<?php

namespace App\Support\Invoicing;

/** The provider's ids a document depends on: its own draft, its client and the invoice it corrects. */
final readonly class RemoteReferences
{
    public function __construct(
        public ?string $document = null,
        public ?string $client = null,
        public ?string $owner = null,
    ) {}
}
