<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * When a bank line and its records differ, the difference is normally money that went through
 * another account, and the company account balance (CompanyAccount) shows it. Only two cases need
 * a record of their own, and these explanations create it.
 */
enum DifferenceExplanation: string implements HasLabel
{
    case BankFee = 'bank_fee';
    case OtherIncome = 'other_income';

    /**
     * What can be recorded for the difference. "Line exceeds" = more money moved in the bank than
     * the records account for.
     *
     * @return array<int, self>
     */
    public static function optionsFor(bool $credit, bool $lineExceeds): array
    {
        return match (true) {
            $credit && $lineExceeds => [self::OtherIncome],
            $credit, $lineExceeds => [self::BankFee],
            default => [],
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::BankFee => 'Comissão bancária (cria a despesa)',
            self::OtherIncome => 'Outra receita (cria uma receita avulsa)',
        };
    }

    public function labelFor(bool $credit): string
    {
        return $this->getLabel();
    }
}
