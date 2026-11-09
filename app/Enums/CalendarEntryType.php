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
            self::Obligation => 'Obrigações fiscais',
            self::Payment => 'Recebimentos',
            self::Receivable => 'A receber',
            self::Expense => 'Despesas',
            self::ProjectedRetainer => 'Avenças previstas',
            self::ProjectedRecurringExpense => 'Recorrentes previstas',
        };
    }

    public function isProjected(): bool
    {
        return in_array($this, [self::ProjectedRetainer, self::ProjectedRecurringExpense], true);
    }
}
