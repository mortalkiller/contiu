<?php

namespace App\Support;

use App\Enums\Language;
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
        $dateFormat = Language::resolve(app()->getLocale())->dateFormat();

        return match ($this->type) {
            'receivable' => __('Payment · :label (:client)', [
                'label' => $this->model->label,
                'client' => $this->model->client?->name ?? '—',
            ]),
            'partial' => __('Partial payment · :label (:client) — :amount outstanding', [
                'label' => $this->model->label,
                'client' => $this->model->client?->name ?? '—',
                'amount' => Money::format($this->model->outstanding),
            ]),
            'own_transfer' => __('Own transfer'),
            'expense' => __('Unpaid expense · :supplier · :description', [
                'supplier' => $this->model->supplier->name,
                'description' => $this->model->description,
            ]),
            'template' => __('Recurring expense · :supplier · :description', [
                'supplier' => $this->model->supplier->name,
                'description' => $this->model->description,
            ]),
            'supplier' => __('Create expense · :supplier', ['supplier' => $this->model->name]),
            'payment' => __('Already recorded · Payment on :date · :label (:client)', [
                'date' => $this->model->paid_on->format($dateFormat),
                'label' => ($this->model->is_split ? $this->model->receivableLabels() : $this->model->receivable?->label) ?? '',
                'client' => $this->model->receivable?->client?->name ?? '—',
            ]),
            'paid' => __('Already recorded · Expense paid on :date · :supplier · :description', [
                'date' => ($this->model->paid_on ?? $this->model->spent_on)->format($dateFormat),
                'supplier' => $this->model->supplier->name,
                'description' => $this->model->description,
            ]),
        };
    }
}
