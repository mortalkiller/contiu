<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Receivable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Extrato do cliente: every charge (receivable, dated by its due date) and every payment, in date
 * order with a running balance. A positive balance is what the client still owes.
 *
 * Future charges are listed and count towards the running balance (they are already agreed), but are
 * flagged `is_future` so the UI can grey them; `overdueBalance()` is the part that is actually late.
 *
 * @phpstan-type Entry array{key: string, date: CarbonInterface, description: string, debit: float, credit: float, balance: float, is_future: bool, kind: string}
 */
final class ClientStatement
{
    /** @var Collection<int, Entry>|null */
    private ?Collection $entries = null;

    private float $opening = 0.0;

    public function __construct(
        public readonly Client $client,
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $to = null,
    ) {}

    /** @return Collection<int, Entry> */
    public function entries(): Collection
    {
        return $this->entries ??= $this->build();
    }

    public function charged(): float
    {
        return round($this->entries()->sum('debit'), 2);
    }

    public function received(): float
    {
        return round($this->entries()->sum('credit'), 2);
    }

    public function balance(): float
    {
        return round((float) ($this->entries()->last()['balance'] ?? $this->opening), 2);
    }

    public function overdueBalance(): float
    {
        return round($this->receivables()
            ->filter(fn (Receivable $receivable) => $receivable->due_on->isBefore(today()))
            ->filter(fn (Receivable $receivable) => $this->to === null || $receivable->due_on->lte($this->to))
            ->sum(fn (Receivable $receivable) => $receivable->outstanding), 2);
    }

    /** @return Collection<int, Receivable> */
    private function receivables(): Collection
    {
        return Receivable::query()
            ->forClient($this->client->getKey())
            ->excludingCancelled()
            ->with(['billable', 'payments'])
            ->get();
    }

    /** @return Collection<int, Entry> */
    private function build(): Collection
    {
        $receivables = $this->receivables();

        $charges = $receivables->map(fn (Receivable $receivable) => [
            'key' => 'r'.$receivable->getKey(),
            'date' => $receivable->due_on,
            'description' => $receivable->label,
            'debit' => round((float) $receivable->amount, 2),
            'credit' => 0.0,
            'is_future' => $receivable->due_on->isAfter(today()),
            'kind' => 'charge',
            'order' => 0,
        ]);

        $payments = $receivables->flatMap(fn (Receivable $receivable) => $receivable->payments)->map(fn (Payment $payment) => [
            'key' => 'p'.$payment->getKey(),
            'date' => $payment->paid_on,
            'description' => collect(['Recebimento', $payment->method?->getLabel(), $payment->invoice_number])->filter()->implode(' · '),
            'debit' => 0.0,
            'credit' => round((float) $payment->amount, 2),
            'is_future' => false,
            'kind' => 'payment',
            'order' => 1,
        ]);

        $all = $charges->concat($payments)
            ->filter(fn (array $entry) => $this->to === null || $entry['date']->lte($this->to))
            ->sortBy([
                fn (array $a, array $b) => $a['date']->getTimestamp() <=> $b['date']->getTimestamp(),
                fn (array $a, array $b) => $a['order'] <=> $b['order'],
                fn (array $a, array $b) => $a['key'] <=> $b['key'],
            ])
            ->values();

        $listed = $all;

        if ($this->from !== null) {
            $before = $all->filter(fn (array $entry) => $entry['date']->lt($this->from));
            $this->opening = round($before->sum('debit') - $before->sum('credit'), 2);
            $listed = $all->filter(fn (array $entry) => $entry['date']->gte($this->from))->values();
        }

        $balance = $this->opening;

        $entries = $listed->map(function (array $entry) use (&$balance) {
            $balance = round($balance + $entry['debit'] - $entry['credit'], 2);
            unset($entry['order']);

            return [...$entry, 'balance' => $balance];
        });

        if ($this->from !== null) {
            $entries->prepend([
                'key' => 'opening',
                'date' => $this->from,
                'description' => 'Saldo anterior',
                'debit' => 0.0,
                'credit' => 0.0,
                'balance' => $this->opening,
                'is_future' => false,
                'kind' => 'opening',
            ]);
        }

        return $entries->values();
    }
}
