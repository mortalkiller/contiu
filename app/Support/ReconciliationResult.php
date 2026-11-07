<?php

namespace App\Support;

use App\Enums\ReconciliationStatus;

/** One record's reconciliation; `missing` = cents not matched by the bank yet. */
final class ReconciliationResult
{
    public function __construct(
        public readonly ReconciliationStatus $status,
        public readonly int $amount = 0,
        public readonly int $missing = 0,
        public readonly ?string $note = null,
    ) {}

    public function isReconciled(): bool
    {
        return $this->status === ReconciliationStatus::Reconciled;
    }

    public function label(): string
    {
        return $this->status->label();
    }

    /** "Faltam 350,00 € de 400,00 €", or why it can't be reconciled yet. */
    public function detail(): ?string
    {
        if ($this->note !== null) {
            return $this->note;
        }

        return $this->status === ReconciliationStatus::NotReconciled && $this->missing > 0 && $this->missing < $this->amount
            ? 'Faltam '.Money::format($this->missing).' de '.Money::format($this->amount)
            : null;
    }
}
