<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a bank line and the records linked to it differ, so no money is left unexplained. Two of
 * them create the missing record (a bank fee expense, another income); the others only say where
 * the rest went.
 */
enum DifferenceExplanation: string implements HasLabel
{
    case OtherAccount = 'other_account';
    case Adjustment = 'adjustment';
    case BankFee = 'bank_fee';
    case OwnMoney = 'own_money';
    case OtherIncome = 'other_income';

    /**
     * What can explain the difference. "Line exceeds" = more money moved in the bank than the
     * records account for.
     *
     * @return array<int, self>
     */
    public static function optionsFor(bool $credit, bool $lineExceeds): array
    {
        return match (true) {
            $credit && $lineExceeds => [self::OwnMoney, self::OtherIncome],
            $credit => [self::BankFee, self::OtherAccount, self::Adjustment],
            $lineExceeds => [self::BankFee, self::OwnMoney],
            default => [self::OtherAccount, self::Adjustment],
        };
    }

    public function getLabel(): string
    {
        return $this->labelFor(credit: true);
    }

    public function labelFor(bool $credit): string
    {
        return match ($this) {
            self::OtherAccount => $credit ? 'O resto entrou noutra conta' : 'O resto foi pago por outra conta',
            self::Adjustment => 'Desconto ou acerto',
            self::BankFee => 'Comissão bancária (cria a despesa)',
            self::OwnMoney => $credit ? 'Dinheiro meu' : 'Dinheiro meu (levantamento)',
            self::OtherIncome => 'Outra receita (cria uma receita avulsa)',
        };
    }

    public function createsRecord(): bool
    {
        return in_array($this, [self::BankFee, self::OtherIncome], true);
    }
}
