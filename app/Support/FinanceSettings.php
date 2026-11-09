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
        'vat_exempt' => false,
        'vat_exemption_threshold' => 1500000, // cents
        'ss_rate' => 0.214,
        'ss_relevant_income_share' => 0.70,
        'payments_exempt_by_default' => false,
        'account_names' => null, // one per line: the holder's name and the holder's own accounts
        // A bank account only for the activity, whose statement is imported. On for accounts from
        // before the question (Pedro's); the sign-up wizard asks it, off being the common case.
        'has_business_account' => true,
        'activity_start_kind' => null, // first | restart
        'previous_activity_ended_on' => null,
        'ss_enrolled_from' => null,
        'dependent_income_years' => [], // years with employment income (category A)
        'irs_coefficient' => 0.75,
        'irs_jovem' => false,
        'birth_date' => null,
        'irs_jovem_year' => null, // {"year": 2026, "number": 2}: the IRS Jovem year number in a reference year
        'ss_contribution_exempt' => false,
        'reverse_charge_vat_rate' => 0.23,
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

    public function activityStartedOn(): ?CarbonImmutable
    {
        return $this->date('activity_started_on');
    }

    /** first | restart; null until the account says it. */
    public function activityStartKind(): ?string
    {
        return blank($this->all()['activity_start_kind']) ? null : (string) $this->all()['activity_start_kind'];
    }

    public function previousActivityEndedOn(): ?CarbonImmutable
    {
        return $this->date('previous_activity_ended_on');
    }

    public function ssEnrolledFrom(): ?CarbonImmutable
    {
        return $this->date('ss_enrolled_from');
    }

    /** @return list<int> */
    public function dependentIncomeYears(): array
    {
        $years = $this->all()['dependent_income_years'];

        return is_array($years) ? array_values(array_map('intval', $years)) : [];
    }

    public function irsCoefficient(): float
    {
        return (float) $this->all()['irs_coefficient'];
    }

    public function irsJovem(): bool
    {
        return (bool) $this->all()['irs_jovem'];
    }

    public function birthDate(): ?CarbonImmutable
    {
        return $this->date('birth_date');
    }

    /** The IRS Jovem year (1 to 10) in a calendar year, counted from the stored reference year. */
    public function irsJovemYearNumber(int $year): ?int
    {
        $reference = $this->all()['irs_jovem_year'];

        if (! is_array($reference) || ! isset($reference['year'], $reference['number'])) {
            return null;
        }

        return (int) $reference['number'] + ($year - (int) $reference['year']);
    }

    public function ssContributionExempt(): bool
    {
        return (bool) $this->all()['ss_contribution_exempt'];
    }

    public function reverseChargeVatRate(): float
    {
        return (float) $this->all()['reverse_charge_vat_rate'];
    }

    /** The account still has to say when and how the activity started. */
    public function isIncomplete(): bool
    {
        return $this->activityStartedOn() === null || $this->activityStartKind() === null;
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->all()[$key];

        return blank($value) ? null : CarbonImmutable::parse($value)->startOfDay();
    }
}
