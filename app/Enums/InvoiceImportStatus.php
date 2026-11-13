<?php

namespace App\Enums;

/** Where an invoice dropped on "Importar faturas" stands, from the file to the expense. */
enum InvoiceImportStatus: string
{
    case Queued = 'queued';
    case Reading = 'reading';
    case Read = 'read';
    case Unreadable = 'unreadable';
    case Confirmed = 'confirmed';
    case Sale = 'sale';
    case Duplicate = 'duplicate';
    case Dismissed = 'dismissed';

    /** Still being read: nothing to show yet. */
    public function isReading(): bool
    {
        return in_array($this, [self::Queued, self::Reading], true);
    }

    /** Nothing left for the person to do with it. */
    public function isHandled(): bool
    {
        return in_array($this, [self::Confirmed, self::Sale, self::Duplicate, self::Dismissed], true);
    }
}
