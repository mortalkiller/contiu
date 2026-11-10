<?php

namespace App\Support;

use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\CustomerRefund;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Extrato do cliente: every charge (receivable, dated by its due date) and every payment, in date
 * order with a running balance. A positive balance is what the client still owes.
 *
 * Future charges are listed and count towards the running balance (they are already agreed), but are
 * flagged `is_future` so the UI can grey them; `overdueBalance()` is the part that is actually late.
 *
 * @phpstan-type Entry array{key: string, date: CarbonInterface, description: string, debit: int, credit: int, balance: int, is_future: bool, kind: string}
 */
final class ClientStatement
{
    /** @var Collection<int, Entry>|null */
    private ?Collection $entries = null;

    private int $opening = 0; // cents

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

    public function charged(): int
    {
        return (int) $this->entries()->where('kind', 'charge')->sum('debit');
    }

    public function received(): int
    {
        return (int) $this->entries()->where('kind', 'payment')->sum('credit');
    }

    public function balance(): int
    {
        return (int) ($this->entries()->last()['balance'] ?? $this->opening);
    }

    public function overdueBalance(): int
    {
        return (int) $this->receivables()
            ->filter(fn (Receivable $receivable) => $receivable->due_on->isBefore(today()))
            ->filter(fn (Receivable $receivable) => $this->to === null || $receivable->due_on->lte($this->to))
            ->sum(fn (Receivable $receivable) => $receivable->outstanding);
    }

    /** @return Collection<int, Receivable> */
    private function receivables(): Collection
    {
        return Receivable::query()
            ->forClient($this->client->getKey())
            ->excludingCancelled()
            ->with(['billable', 'payments', 'creditApplications'])
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
            'debit' => $receivable->amount,
            'credit' => 0,
            'is_future' => $receivable->due_on->isAfter(today()),
            'kind' => 'charge',
            'order' => 0,
        ]);

        $payments = $receivables->flatMap(fn (Receivable $receivable) => $receivable->payments->reject(fn (Payment $payment): bool => $payment->isAdvance())->map(fn (Payment $payment) => [
            'key' => 'p'.$payment->getKey().($payment->is_split ? '-'.$receivable->id : ''),
            'date' => $payment->paid_on,
            'description' => collect(['Recebimento', $payment->method?->getLabel(), $payment->invoice_number])->filter()->implode(' · '),
            'debit' => 0,
            'credit' => (int) $payment->pivot->amount,
            'is_future' => false,
            'kind' => 'payment',
            'order' => 1,
        ]));

        $advances = Payment::query()->where('advance_client_id', $this->client->getKey())->get()->map(fn (Payment $payment): array => [
            'key' => 'p'.$payment->getKey(),
            'date' => $payment->paid_on,
            'description' => collect(['Sinal recebido', $payment->method?->getLabel(), $payment->invoice_number])->filter()->implode(' · '),
            'debit' => 0,
            'credit' => $payment->amount,
            'is_future' => false,
            'kind' => 'payment',
            'order' => 1,
        ]);

        $credits = $this->creditEntries($receivables->flatMap->creditApplications);

        $all = $charges->concat($payments)->concat($advances)->concat($credits)->concat($this->refundEntries($receivables))
            ->concat($this->withholdingEntries($receivables))
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
            $this->opening = (int) ($before->sum('debit') - $before->sum('credit'));
            $listed = $all->filter(fn (array $entry) => $entry['date']->gte($this->from))->values();
        }

        $balance = $this->opening;

        $entries = $listed->map(function (array $entry) use (&$balance) {
            $balance = $balance + $entry['debit'] - $entry['credit'];
            unset($entry['order']);

            return [...$entry, 'balance' => $balance];
        });

        if ($this->from !== null) {
            $entries->prepend([
                'key' => 'opening',
                'date' => $this->from,
                'description' => 'Saldo anterior',
                'debit' => 0,
                'credit' => 0,
                'balance' => $this->opening,
                'is_future' => false,
                'kind' => 'opening',
            ]);
        }

        return $entries->values();
    }

    /**
     * @param  Collection<int, Receivable>  $receivables
     * @return Collection<int, array<string, mixed>>
     */
    private function refundEntries(Collection $receivables): Collection
    {
        return CustomerRefund::query()->whereIntegerInRaw('receivable_id', $receivables->modelKeys())->with('creditNote')->get()
            ->flatMap(function (CustomerRefund $refund): array {
                $entry = ['date' => $refund->paid_on, 'is_future' => false];

                return [[
                    ...$entry, 'key' => 'rf'.$refund->id, 'description' => 'Reembolso · '.$refund->reference,
                    'debit' => $refund->amount, 'credit' => 0, 'kind' => 'refund', 'order' => 3,
                ], [
                    ...$entry, 'key' => 'rfc'.$refund->id, 'description' => 'Nota de crédito reembolsada · '.$refund->creditNote->number,
                    'debit' => 0, 'credit' => $refund->amount, 'kind' => 'credit', 'order' => 4,
                ]];
            });
    }

    /** @param Collection<int, Receivable> $receivables @return Collection<int, array<string, mixed>> */
    private function withholdingEntries(Collection $receivables): Collection
    {
        return WithholdingReview::settlementsOn($this->to?->toDateString() ?? today()->toDateString())
            ->whereIntegerInRaw('withholding_records.receivable_id', $receivables->modelKeys())
            ->get(['withholding_records.id', 'withholding_records.paid_on', 'withholding_records.retained_amount', 'withholding_records.document_number'])
            ->map(fn (object $record): array => [
                'key' => 'w'.$record->id,
                'date' => CarbonImmutable::parse($record->paid_on),
                'description' => 'Retenção na fonte · '.$record->document_number,
                'debit' => 0,
                'credit' => (int) $record->retained_amount,
                'is_future' => false,
                'kind' => 'withholding',
                'order' => 2,
            ]);
    }

    /** @param Collection<int, CreditApplication> $applications @return Collection<int, array<string, mixed>> */
    private function creditEntries(Collection $applications): Collection
    {
        $references = FiscalDocument::query()->whereKey($applications->pluck('credit_note_id')->unique()->all())->pluck('number', 'id');

        return $applications->flatMap(function (CreditApplication $application) use ($references): array {
            $label = $references[$application->credit_note_id];
            $entry = ['is_future' => false, 'kind' => 'credit', 'order' => 2];
            $applied = [
                ...$entry, 'key' => 'c'.$application->id, 'date' => $application->applied_on,
                'description' => 'Nota de crédito · '.$label, 'debit' => 0, 'credit' => $application->amount,
            ];

            if ($application->reversed_on === null) {
                return [$applied];
            }

            return [$applied, [
                ...$entry, 'key' => 'cr'.$application->id, 'date' => $application->reversed_on,
                'description' => 'Aplicação revertida · '.$label, 'debit' => $application->amount, 'credit' => 0,
            ]];
        });
    }
}
