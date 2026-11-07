<?php

namespace App\Actions;

use App\Filament\Pages\MonthClose;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use App\Models\User;
use App\Support\Money;
use App\Support\MonthReview;
use App\Support\Period;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Bell notifications for open amounts: once when due within a week, once when overdue.
 */
class SendFinanceReminders
{
    public const DAYS_BEFORE = 7;

    /** @return int number of reminders sent */
    public function run(): int
    {
        $recipients = User::all();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;

        $open = Receivable::query()->open()->excludingCancelled()
            ->whereDate('due_on', '<=', today()->addDays(self::DAYS_BEFORE))
            ->with(['payments', 'billable.client'])
            ->get();

        foreach ($open as $receivable) {
            $days = (int) today()->diffInDays($receivable->due_on, false);

            if ($days < 0 && $receivable->overdue_notified_at === null) {
                $this->notify($recipients, $receivable, 'Vencido: '.$receivable->label, 'danger', Phosphor::WarningCircle);
                $receivable->forceFill(['overdue_notified_at' => now(), 'due_soon_notified_at' => $receivable->due_soon_notified_at ?? now()])->saveQuietly();
                $sent++;
            } elseif ($days >= 0 && $receivable->due_soon_notified_at === null) {
                $when = match ($days) {
                    0 => 'Vence hoje',
                    1 => 'Vence amanhã',
                    default => "Vence em {$days} dias",
                };

                $this->notify($recipients, $receivable, $when.': '.$receivable->label, 'warning', Phosphor::HourglassMedium);
                $receivable->forceFill(['due_soon_notified_at' => now()])->saveQuietly();
                $sent++;
            }
        }

        return $sent + $this->monthCloseReminder($recipients);
    }

    /**
     * On the 1st, a nudge to close the previous month if it still has items left (the daily
     * schedule makes this run once per month).
     */
    private function monthCloseReminder($recipients): int
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

        $label = Period::of($month->year, $month->month)->label();

        Notification::make()
            ->title('Fecho de '.$label.': falta'.($pending === 1 ? ' 1 item' : 'm '.$pending.' itens'))
            ->body($review->pendingReceivables().' por receber · '.$review->pendingExpenses().' despesas por pagar')
            ->warning()
            ->icon(Phosphor::CalendarCheck)
            ->actions([
                Action::make('open')
                    ->label('Abrir fecho do mês')
                    ->url(MonthClose::getUrl(['month' => $month->format('Y-m')], panel: 'admin')),
            ])
            ->sendToDatabase($recipients);

        return 1;
    }

    private function notify($recipients, Receivable $receivable, string $title, string $status, Phosphor $icon): void
    {
        Notification::make()
            ->title($title)
            ->body(($receivable->client?->name ?? '—').' — falta receber '.Money::format($receivable->outstanding).' (vencimento '.$receivable->due_on->format('d/m/Y').')')
            ->status($status)
            ->icon($icon)
            ->actions([
                Action::make('open')
                    ->label('Ver a receber')
                    ->url(ReceivableResource::getUrl(panel: 'admin')),
            ])
            ->sendToDatabase($recipients);
    }
}
