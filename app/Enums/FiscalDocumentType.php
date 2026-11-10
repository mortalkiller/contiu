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
            self::Invoice => 'Fatura',
            self::Receipt => 'Recibo',
            self::InvoiceReceipt => 'Fatura-recibo',
            self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito',
        };
    }
}
