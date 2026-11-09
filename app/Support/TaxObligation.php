<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * One line of the "Este mês" card: something to pay or deliver (or a note about the VAT regime).
 *
 * kind: ss | ss-declaration | vat-reverse | recapitulativa | irs-modelo3 (things to do),
 * vat-threshold | foreign-vat-warning | incomplete-settings (notes, status info).
 * status: due | overdue | done | exempt | info. Amounts in cents; null when there is nothing to pay.
 */
final readonly class TaxObligation
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $title,
        public ?int $amount,
        public ?CarbonImmutable $dueOn,
        public ?CarbonImmutable $opensOn,
        public string $detail,
        public ?string $url,
        public string $status,
    ) {}

    /** What the obligation is about: "outubro" for a monthly one, "2026" for the IRS return, else "". */
    public function period(): string
    {
        return match ($this->kind) {
            'irs-modelo3' => substr($this->key, -4),
            'ss', 'vat-reverse', 'recapitulativa' => mb_strtolower(Period::MONTHS[(int) substr($this->key, -2) - 1]),
            default => '',
        };
    }

    /** Days until the deadline (0 on the day, negative once past); null without one. */
    public function daysLeft(CarbonImmutable $today): ?int
    {
        return $this->dueOn === null ? null : (int) $today->startOfDay()->diffInDays($this->dueOn->startOfDay(), false);
    }
}
