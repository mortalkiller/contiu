<?php

namespace App\Support\Invoicing;

use App\Enums\FiscalDocumentType;

/** What a provider can do. Screens only offer what the connected provider supports. */
final readonly class InvoicingCapabilities
{
    /** @param list<FiscalDocumentType> $documentTypes */
    public function __construct(
        public array $documentTypes,
        public bool $drafts = true,
        public bool $reportsToTaxAuthority = true,
    ) {}

    public function supports(FiscalDocumentType $type): bool
    {
        return in_array($type, $this->documentTypes, true);
    }
}
