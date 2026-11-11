<?php

namespace App\Actions;

use App\Enums\Language;
use App\Filament\Pages\MonthClose;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\MonthReview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Bell notifications for open amounts: once when due within a week, once when overdue.
 */
class SendFinanceReminders
{
    use Localizable;

    public const DAYS_BEFORE = 7;

    /** @return int number of reminders sent */
    public function run(): int
    {
        $recipients = CurrentAccount::get()->users;

        if ($recipients->isEmpty()) {
            return 0;
        }

        $recipientsByLocale = $recipients->groupBy(fn ($user): string => $user->preferredLocale());
        $sent = 0;

        $open = Receivable::query()->open()->excludingCancelled()
            ->whereDate('due_on', '<=', today()->addDays(self::DAYS_BEFORE))
            ->with(['payments', 'billable.client'])
            ->get();

        foreach ($open as $receivable) {
            $days = (int) today()->diffInDays($receivable->due_on, false);

            if ($days < 0 && $receivable->overdue_notified_at === null) {
                $this->notify($recipientsByLocale, $receivable, $days);
                $receivable->forceFill(['overdue_notified_at' => now(), 'due_soon_notified_at' => $receivable->due_soon_notified_at ?? now()])->saveQuietly();
                $sent++;
            } elseif ($days >= 0 && $receivable->due_soon_notified_at === null) {
                $this->notify($recipientsByLocale, $receivable, $days);
                $receivable->forceFill(['due_soon_notified_at' => now()])->saveQuietly();
                $sent++;
            }
        }

        return $sent + $this->monthCloseReminder($recipientsByLocale);
    }

    /**
     * On the 1st, a nudge to close the previous month if it still has items left (the daily
     * schedule makes this run once per month).
     */
    private function monthCloseReminder(Collection $recipientsByLocale): int
    {
        if (today()->day !== 1) {
            return 0;
        }

        $month = today()->subMonth();
        $review = new MonthReview($month);
        $pending = $review->pendingCount();

        if ($pending === 0) {
            return 0;
        }

        foreach ($recipientsByLocale as $locale => $recipients) {
            $this->withLocale($locale, function () use ($locale, $recipients, $month, $review, $pending): void {
                Notification::make()
                    ->title(trans_choice('Month close for :month: :count item pending|Month close for :month: :count items pending', $pending, [
                        'month' => Str::ucfirst($month->copy()->locale($locale)->translatedFormat('F Y')),
                    ]))
                    ->body(__(':receivables pending receipt · :expenses', [
                        'receivables' => $review->pendingReceivables(),
                        'expenses' => trans_choice(':count unpaid expense|:count unpaid expenses', $review->pendingExpenses()),
                    ]))
                    ->warning()
                    ->icon(Phosphor::CalendarCheck)
                    ->actions([
                        Action::make('open')
                            ->label(__('Open month close'))
                            ->url(MonthClose::getUrl(['month' => $month->format('Y-m')], panel: 'admin', tenant: CurrentAccount::get())),
                    ])
                    ->sendToDatabase($recipients);
            });
        }

        return 1;
    }

    private function notify(Collection $recipientsByLocale, Receivable $receivable, int $days): void
    {
        foreach ($recipientsByLocale as $locale => $recipients) {
            $this->withLocale($locale, function () use ($locale, $recipients, $receivable, $days): void {
                $label = $receivable->label;
                $title = match (true) {
                    $days < 0 => __('Overdue: :label', ['label' => $label]),
                    $days === 0 => __('Due today: :label', ['label' => $label]),
                    $days === 1 => __('Due tomorrow: :label', ['label' => $label]),
                    default => __('Due in :days days: :label', ['days' => $days, 'label' => $label]),
                };

                Notification::make()
                    ->title($title)
                    ->body(__(':client — :amount outstanding (due :date)', [
                        'client' => $receivable->client?->name ?? '—',
                        'amount' => Money::format($receivable->outstanding, $locale),
                        'date' => $receivable->due_on->format(Language::resolve($locale)->dateFormat()),
                    ]))
                    ->status($days < 0 ? 'danger' : 'warning')
                    ->icon($days < 0 ? Phosphor::WarningCircle : Phosphor::HourglassMedium)
                    ->actions([
                        Action::make('open')
                            ->label(__('View receivables'))
                            ->url(ReceivableResource::getUrl(panel: 'admin', tenant: CurrentAccount::get())),
                    ])
                    ->sendToDatabase($recipients);
            });
        }
    }
}
