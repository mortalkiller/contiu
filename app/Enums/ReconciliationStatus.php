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
            self::Reconciled => __('Reconciled'),
            self::NotReconciled => __('Unreconciled'),
        };
    }

    public function color(): string
    {
        return $this === self::Reconciled ? 'success' : 'warning';
    }
}
