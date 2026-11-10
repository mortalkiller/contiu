<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Supplier;

/**
 * One possible meaning of a bank line: pays a receivable, pays an unpaid expense, is a new
 * occurrence of a recurring expense, is a new expense from a known supplier, or is a payment or
 * paid expense already registered by hand (e.g. imported from the old spreadsheet), or money
 * between Pedro's own accounts (own_transfer, no model).
 */
final class BankCandidate
{
    /**
     * @param  'receivable'|'partial'|'expense'|'template'|'supplier'|'payment'|'paid'|'own_transfer'  $type
     */
    public function __construct(
        public readonly string $type,
        public readonly Receivable|Expense|RecurringExpense|Supplier|Payment|null $model,
        public readonly int $nameScore,
        public readonly int $daysApart,
    ) {}

    public function key(): string
    {
        return $this->type.':'.$this->model?->getKey();
    }

    public function label(): string
    {
        return match ($this->type) {
            'receivable' => 'Recebimento · '.$this->model->label.' ('.($this->model->client?->name ?? '—').')',
            'partial' => 'Recebimento parcial · '.$this->model->label.' ('.($this->model->client?->name ?? '—').') — falta '.Money::format($this->model->outstanding),
            'own_transfer' => 'Transferência minha',
            'expense' => 'Despesa por pagar · '.$this->model->supplier->name.' · '.$this->model->description,
            'template' => 'Despesa recorrente · '.$this->model->supplier->name.' · '.$this->model->description,
            'supplier' => 'Criar despesa · '.$this->model->name,
            'payment' => 'Já registado · Recebimento de '.$this->model->paid_on->format('d/m/Y').' · '.($this->model->is_split ? $this->model->receivableLabels() : $this->model->receivable?->label).' ('.($this->model->receivable?->client?->name ?? '—').')',
            'paid' => 'Já registado · Despesa paga em '.($this->model->paid_on ?? $this->model->spent_on)->format('d/m/Y').' · '.$this->model->supplier->name.' · '.$this->model->description,
        };
    }
}
