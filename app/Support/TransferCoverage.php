<?php

namespace App\Support;

use App\Enums\BankTransactionStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which payments and expenses each "Transferência minha" covers, for display. A transfer is not
 * tied to specific records, so this follows the same rule as the reconciliation: going through the
 * transfers by date, money that came into the company account covers the oldest payments not
 * linked to their own statement line. Expenses paid outside the company account before the
 * transfer count as already deducted from it (as when 730 € came in as 646,26 € after paying
 * ChatGPT), but only as far as there are payments left to cover. Money that went out to Pedro
 * gives back expenses he paid, oldest first.
 */
final class TransferCoverage
{
    /** @var array<int, list<TransferCover>>|null */
    private ?array $covers = null;

    /** @var array<string, list<array{line: BankTransaction, amount: int}>> by record key */
    private array $coveredBy = [];

    /** @var array<string, int> what is left to cover, by record key (records in scope only) */
    private array $remaining = [];

    /** Transfers that covered a payment or expense, oldest first, with how much each. */
    public function coveredBy(Payment|Expense $record): array
    {
        $this->all();

        return $this->coveredBy[$this->key($record)] ?? [];
    }

    /** Cents of the record not covered by any transfer; null when it is not in scope (linked, out of range). */
    public function remaining(Payment|Expense $record): ?int
    {
        $this->all();

        return $this->remaining[$this->key($record)] ?? null;
    }

    private function key(Payment|Expense $record): string
    {
        return $record::class.':'.$record->getKey();
    }

    /** @return list<TransferCover> */
    public function for(BankTransaction $transfer): array
    {
        return $this->all()[$transfer->getKey()] ?? [];
    }

    /** @return array<int, list<TransferCover>> */
    private function all(): array
    {
        if ($this->covers !== null) {
            return $this->covers;
        }

        $account = new CompanyAccount;
        $from = $account->openingBalance() === 0 ? null : $account->startsOn();

        $payments = $this->open(Payment::query()->with(['bankLink', 'receivable.billable'])->get(), fn (Payment $payment) => $payment->paid_on, fn (Payment $payment) => (int) $payment->amount, $from);
        $expenses = $this->open(Expense::query()->where('is_paid', true)->with(['bankLink', 'supplier'])->get(), fn (Expense $expense) => $expense->paid_on ?? $expense->spent_on, fn (Expense $expense) => $expense->total, $from);
        $this->covers = [];

        $transfers = BankTransaction::query()->where('status', BankTransactionStatus::OwnTransfer)->orderBy('booked_on')->orderBy('id')->get();

        foreach ($transfers as $transfer) {
            $day = Carbon::parse($transfer->booked_on)->endOfDay();
            $eligible = fn (array $item) => $item['remaining'] > 0 && $item['date']->lte($day);
            $covers = [];

            if ($transfer->is_credit) {
                $capacity = $transfer->absolute_amount;
                $owed = (int) collect($payments)->filter($eligible)->sum('remaining');
                $canNet = max(0, $owed - $capacity);

                foreach ($expenses as $key => $item) {
                    if ($canNet <= 0 || ! $eligible($item)) {
                        continue;
                    }

                    $take = min($canNet, $item['remaining']);
                    $expenses[$key]['remaining'] -= $take;
                    $canNet -= $take;
                    $capacity += $take;
                    $covers[] = new TransferCover($item['record'], $take, $item['amount'], deducted: true);
                    $this->coveredBy[$this->key($item['record'])][] = ['line' => $transfer, 'amount' => $take];
                }

                foreach ($payments as $key => $item) {
                    if ($capacity <= 0) {
                        break;
                    }

                    if (! $eligible($item)) {
                        continue;
                    }

                    $take = min($capacity, $item['remaining']);
                    $payments[$key]['remaining'] -= $take;
                    $capacity -= $take;
                    $covers[] = new TransferCover($item['record'], $take, $item['amount']);
                    $this->coveredBy[$this->key($item['record'])][] = ['line' => $transfer, 'amount' => $take];
                }

                // Payments first, then the expenses deducted from them.
                usort($covers, fn (TransferCover $a, TransferCover $b) => [$a->record instanceof Expense, $a->record->getKey()] <=> [$b->record instanceof Expense, $b->record->getKey()]);
            } else {
                $capacity = $transfer->absolute_amount;

                foreach ($expenses as $key => $item) {
                    if ($capacity <= 0) {
                        break;
                    }

                    if (! $eligible($item)) {
                        continue;
                    }

                    $take = min($capacity, $item['remaining']);
                    $expenses[$key]['remaining'] -= $take;
                    $capacity -= $take;
                    $covers[] = new TransferCover($item['record'], $take, $item['amount']);
                    $this->coveredBy[$this->key($item['record'])][] = ['line' => $transfer, 'amount' => $take];
                }
            }

            $this->covers[$transfer->getKey()] = $covers;
        }

        foreach ([...$payments, ...$expenses] as $item) {
            $this->remaining[$this->key($item['record'])] = $item['remaining'];
        }

        return $this->covers;
    }

    /**
     * Records not linked to a statement line, oldest first, with what is left to cover.
     *
     * @param  Collection<int, Payment|Expense>  $records
     * @return list<array{record: Payment|Expense, date: Carbon, amount: int, remaining: int}>
     */
    private function open(Collection $records, callable $date, callable $amount, ?Carbon $from): array
    {
        return $records
            ->filter(fn ($record) => $record->bankLink === null && $date($record) !== null)
            ->map(fn ($record) => ['record' => $record, 'date' => Carbon::parse($date($record))->startOfDay(), 'amount' => $amount($record), 'remaining' => $amount($record)])
            ->filter(fn (array $item) => $from === null || $item['date']->gte($from))
            ->sortBy([fn ($a, $b) => $a['date'] <=> $b['date'], fn ($a, $b) => $a['record']->getKey() <=> $b['record']->getKey()])
            ->values()
            ->all();
    }
}
