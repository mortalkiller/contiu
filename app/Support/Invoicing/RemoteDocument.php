<?php

namespace App\Support\Invoicing;

use App\Enums\FiscalDocumentType;
use Carbon\CarbonImmutable;

/**
 * A fiscal document as a billing provider holds it, in the app's own terms: cents, basis points
 * and the app's document types. Drivers build it; nothing else knows the provider's format.
 */
final readonly class RemoteDocument
{
    /** @param list<RemoteLine> $lines */
    public function __construct(
        public string $remoteType,
        public string $remoteId,
        public FiscalDocumentType $type,
        public RemoteDocumentState $state,
        public CarbonImmutable $date,
        public int $net,
        public int $vat,
        public int $total,
        public ?string $number = null,
        public ?string $series = null,
        public ?string $atcud = null,
        public ?CarbonImmutable $dueDate = null,
        public int $withholding = 0,
        public ?string $partyName = null,
        public ?string $partyTaxId = null,
        public array $lines = [],
        public ?string $url = null,
        public ?string $ownerRemoteId = null,
    ) {}

    public function isIssued(): bool
    {
        return $this->state === RemoteDocumentState::Issued;
    }

    /** Whether the provider's amounts are the ones the app worked out. */
    public function matches(int $net, int $vat, int $total): bool
    {
        return [$this->net, $this->vat, $this->total] === [$net, $vat, $total];
    }

    /**
     * The short copy kept beside the local record, enough to show and compare without asking again.
     *
     * @return array<string, int|string|null>
     */
    public function summary(): array
    {
        return [
            'type' => $this->type->value,
            'state' => $this->state->value,
            'number' => $this->number,
            'date' => $this->date->toDateString(),
            'net' => $this->net,
            'vat' => $this->vat,
            'total' => $this->total,
            'party_name' => $this->partyName,
            'party_tax_id' => $this->partyTaxId,
            'url' => $this->url,
        ];
    }

    /** Changes whenever something that matters at the provider does. */
    public function hash(): string
    {
        return hash('sha256', json_encode([...$this->summary(), 'atcud' => $this->atcud, 'withholding' => $this->withholding], JSON_THROW_ON_ERROR));
    }
}
