<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FiscalDocumentType: string implements HasLabel
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case InvoiceReceipt = 'invoice_receipt';
    case SimplifiedInvoice = 'simplified_invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Quote = 'quote';
    case ProForma = 'pro_forma';
    case TransportGuide = 'transport_guide';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::Receipt => __('Receipt'),
            self::InvoiceReceipt => __('Invoice receipt'),
            self::SimplifiedInvoice => __('Simplified invoice'),
            self::CreditNote => __('Credit note'),
            self::DebitNote => __('Debit note'),
            self::Quote => __('Quote'),
            self::ProForma => __('Pro forma invoice'),
            self::TransportGuide => __('Transport guide'),
        };
    }

    /** The letters Portuguese invoicing software prints before the series and number. */
    public function prefix(): string
    {
        return match ($this) {
            self::Invoice => 'FT',
            self::Receipt => 'RC',
            self::InvoiceReceipt => 'FR',
            self::SimplifiedInvoice => 'FS',
            self::CreditNote => 'NC',
            self::DebitNote => 'ND',
            self::Quote => 'OR',
            self::ProForma => 'PF',
            self::TransportGuide => 'GT',
        };
    }

    /**
     * The documents that charge a client for a sale.
     *
     * @return list<self>
     */
    public static function invoices(): array
    {
        return [self::Invoice, self::InvoiceReceipt, self::SimplifiedInvoice];
    }

    /**
     * The documents that add to what was billed, or take from it: a quote, a pro forma, a transport
     * guide and a receipt never do.
     *
     * @return list<self>
     */
    public static function billing(): array
    {
        return [...self::invoices(), self::CreditNote, self::DebitNote];
    }

    public function isInvoice(): bool
    {
        return in_array($this, self::invoices(), true);
    }

    public function countsAsBilling(): bool
    {
        return in_array($this, self::billing(), true);
    }

    /** Paid at the moment it is issued, so it carries how and when the money came in. */
    public function settlesOnIssue(): bool
    {
        return in_array($this, [self::InvoiceReceipt, self::SimplifiedInvoice], true);
    }

    /** Has a second date: when it is due, or until when the offer stands. The others are settled or owe nothing. */
    public function hasTerm(): bool
    {
        return in_array($this, [self::Invoice, self::DebitNote, self::Quote, self::ProForma], true);
    }

    /** Only exists on top of an invoice already issued. */
    public function requiresOriginal(): bool
    {
        return in_array($this, [self::CreditNote, self::DebitNote, self::Receipt], true);
    }

    /** Lists what is charged line by line; a receipt only says which invoices were paid. */
    public function hasLines(): bool
    {
        return $this !== self::Receipt;
    }

    /**
     * What "New document" offers. Transport guides are only for accounts that sell or move goods.
     *
     * @return list<self>
     */
    public static function creatable(bool $sellsGoods): array
    {
        return [
            self::Invoice, self::InvoiceReceipt, self::SimplifiedInvoice, self::Quote, self::ProForma,
            ...($sellsGoods ? [self::TransportGuide] : []),
        ];
    }
}
