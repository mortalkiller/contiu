<?php

namespace App\Enums;

/** Whether a payment or a paid expense is already matched by the bank statement. */
enum ReconciliationStatus: string
{
    case Reconciled = 'reconciled';
    case NotReconciled = 'not_reconciled';

    public function label(): string
    {
        return match ($this) {
            self::Reconciled => 'Reconciliado',
            self::NotReconciled => 'Por reconciliar',
        };
    }

    public function color(): string
    {
        return $this === self::Reconciled ? 'success' : 'warning';
    }
}
