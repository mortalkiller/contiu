<?php

namespace App\Support;

interface FiscalProviderCapabilities
{
    public function provider(): string;

    public function providerAccount(): string;

    /** @return list<string> */
    public function draftDocumentTypes(): array;

    public function canCreateRemoteDraft(string $documentType): bool;

    public function canFinalize(): bool;
}
