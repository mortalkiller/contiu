<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Typed access to the fiscal / cash settings used by the analysis pages.
 * Defaults are the neutral case (nothing exempt); each account sets its own regime.
 */
final class FinanceSettings
{
    public const DEFAULTS = [
        'activity_started_on' => null,
        'irs_exempt' => false,
        'vat_exempt' => false,
        'vat_exemption_threshold' => 1500000, // cents
        'ss_rate' => 0.214,
        'ss_relevant_income_share' => 0.70,
        'ss_first_year_exempt' => false,
        'payments_exempt_by_default' => false,
        'account_names' => null, // one per line: the holder's name and the holder's own accounts
        // A bank account only for the activity, whose statement is imported. On for accounts from
        // before the question (Pedro's); the sign-up wizard asks it, off being the common case.
        'has_business_account' => true,
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values ??= [
            ...self::DEFAULTS,
            ...Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all(),
        ];
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value === '' ? null : $value]);
        }

        $this->values = null;
    }

    /** New payments and retainers start tax-exempt (a retainer marked not exempt still rules its payments). */
    public function paymentsExemptByDefault(): bool
    {
        return (bool) $this->all()['payments_exempt_by_default'];
    }

    /**
     * How the bank names the holder's own accounts in transfer descriptions: the holder's name
     * and the names given to the other accounts.
     *
     * @return array<int, string>
     */
    public function accountNames(): array
    {
        return collect(preg_split('/\R/', (string) $this->all()['account_names']) ?: [])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->values()
            ->all();
    }

    /** Statement import, reconciliation, the company account card and the forecast's balance. */
    public function hasBusinessAccount(): bool
    {
        return (bool) $this->all()['has_business_account'];
    }

    public function irsExempt(): bool
    {
        return (bool) $this->all()['irs_exempt'];
    }

    public function vatExempt(): bool
    {
        return (bool) $this->all()['vat_exempt'];
    }

    /** Cents. */
    public function vatExemptionThreshold(): int
    {
        return (int) $this->all()['vat_exemption_threshold'];
    }

    public function ssRate(): float
    {
        return (float) $this->all()['ss_rate'];
    }

    public function ssRelevantIncomeShare(): float
    {
        return (float) $this->all()['ss_relevant_income_share'];
    }

    public function ssFirstYearExempt(): bool
    {
        return (bool) $this->all()['ss_first_year_exempt'];
    }

    public function activityStartedOn(): ?CarbonImmutable
    {
        return $this->date('activity_started_on');
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->all()[$key];

        return blank($value) ? null : CarbonImmutable::parse($value)->startOfDay();
    }
}
