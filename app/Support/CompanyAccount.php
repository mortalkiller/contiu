<?php

namespace App\Support;

use App\Enums\BankTransactionStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The company bank account against what Contiu recorded. The imported statement gives the real
 * balance; Contiu's balance is the same starting point plus every payment received minus every
 * expense paid since, whatever account the money went through (since the beginning when the
 * statement starts with the account's opening at zero). The difference is money that
 * still has to move between Pedro's personal account and the company's:
 *   negative → "falta transferir" to the company; positive → the company owes Pedro (e.g. an
 *   expense paid with the personal account). All amounts in cents.
 */
final class CompanyAccount
{
    private ?int $opening = null;

    private bool $openingResolved = false;

    /** @param  CarbonInterface|null  $until  look only at what happened up to this day (month close) */
    public function __construct(private readonly ?CarbonInterface $until = null) {}

    public function hasStatement(): bool
    {
        return $this->lines()->exists();
    }

    public function startsOn(): ?Carbon
    {
        $date = $this->lines()->min('booked_on');

        return $date === null ? null : Carbon::parse($date)->startOfDay();
    }

    /** Date of the last statement line considered: the balances are compared on this day. */
    public function endsOn(): ?Carbon
    {
        $date = $this->lines()->max('booked_on');

        return $date === null ? null : Carbon::parse($date)->startOfDay();
    }

    /** Balance before the first statement line, from that line's running balance. */
    public function openingBalance(): ?int
    {
        if ($this->openingResolved) {
            return $this->opening;
        }

        $this->openingResolved = true;
        $start = $this->startsOn();

        if ($start === null) {
            return null;
        }

        $firstDay = $this->lines()->whereDate('booked_on', $start)->whereNotNull('balance')->orderBy('id')->get();

        if ($firstDay->isEmpty()) {
            return null; // the statement has no balance column
        }

        // Same-day lines may come in any order: the opening is the "balance before" that is not
        // the balance after another line of that day.
        $balancesAfter = $firstDay->pluck('balance')->all();
        $first = $firstDay->first(fn (BankTransaction $line) => ! in_array($line->balance - $line->amount, $balancesAfter, true)) ?? $firstDay->first();

        return $this->opening = $first->balance - $first->amount;
    }

    /** What the bank says at the end of the statement: opening + every line. */
    public function bankBalance(): ?int
    {
        $opening = $this->openingBalance();

        return $opening === null ? null : $opening + (int) $this->lines()->sum('amount');
    }

    /** What the company should have on the statement's last day, from what Contiu recorded. */
    public function contiuBalance(): ?int
    {
        $end = $this->endsOn();

        return $end === null ? null : $this->contiuBalanceAt($end);
    }

    /** Same starting point plus payments minus paid expenses up to a day (e.g. today, for the forecast). */
    public function contiuBalanceAt(CarbonInterface $day): ?int
    {
        $opening = $this->openingBalance();
        $start = $this->startsOn();

        if ($opening === null || $start === null) {
            return null;
        }

        // A statement that starts with the account's opening (balance 0 before it) means the account
        // is new: everything the business received or paid before then went through Pedro's
        // personal account and still counts. Otherwise what came before is already in the opening.
        // No lower bound in that case (not a far-past date: PostgreSQL has no year 0).
        $from = $opening === 0 ? null : $start->toDateString();
        $until = Carbon::parse($day)->endOfDay()->toDateTimeString();
        $within = fn (Builder $query, string $column) => $from === null
            ? $query->where($column, '<=', $until)
            : $query->whereBetween($column, [$from, $until]);

        $received = (int) $within(Payment::query(), 'paid_on')->sum('amount');
        $paid = (int) Expense::query()
            ->where('is_paid', true)
            ->where(fn (Builder $query) => $within($query, 'paid_on')
                ->orWhere(fn (Builder $query) => $within($query->whereNull('paid_on'), 'spent_on')))
            ->get()
            ->sum(fn (Expense $expense) => $expense->total);

        return $opening + $received - $paid;
    }

    /** Bank − Contiu: negative = still to transfer to the company, positive = the company owes Pedro. */
    public function difference(): ?int
    {
        $bank = $this->bankBalance();
        $contiu = $this->contiuBalance();

        return $bank === null || $contiu === null ? null : $bank - $contiu;
    }

    /** Statement lines not treated yet: until they are, the difference may still change. */
    public function pendingLines(): int
    {
        return $this->lines()->where('status', BankTransactionStatus::Pending)->count();
    }

    /** @return Builder<BankTransaction> */
    private function lines(): Builder
    {
        return BankTransaction::query()
            ->when($this->until, fn (Builder $query) => $query->where('booked_on', '<=', Carbon::parse($this->until)->endOfDay()->toDateTimeString()));
    }
}
