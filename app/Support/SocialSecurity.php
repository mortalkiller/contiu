<?php

namespace App\Support;

use App\Exceptions\AccountIsReadOnly;
use App\Models\Account;
use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\SocialSecurityContribution;
use App\Models\SocialSecurityDeclaration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SocialSecurity
{
    public const int MAX_AMOUNT = 99_999_999_999;

    public function latestClosedQuarter(): string
    {
        return $this->quarterKey(CarbonImmutable::today('Europe/Lisbon')->startOfQuarter()->subMonths(3));
    }

    public function closedQuarter(string $quarter): ?CarbonImmutable
    {
        if (! preg_match('/\A([1-9][0-9]{3})-Q([1-4])\z/', $quarter, $parts) || $quarter > $this->latestClosedQuarter()) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], ((int) $parts[2] - 1) * 3 + 1, 1, timezone: 'Europe/Lisbon');
    }

    /** @return list<string> */
    public function quarterOptions(string $selected): array
    {
        $latest = $this->requireClosedQuarter($this->latestClosedQuarter());
        $settings = app(FinanceSettings::class);
        $earliestContribution = SocialSecurityContribution::query()->min('contribution_month');
        $earliest = min(array_filter([
            CarbonImmutable::today('Europe/Lisbon')->subYear()->startOfYear()->toDateString(),
            $settings->activityStartedOn()?->toDateString(),
            $settings->obligationsTrackedFrom()?->toDateString(),
            FiscalProfile::query()->min('activity_started_on'),
            FiscalProfile::query()->min('effective_from'),
            Payment::query()->min('paid_on'),
            $earliestContribution === null ? null : CarbonImmutable::parse($earliestContribution)->subMonths(3)->toDateString(),
        ]));
        $earliestDeclaration = SocialSecurityDeclaration::query()->min('quarter');
        $first = min($this->quarterKey(CarbonImmutable::parse($earliest)), $earliestDeclaration ?? $this->latestClosedQuarter());
        $options = [];

        for ($date = $latest; $this->quarterKey($date) >= $first; $date = $date->subMonths(3)) {
            $options[] = $this->quarterKey($date);
        }

        if ($this->closedQuarter($selected) !== null && ! in_array($selected, $options, true)) {
            $options[] = $selected;
        }

        return $options;
    }

    /** @return list<CarbonImmutable> */
    public function incomeMonths(string $quarter): array
    {
        return $this->months($this->requireClosedQuarter($quarter));
    }

    /** @return list<CarbonImmutable> */
    public function contributionMonths(string $quarter): array
    {
        return $this->months($this->requireClosedQuarter($quarter)->addMonths(3));
    }

    /**
     * @return array{
     *     receipts: Collection<int, Payment>,
     *     estimateIncome: int,
     *     estimatedContributions: array<string, int>,
     *     incompleteSettings: bool
     * }
     */
    public function preparation(string $quarter): array
    {
        $start = $this->requireClosedQuarter($quarter);
        $settings = app(FinanceSettings::class);
        $estimator = new TaxEstimator($settings, $start->year);
        $receipts = Payment::query()
            ->with('allocations.receivable.billable')
            ->where('paid_on', '>=', $start->toDateString())
            ->where('paid_on', '<', $start->addMonths(3)->toDateString())
            ->orderBy('paid_on')->orderBy('id')->get();
        $estimatedContributions = [];

        foreach ($this->contributionMonths($quarter) as $month) {
            $estimatedContributions[$month->format('Y-m')] = $estimator->ssContributionForMonth($month);
        }

        return [
            'receipts' => $receipts,
            'estimateIncome' => (int) $receipts->whereStrict('tax_exempt', false)->sum('amount'),
            'estimatedContributions' => $estimatedContributions,
            'incompleteSettings' => $settings->isIncomplete(),
        ];
    }

    public function declaration(string $quarter): ?SocialSecurityDeclaration
    {
        if ($this->closedQuarter($quarter) === null) {
            return null;
        }

        return SocialSecurityDeclaration::query()->where('quarter', $quarter)->latest('id')->first();
    }

    /** @return Collection<int, SocialSecurityDeclaration> */
    public function declarationHistory(string $quarter): Collection
    {
        $this->requireClosedQuarter($quarter);

        return SocialSecurityDeclaration::query()->where('quarter', $quarter)->latest('id')->get();
    }

    /** @return Collection<int, SocialSecurityContribution> */
    public function contributionHistory(string $quarter): Collection
    {
        [$first, , $last] = $this->contributionMonths($quarter);

        return SocialSecurityContribution::query()
            ->where('contribution_month', '>=', $first->toDateString())
            ->where('contribution_month', '<', $last->addMonth()->toDateString())
            ->latest('id')->get();
    }

    /** Null means there is no confirmed value; a confirmed zero is returned as zero. */
    public function confirmedContributionForMonth(CarbonImmutable $month): ?int
    {
        return $this->latestContribution($month->format('Y-m'))?->amount;
    }

    /** Looks up the contribution of the preceding month. */
    public function confirmedPaymentInMonth(CarbonImmutable $month): ?int
    {
        return $this->confirmedContributionForMonth($month->startOfMonth()->subMonth());
    }

    /** @param array<string, int> $amounts The entered totals in cents, indexed by income month (YYYY-MM). */
    public function recordDeclaration(string $quarter, array $amounts, ?int $expectedRevision = null): SocialSecurityDeclaration
    {
        $accountId = $this->writableAccount();
        $months = $this->incomeMonths($quarter);
        $ordered = [];

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $ordered[$key] = $this->validateAmount($amounts[$key] ?? null, 'amounts');
        }

        if (count($amounts) !== count($ordered)) {
            throw ValidationException::withMessages(['amounts' => __('Enter amounts for the three months of this quarter only.')]);
        }

        return DB::transaction(function () use ($accountId, $quarter, $ordered, $expectedRevision): SocialSecurityDeclaration {
            Account::query()->lockForUpdate()->findOrFail($accountId);
            $this->checkRevision($expectedRevision, $this->declaration($quarter)?->id);

            return SocialSecurityDeclaration::query()->create([
                'quarter' => $quarter,
                'amounts' => $ordered,
                'recorded_at' => now(),
                'user_id' => auth()->id(),
            ]);
        });
    }

    public function confirmContribution(string $quarter, string $month, int $amount, ?int $expectedRevision = null): SocialSecurityContribution
    {
        $accountId = $this->writableAccount();
        $months = array_map(fn (CarbonImmutable $date): string => $date->format('Y-m'), $this->contributionMonths($quarter));

        if (! in_array($month, $months, true)) {
            throw ValidationException::withMessages(['month' => __('Choose a contribution month associated with this quarter.')]);
        }

        $this->validateAmount($amount, 'amount');

        return DB::transaction(function () use ($accountId, $month, $amount, $expectedRevision): SocialSecurityContribution {
            Account::query()->lockForUpdate()->findOrFail($accountId);
            $this->checkRevision($expectedRevision, $this->latestContribution($month)?->id);

            return SocialSecurityContribution::query()->create([
                'contribution_month' => $month.'-01',
                'amount' => $amount,
                'confirmed_at' => now(),
                'user_id' => auth()->id(),
            ]);
        });
    }

    private function latestContribution(string $month): ?SocialSecurityContribution
    {
        return SocialSecurityContribution::query()->whereDate('contribution_month', $month.'-01')->latest('id')->first();
    }

    private function writableAccount(): int
    {
        $accountId = CurrentAccount::id();

        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        return $accountId;
    }

    private function requireClosedQuarter(string $quarter): CarbonImmutable
    {
        return $this->closedQuarter($quarter)
            ?? throw ValidationException::withMessages(['quarter' => __('Choose a closed quarter.')]);
    }

    /** @return list<CarbonImmutable> */
    private function months(CarbonImmutable $start): array
    {
        return [$start, $start->addMonth(), $start->addMonths(2)];
    }

    private function quarterKey(CarbonImmutable $date): string
    {
        return $date->year.'-Q'.$date->quarter;
    }

    private function validateAmount(mixed $amount, string $field): int
    {
        if (! is_int($amount) || $amount < 0 || $amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages([$field => __('Enter an amount between €0 and €999,999,999.99.')]);
        }

        return $amount;
    }

    private function checkRevision(?int $expected, ?int $current): void
    {
        if ($expected !== $current) {
            throw ValidationException::withMessages(['revision' => __('The record has changed. Open it again to review the amounts.')]);
        }
    }
}
