<?php

namespace App\Support;

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
            ? Money::format($this->amount).' de '.Money::format($this->total).' (em parte)'
            : Money::format($this->amount);

        if ($this->record instanceof Payment) {
            return 'Recebimento '.$this->record->receivable?->label.' · '.$this->record->paid_on->format('d/m/Y').' · '.$value;
        }

        return 'Despesa '.$this->record->description.' · '.($this->record->paid_on ?? $this->record->spent_on)->format('d/m/Y').' · '.$value
            .($this->deducted ? ' (descontada)' : ' (devolvida)');
    }
}
