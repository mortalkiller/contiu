<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a row of the "Calendário" is; the cases are in the order the calendar lists a day's rows.
 */
enum CalendarEntryType: string implements HasLabel
{
    case Obligation = 'obligation';
    case Payment = 'payment';
    case Receivable = 'receivable';
    case Expense = 'expense';
    case ProjectedRetainer = 'projected-retainer';
    case ProjectedRecurringExpense = 'projected-recurring-expense';

    public function getLabel(): string
    {
        return match ($this) {
            self::Obligation => __('Tax obligations'),
            self::Payment => __('Payments received'),
            self::Receivable => __('To receive'),
            self::Expense => __('Expenses'),
            self::ProjectedRetainer => __('Projected retainers'),
            self::ProjectedRecurringExpense => __('Projected recurring expenses'),
        };
    }

    public function isProjected(): bool
    {
        return in_array($this, [self::ProjectedRetainer, self::ProjectedRecurringExpense], true);
    }
}
