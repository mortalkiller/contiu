<?php

namespace App\Support;

use App\Actions\RecordTaxPayment;
use App\Enums\CategoryType;
use App\Models\Expense;
use App\Models\SocialSecurityContribution;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class AnnualFiscalReview
{
    public function __construct(public int $year) {}

    /** @return array{billed: ?int, received: int, expenses: int, taxPaid: int, otherCurrencies: int, expensesWithoutProof: int, expensesToReview: int} */
    public function totals(): array
    {
        $turnover = DocumentedTurnover::forYear($this->year);
        $expenses = Expense::query()->whereYear('spent_on', $this->year)->get();

        return [
            'billed' => $turnover->billedNet,
            'received' => $turnover->received,
            'expenses' => (int) $expenses->sum(fn (Expense $expense): int => $expense->total),
            'taxPaid' => (int) self::taxPayments($this->year)->get()->sum(fn (Expense $expense): int => $expense->total),
            'otherCurrencies' => $turnover->nonEuroDocumentCount,
            'expensesWithoutProof' => $expenses->reject(fn (Expense $expense): bool => $expense->hasProof())->count(),
            'expensesToReview' => $expenses->where('is_reviewed', false)->count(),
        ];
    }

    /** @return Collection<int, SocialSecurityContribution> */
    public function confirmedContributions(): Collection
    {
        return SocialSecurityContribution::query()->whereYear('contribution_month', $this->year)
            ->latest('id')
            ->get()
            ->unique(fn (SocialSecurityContribution $row): string => $row->contribution_month->format('Y-m'))
            ->sortBy('contribution_month')
            ->values();
    }

    public static function incomeQuarter(CarbonImmutable $contributionMonth): string
    {
        $source = $contributionMonth->firstOfQuarter()->subMonths(3);

        return $source->year.'-Q'.$source->quarter;
    }

    /** @param Builder<Expense>|null $query @return Builder<Expense> */
    public static function taxPayments(int $year, ?Builder $query = null, ?CarbonInterface $through = null): Builder
    {
        return ($query ?? Expense::query())
            ->where('is_paid', true)
            ->whereYear('paid_on', $year)
            ->when($through, fn (Builder $query, CarbonInterface $date): Builder => $query->whereDate('paid_on', '<=', $date))
            ->whereHas('category', fn (Builder $query): Builder => $query
                ->where('type', CategoryType::Expense)->where('name', RecordTaxPayment::CATEGORY));
    }
}
