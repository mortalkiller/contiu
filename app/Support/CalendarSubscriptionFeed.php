<?php

namespace App\Support;

use App\Models\Receivable;
use Carbon\CarbonImmutable;

final class CalendarSubscriptionFeed
{
    public function __construct(private readonly FinanceSettings $settings) {}

    public function render(bool $includeReceivables): string
    {
        $today = CarbonImmutable::today();
        $from = $today->startOfMonth()->subMonths(TaxObligations::OVERDUE_MONTHS);
        $to = $today->startOfMonth()->addMonths(12)->endOfMonth();
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');

        $events = [];

        foreach ((new TaxObligations($this->settings, $today))->between($from, $to) as $obligation) {
            array_push($events, ...$this->event('tax:'.$obligation->key, $obligation->dueOn, $obligation->title, $stamp));
        }

        if ($includeReceivables) {
            $receivables = Receivable::query()
                ->open()
                ->excludingCancelled()
                ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
                ->orderBy('due_on')
                ->orderBy('id')
                ->get(['id', 'due_on']);

            foreach ($receivables as $receivable) {
                array_push($events, ...$this->event(
                    'receivable:'.$receivable->getKey(),
                    CarbonImmutable::instance($receivable->due_on),
                    __('Receivable due'),
                    $stamp,
                ));
            }
        }

        // RFC 5545 requires at least one component, even when there are no events.
        $components = $events === [] ? [
            'BEGIN:VTIMEZONE',
            'TZID:UTC',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0000',
            'TZOFFSETTO:+0000',
            'END:STANDARD',
            'END:VTIMEZONE',
        ] : $events;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Contiu//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:Contiu',
            ...$components,
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private function event(string $id, CarbonImmutable $date, string $title, string $stamp): array
    {
        $uid = hash_hmac('sha256', CurrentAccount::id().':'.$id, config('app.key'));

        return [
            'BEGIN:VEVENT',
            'UID:'.$uid.'@contiu',
            'DTSTAMP:'.$stamp,
            'DTSTART;VALUE=DATE:'.$date->format('Ymd'),
            'DTEND;VALUE=DATE:'.$date->addDay()->format('Ymd'),
            'SUMMARY:'.strtr($title, ['\\' => '\\\\', ',' => '\,', ';' => '\;', "\r\n" => '\n', "\r" => '\n', "\n" => '\n']),
            'END:VEVENT',
        ];
    }

    private function fold(string $line): string
    {
        $parts = [];

        while (strlen($line) > 75) {
            $part = mb_strcut($line, 0, 75, 'UTF-8');
            $parts[] = $part;
            $line = ' '.substr($line, strlen($part));
        }

        $parts[] = $line;

        return implode("\r\n", $parts);
    }
}
