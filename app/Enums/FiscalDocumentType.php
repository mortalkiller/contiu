<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FiscalDocumentType: string implements HasLabel
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case InvoiceReceipt = 'invoice_receipt';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::Receipt => __('Receipt'),
            self::InvoiceReceipt => __('Invoice receipt'),
            self::CreditNote => __('Credit note'),
            self::DebitNote => __('Debit note'),
        };
    }
}
