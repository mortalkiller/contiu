<?php

namespace App\Support\Invoicing;

use App\Enums\FiscalDocumentType;

/**
 * What the provider is set up with: the series documents are numbered in and the taxes it knows.
 * Both are created at the provider, which reports the series to the tax authority; the app only
 * reads them.
 */
final readonly class InvoicingCatalog
{
    /**
     * @param  list<array{id: string, name: string, default: bool, next: array<string, int>, ids: array<string, string>}>  $series  `next` and `ids` are keyed by document type
     * @param  list<array{name: string, basisPoints: int, region: ?string}>  $taxes
     */
    public function __construct(public array $series, public array $taxes) {}

    /** @return array{id: string, name: string, default: bool, next: array<string, int>, ids: array<string, string>}|null */
    public function series(?string $id): ?array
    {
        return collect($this->series)->first(fn (array $series): bool => $id === null ? $series['default'] : $series['id'] === $id)
            ?? ($id === null ? ($this->series[0] ?? null) : null);
    }

    /** "FT 2026/15": the number the next document of this type would get in the series. */
    public function nextNumber(FiscalDocumentType $type, ?string $seriesId = null): ?string
    {
        $series = $this->series($seriesId);
        $next = $series['next'][$type->value] ?? null;

        return $series === null || $next === null ? null : "{$type->prefix()} {$series['name']}/{$next}";
    }

    /** The provider's name for a VAT rate, which is how its API takes the tax of a line. */
    public function taxName(int $basisPoints, ?string $region = null): ?string
    {
        return collect($this->taxes)
            ->first(fn (array $tax): bool => $tax['basisPoints'] === $basisPoints && ($region === null || $tax['region'] === null || $tax['region'] === $region))['name'] ?? null;
    }
}
