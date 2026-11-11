<?php

namespace App\Support;

use App\Enums\Language;
use App\Models\Expense;
use App\Models\Payment;

/** A payment or expense (or part of it, in cents) that a "Transferência minha" covers. */
final class TransferCover
{
    public function __construct(
        public readonly Payment|Expense $record,
        public readonly int $amount,
        public readonly int $total,
        public readonly bool $deducted = false,
    ) {}

    /** "Recebimento PRESSIU OC-7 · Pagamento único · 03/11/2026 · 400,00 €" */
    public function label(): string
    {
        $value = $this->amount < $this->total
            ? __(':amount of :total (partial)', ['amount' => Money::format($this->amount), 'total' => Money::format($this->total)])
            : Money::format($this->amount);

        $dateFormat = Language::resolve(app()->getLocale())->dateFormat();

        if ($this->record instanceof Payment) {
            return __('Payment :label · :date · :amount', [
                'label' => ($this->record->is_split ? $this->record->receivableLabels() : $this->record->receivable?->label) ?? '',
                'date' => $this->record->paid_on->format($dateFormat),
                'amount' => $value,
            ]);
        }

        return __($this->deducted ? 'Expense :description · :date · :amount (deducted)' : 'Expense :description · :date · :amount (refunded)', [
            'description' => $this->record->description,
            'date' => ($this->record->paid_on ?? $this->record->spent_on)->format($dateFormat),
            'amount' => $value,
        ]);
    }
}
