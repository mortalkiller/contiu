<?php

namespace App\Support;

use App\Enums\CalendarEntryType;
use Carbon\CarbonImmutable;

/**
 * One dated row of the "Calendário", independent of the calendar plugin.
 *
 * `status` is the obligation's (due / overdue / done), the receivable's ReceivableStatus value,
 * paid / unpaid for an expense, paid for a payment and projected for what is still to be generated.
 */
final readonly class CalendarEntry
{
    public function __construct(
        public string $id,
        public CalendarEntryType $type,
        public CarbonImmutable $date,
        public string $title,
        public ?int $amount,
        public string $status,
        public ?string $url,
        public ?string $detail = null,
    ) {}
}
