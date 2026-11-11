<?php

namespace App\Support;

use App\Models\InvoiceXpressConfig;

final class InvoiceXpressFiscalProvider implements FiscalProviderCapabilities
{
    public function __construct(
        private readonly int $accountId,
        private readonly string $subdomain,
    ) {}

    public function provider(): string
    {
        return 'invoicexpress';
    }

    public function providerAccount(): string
    {
        return $this->subdomain;
    }

    /** @return array{itemIds: list<int>, taxName: string, sequenceId: int, draftOnly?: true}|null */
    public function options(bool $forNewIssue = true): ?array
    {
        if (CurrentAccount::get()?->getKey() !== $this->accountId
            || InvoiceXpressConfig::current()?->subdomain !== $this->subdomain) {
            return null;
        }

        return InvoiceXpressIssue::options($forNewIssue);
    }

    /** @return list<string> */
    public function draftDocumentTypes(): array
    {
        if (! ($this->options()['draftOnly'] ?? false)) {
            return [];
        }

        return array_keys(InvoiceXpressSandboxDrafts::DocumentTypes + InvoiceXpressNoteTerms::Types);
    }

    public function canCreateRemoteDraft(string $documentType): bool
    {
        return in_array($documentType, $this->draftDocumentTypes(), true);
    }

    public function canFinalize(): bool
    {
        return false;
    }
}
