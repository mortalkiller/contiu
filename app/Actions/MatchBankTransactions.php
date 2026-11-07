<?php

namespace App\Actions;

use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BankCandidate;
use App\Support\BankSuggestion;
use App\Support\FinanceSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds what a bank line most likely is:
 * - credit → open receivables with exactly that amount open, and payments already registered with
 *   that amount up to 10 days apart that no other bank line points at;
 * - debit → unpaid expenses with that total, and paid expenses with that total up to 10 days
 *   apart that no other bank line points at; else a recurring template with that total and its
 *   supplier named in the description; else any supplier named in the description.
 * Any line whose description names the account holder (finance settings) or a user of the app in
 * full ("TRF DE PEDRO FILIPE PEREIRA MONTEIRO") or says "ordenado" can also be money between Pedro's own accounts (own_transfer).
 * A credit smaller than what is open on a receivable whose client is named is a partial receipt.
 * Candidates are ranked by how much of the client/supplier name appears in the description, then
 * by how close the dates are.
 */
class MatchBankTransactions
{
    /** How far a registered payment or paid expense may be from the bank date to be suggested. */
    public const REGISTERED_WINDOW_DAYS = 10;

    private const STOP_WORDS = ['lda', 'unipessoal', 'sociedade', 'limitada', 'instituto', 'nacional', 'the', 'inc', 'ltd', 'sas', 'gmbh', 'com', 'para', 'dos', 'das'];

    public function for(BankTransaction $transaction): BankSuggestion
    {
        $description = $this->normalise($transaction->description);
        $amount = $transaction->absolute_amount;
        $own = $this->ownTransfer($description);

        if ($transaction->is_credit) {
            $receivables = Receivable::query()->open()->excludingCancelled()->with(['payments', 'billable.client'])->get();

            $exact = $receivables
                ->filter(fn (Receivable $receivable) => abs($receivable->outstanding - $amount) < 0.01)
                ->map(fn (Receivable $receivable) => new BankCandidate('receivable', $receivable, $this->nameScore($receivable->client?->name, $description), $this->daysApart($transaction, $receivable->due_on)));

            $registered = $this->registeredPayments($transaction, $description);

            // A partial receipt is only a fallback: never next to an exact or already registered match
            // (that would invite a duplicate), and only the earliest open instalment of the client.
            $partial = $exact->isNotEmpty() || $registered->isNotEmpty() ? collect() : $receivables
                ->filter(fn (Receivable $receivable) => $receivable->outstanding > $amount + 0.009)
                ->map(fn (Receivable $receivable) => new BankCandidate('partial', $receivable, $this->nameScore($receivable->client?->name, $description), $this->daysApart($transaction, $receivable->due_on)))
                ->filter(fn (BankCandidate $candidate) => $candidate->nameScore > 0)
                ->sortBy([fn (BankCandidate $a, BankCandidate $b) => $b->nameScore <=> $a->nameScore, fn (BankCandidate $a, BankCandidate $b) => $a->model->due_on <=> $b->model->due_on])
                ->take(1);

            return $this->rank($exact->concat($registered)->concat($partial)->concat($own));
        }

        $expenses = Expense::query()->where('is_paid', false)->with('supplier')->get()
            ->filter(fn (Expense $expense) => abs($expense->total - $amount) < 0.01)
            ->map(fn (Expense $expense) => new BankCandidate('expense', $expense, $this->nameScore($expense->supplier->name, $description), $this->daysApart($transaction, $expense->spent_on)))
            ->concat($this->registeredExpenses($transaction, $description));

        if ($expenses->isNotEmpty()) {
            return $this->rank($expenses->concat($own));
        }

        $templates = RecurringExpense::query()->where('is_active', true)->with('supplier')->get()
            ->filter(fn (RecurringExpense $template) => abs($template->total - $amount) < 0.01)
            ->map(fn (RecurringExpense $template) => new BankCandidate('template', $template, $this->nameScore($template->supplier->name, $description), 0))
            ->filter(fn (BankCandidate $candidate) => $candidate->nameScore > 0);

        if ($templates->isNotEmpty()) {
            return $this->rank($templates->concat($own));
        }

        return $this->rank(
            Supplier::query()->get()
                ->map(fn (Supplier $supplier) => new BankCandidate('supplier', $supplier, $this->nameScore($supplier->name, $description), 0))
                ->filter(fn (BankCandidate $candidate) => $candidate->nameScore > 0)
                ->concat($own),
        );
    }

    /**
     * Every word (3+ letters) of a user's full name in the description, or "ordenado". A single
     * shared first name is not enough.
     *
     * @return Collection<int, BankCandidate>
     */
    private function ownTransfer(string $description): Collection
    {
        $words = explode(' ', $description);

        $score = User::query()->pluck('name')
            ->push(app(FinanceSettings::class)->accountHolderName())
            ->filter()
            ->map(fn (string $name) => collect(explode(' ', $this->normalise($name)))->filter(fn (string $token) => strlen($token) >= 3)->unique())
            ->filter(fn (Collection $tokens) => $tokens->count() >= 2 && $tokens->every(fn (string $token) => in_array($token, $words, true)))
            ->map->count()
            ->max() ?? (in_array('ordenado', $words, true) ? 1 : 0);

        return $score > 0 ? collect([new BankCandidate('own_transfer', null, $score, 0)]) : collect();
    }

    /** @return Collection<int, BankCandidate> */
    private function registeredPayments(BankTransaction $transaction, string $description): Collection
    {
        [$from, $until] = $this->window($transaction);

        return Payment::query()
            ->doesntHave('bankLink')
            ->whereBetween('paid_on', [$from, $until])
            ->with('receivable.billable.client')
            ->get()
            ->filter(fn (Payment $payment) => abs((float) $payment->amount - $transaction->absolute_amount) < 0.01)
            ->map(fn (Payment $payment) => new BankCandidate('payment', $payment, $this->nameScore($payment->receivable?->client?->name, $description), $this->daysApart($transaction, $payment->paid_on)))
            ->values();
    }

    /** @return Collection<int, BankCandidate> */
    private function registeredExpenses(BankTransaction $transaction, string $description): Collection
    {
        [$from, $until] = $this->window($transaction);

        return Expense::query()
            ->where('is_paid', true)
            ->doesntHave('bankLink')
            ->where(fn ($query) => $query->whereBetween('paid_on', [$from, $until])
                ->orWhere(fn ($query) => $query->whereNull('paid_on')->whereBetween('spent_on', [$from, $until])))
            ->with('supplier')
            ->get()
            ->filter(fn (Expense $expense) => abs($expense->total - $transaction->absolute_amount) < 0.01)
            ->map(fn (Expense $expense) => new BankCandidate('paid', $expense, $this->nameScore($expense->supplier->name, $description), $this->daysApart($transaction, $expense->paid_on ?? $expense->spent_on)))
            ->values();
    }

    /** @return array{0: string, 1: string} */
    private function window(BankTransaction $transaction): array
    {
        return [
            $transaction->booked_on->copy()->subDays(self::REGISTERED_WINDOW_DAYS)->startOfDay()->toDateTimeString(),
            $transaction->booked_on->copy()->addDays(self::REGISTERED_WINDOW_DAYS)->endOfDay()->toDateTimeString(),
        ];
    }

    /** @param Collection<int, BankCandidate> $candidates */
    private function rank(Collection $candidates): BankSuggestion
    {
        return new BankSuggestion($candidates
            ->sort(fn (BankCandidate $a, BankCandidate $b) => [$b->nameScore, $a->daysApart] <=> [$a->nameScore, $b->daysApart])
            ->values());
    }

    /** How many words of the name appear as whole words in the description ("ana" ≠ "santana"). */
    private function nameScore(?string $name, string $description): int
    {
        $words = explode(' ', $description);

        return collect(explode(' ', $this->normalise((string) $name)))
            ->filter(fn (string $token) => strlen($token) >= 3 && ! in_array($token, self::STOP_WORDS, true))
            ->unique()
            ->filter(fn (string $token) => in_array($token, $words, true))
            ->count();
    }

    private function daysApart(BankTransaction $transaction, $date): int
    {
        return (int) abs($transaction->booked_on->diffInDays($date));
    }

    private function normalise(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))) ?? '');
    }
}
