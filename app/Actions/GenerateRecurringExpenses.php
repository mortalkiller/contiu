<?php

namespace App\Actions;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\Money;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Creates the expense for each occurrence of a recurring expense up to today, as "por pagar"
 * so it can be confirmed (and its invoice number added) once it is really charged.
 *
 * Like retainer periods, it continues after the latest expense of the template and never
 * back-fills older gaps.
 */
class GenerateRecurringExpenses
{
    /** @return int number of expenses created */
    public function forAll(?CarbonInterface $until = null): int
    {
        return RecurringExpense::query()->where('is_active', true)->get()
            ->sum(fn (RecurringExpense $template) => $this->forTemplate($template, $until));
    }

    /** @return int number of expenses created */
    public function forTemplate(RecurringExpense $template, ?CarbonInterface $until = null): int
    {
        if (! $template->is_active) {
            return 0;
        }

        $until ??= today();
        $months = $template->frequency->months();
        $created = 0;

        for ($on = $this->firstDate($template); $on->lte($until); $on = $this->onDay($on->copy()->startOfMonth()->addMonths($months), $template->day)) {
            if ($template->ends_on !== null && $on->gt($template->ends_on)) {
                break;
            }

            $expense = $template->expenses()->create([
                'supplier_id' => $template->supplier_id,
                'category_id' => $template->category_id,
                'kind' => $template->kind,
                'project_id' => $template->project_id,
                'retainer_id' => $template->retainer_id,
                'description' => $template->description,
                'spent_on' => $on,
                'net_amount' => $template->net_amount,
                'vat_rate' => $template->vat_rate,
                'is_paid' => false,
                'paid_on' => null,
                'method' => $template->method,
            ]);

            $this->notify($expense);
            $created++;
        }

        return $created;
    }

    private function firstDate(RecurringExpense $template): Carbon
    {
        $months = $template->frequency->months();
        $latest = $template->expenses()->max('spent_on');

        if ($latest !== null) {
            return $this->onDay(Carbon::parse($latest)->startOfMonth()->addMonths($months), $template->day);
        }

        $first = $this->onDay($template->starts_on->copy()->startOfMonth(), $template->day);

        return $first->lt($template->starts_on)
            ? $this->onDay($first->copy()->startOfMonth()->addMonths($months), $template->day)
            : $first;
    }

    private function onDay(Carbon $month, int $day): Carbon
    {
        return $month->day(min($day, $month->daysInMonth));
    }

    private function notify(Expense $expense): void
    {
        $recipients = User::all();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Despesa por pagar: '.$expense->description)
            ->body($expense->supplier->name.' — '.Money::format($expense->total).' ('.$expense->spent_on->format('d/m/Y').')')
            ->warning()
            ->icon(Phosphor::Receipt)
            ->actions([
                Action::make('open')
                    ->label('Ver despesa')
                    ->url(ExpenseResource::getUrl('edit', ['record' => $expense], panel: 'admin', tenant: CurrentAccount::get())),
            ])
            ->sendToDatabase($recipients);
    }
}
