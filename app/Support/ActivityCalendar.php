<?php

namespace App\Support;

use App\Enums\CalendarEntryType;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use Carbon\CarbonImmutable;

/**
 * Everything in the account that has a date, for the "Calendário": tax obligations (from
 * TaxObligations), payments, open receivables, expenses and the retainer periods and recurring
 * expenses not generated yet (from ScheduleProjector). Read-only; no rule is computed here.
 */
final class ActivityCalendar
{
    public function __construct(private readonly FinanceSettings $settings, private readonly CarbonImmutable $today) {}

    /**
     * The entries dated between the two days (inclusive), by date and then by type.
     *
     * @param  list<CalendarEntryType>  $types
     * @return list<CalendarEntry>
     */
    public function entries(CarbonImmutable $from, CarbonImmutable $to, array $types): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        $entries = [];

        foreach ($types as $type) {
            array_push($entries, ...match ($type) {
                CalendarEntryType::Obligation => $this->obligations($from, $to),
                CalendarEntryType::Payment => $this->payments($from, $to),
                CalendarEntryType::Receivable => $this->receivables($from, $to),
                CalendarEntryType::Expense => $this->expenses($from, $to),
                CalendarEntryType::ProjectedRetainer => $this->projectedRetainers($from, $to),
                CalendarEntryType::ProjectedRecurringExpense => $this->projectedRecurringExpenses($from, $to),
            });
        }

        $order = array_flip(array_map(fn (CalendarEntryType $type) => $type->value, CalendarEntryType::cases()));
        usort($entries, fn (CalendarEntry $a, CalendarEntry $b) => [$a->date, $order[$a->type->value]] <=> [$b->date, $order[$b->type->value]]);

        return $entries;
    }

    /** @return list<CalendarEntry> */
    private function obligations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_map(fn (TaxObligation $obligation) => new CalendarEntry(
            id: $obligation->key,
            type: CalendarEntryType::Obligation,
            date: $obligation->dueOn,
            title: implode(' · ', array_filter([
                $obligation->title,
                $obligation->period(),
                $obligation->amount === null ? null : Money::format($obligation->amount),
            ])),
            amount: $obligation->amount,
            status: $obligation->status,
            url: $obligation->url,
            detail: $obligation->detail,
        ), (new TaxObligations($this->settings, $this->today))->between($from, $to));
    }

    /** @return list<CalendarEntry> */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Payment::query()
            ->with(['advanceClient', 'receivable.billable.client'])
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Payment $payment) => new CalendarEntry(
                id: 'payment:'.$payment->getKey(),
                type: CalendarEntryType::Payment,
                date: CarbonImmutable::parse($payment->paid_on),
                title: __($payment->isAdvance() ? 'Advance received · :name · :amount' : 'Received · :name · :amount', [
                    'name' => $payment->client?->name ?? $payment->receivable?->description ?? '',
                    'amount' => Money::format($payment->amount),
                ]),
                amount: $payment->amount,
                status: 'paid',
                url: ($payment->is_split ? null : $payment->receivable?->billableUrl()) ?? PaymentResource::getUrl(),
            ))
            ->all();
    }

    /** @return list<CalendarEntry> */
    private function receivables(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Receivable::query()
            ->open()
            ->excludingCancelled()
            ->with(['payments', 'billable.client'])
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Receivable $receivable) => new CalendarEntry(
                id: 'receivable:'.$receivable->getKey(),
                type: CalendarEntryType::Receivable,
                date: CarbonImmutable::parse($receivable->due_on),
                title: __('To receive · :label · :amount', [
                    'label' => $receivable->label,
                    'amount' => Money::format($receivable->outstanding),
                ]),
                amount: $receivable->outstanding,
                status: $receivable->status->value,
                url: $receivable->billableUrl() ?? ReceivableResource::getUrl(),
            ))
            ->all();
    }

    /** @return list<CalendarEntry> */
    private function expenses(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Expense::query()
            ->with('supplier')
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Expense $expense) => new CalendarEntry(
                id: 'expense:'.$expense->getKey(),
                type: CalendarEntryType::Expense,
                date: CarbonImmutable::parse($expense->spent_on),
                title: __($expense->is_paid ? ':name · :amount' : ':name · :amount · unpaid', [
                    'name' => $expense->supplier?->name ?? $expense->description,
                    'amount' => Money::format($expense->total),
                ]),
                amount: $expense->total,
                status: $expense->is_paid ? 'paid' : 'unpaid',
                url: ExpenseResource::getUrl('edit', ['record' => $expense]),
            ))
            ->all();
    }

    /** @return list<CalendarEntry> */
    private function projectedRetainers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $projector = new ScheduleProjector;
        $entries = [];

        foreach (Retainer::query()->where('is_active', true)->with('client')->get() as $retainer) {
            foreach ($this->within($projector->retainerDueDates($retainer, $to), $from, $to) as $date) {
                $entries[] = new CalendarEntry(
                    id: 'retainer:'.$retainer->getKey().':'.$date->toDateString(),
                    type: CalendarEntryType::ProjectedRetainer,
                    date: $date,
                    title: __('Retainer · :name (projected) · :amount', [
                        'name' => $retainer->client?->name ?? $retainer->title,
                        'amount' => Money::format($retainer->total),
                    ]),
                    amount: $retainer->total,
                    status: 'projected',
                    url: RetainerResource::getUrl('view', ['record' => $retainer]),
                );
            }
        }

        return $entries;
    }

    /** @return list<CalendarEntry> */
    private function projectedRecurringExpenses(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $projector = new ScheduleProjector;
        $entries = [];

        foreach (RecurringExpense::query()->where('is_active', true)->get() as $template) {
            foreach ($this->within($projector->recurringExpenseDates($template, $to), $from, $to) as $date) {
                $entries[] = new CalendarEntry(
                    id: 'recurring-expense:'.$template->getKey().':'.$date->toDateString(),
                    type: CalendarEntryType::ProjectedRecurringExpense,
                    date: $date,
                    title: __(':description (projected) · :amount', [
                        'description' => $template->description,
                        'amount' => Money::format($template->total),
                    ]),
                    amount: $template->total,
                    status: 'projected',
                    url: RecurringExpenseResource::getUrl(),
                );
            }
        }

        return $entries;
    }

    /**
     * @param  array<int, CarbonImmutable>  $dates
     * @return list<CarbonImmutable>
     */
    private function within(array $dates, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(array_filter($dates, fn (CarbonImmutable $date) => $date->startOfDay()->betweenIncluded($from, $to)));
    }
}
