<?php

namespace App\Support\Invoicing;

/** One page of a provider's document list. */
final readonly class RemotePage
{
    /** @param list<RemoteDocument> $documents */
    public function __construct(public array $documents, public bool $hasMore) {}
}
