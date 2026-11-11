<?php

namespace App\Support;

use App\Enums\PortugueseVatRegion;
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
        'cae' => null, // the main activity code, as registered with the tax authority
        // Sells or moves goods: turns on transport guides and the transport details of an invoice.
        'sells_goods' => false,
        'vat_exempt' => false,
        'vat_region' => null,
        'vat_exemption_threshold' => 1500000, // cents
        'ss_rate' => 0.214,
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
        'tax_reserved_amount' => 0,
        'tax_reserve_transfer' => null,
        // From when the obligations card lists overdue obligations (default: the account's creation).
        'obligations_tracked_from' => null,
        // Whether the activity is already open with the Finanças; off: an account waiting to open it,
        // with no obligations nor estimates yet.
        'activity_opened' => true,
        // simplified | organised: Contiu only estimates IRS and Social Security in the simplified regime.
        'accounting_regime' => 'simplified',
        // Why the Social Security exemption applies (art. 157.º): accumulation | pension | minimum | other.
        'ss_exemption_reason' => null,
        // Percent added to the declared relevant income, from -25 to 25 in steps of 5 (art. 164.º).
        'ss_base_adjustment' => 0,
        // monthly | quarterly periodic VAT return, outside the art. 53.º exemption (art. 41.º CIVA).
        'vat_period' => 'quarterly',
        // The worker waived IRS withholding on their receipts (art. 101.º-B CIRS).
        'withholding_waiver' => false,
        /*
         * From the IRS assessment of a year (nota de liquidação), for the payments on account two
         * years later (art. 102.º CIRS): {"income_year": 2024, "tax": cents, "withheld": cents,
         * "net_business": cents, "net_total": cents}.
         */
        'irs_prepayment_basis' => null,
    ];

    public const SS_EXEMPTION_REASONS = ['accumulation', 'pension', 'minimum', 'other'];

    public const SS_BASE_ADJUSTMENTS = [-25, -20, -15, -10, -5, 0, 5, 10, 15, 20, 25];

    /** @var array<int, array<string, mixed>> */
    private array $valuesByAccount = [];

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->valuesByAccount[CurrentAccount::id()] ??= [
            ...self::DEFAULTS,
            ...Setting::query()->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all(),
        ];
    }

    /** @param array<string, mixed> $values */
    public function set(array $values): void
    {
        unset($this->valuesByAccount[CurrentAccount::id()]);

        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value === '' ? null : $value]);
        }
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

    public function taxReservedAmount(): int
    {
        return (int) $this->all()['tax_reserved_amount'];
    }

    /** @return array{id: int, amount: int, bookedOn: string}|null */
    public function taxReserveTransfer(): ?array
    {
        return $this->all()['tax_reserve_transfer'];
    }

    public function cae(): ?string
    {
        return $this->all()['cae'];
    }

    public function sellsGoods(): bool
    {
        return (bool) $this->all()['sells_goods'];
    }

    public function vatExempt(): bool
    {
        return (bool) $this->all()['vat_exempt'];
    }

    public function vatRegion(): ?PortugueseVatRegion
    {
        $value = $this->all()['vat_region'];

        return is_string($value) ? PortugueseVatRegion::tryFrom($value) : null;
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

    /**
     * Share of the income that is relevant for the Social Security (art. 162.º CRC, SS020): 20 % of
     * sales of goods, restaurants and hotels (the IRS 0.15 coefficient), 70 % of services.
     */
    public function ssRelevantIncomeShare(): float
    {
        return $this->sellsGoodsUnderIrs() ? 0.20 : 0.70;
    }

    /** The IRS coefficient is the one of sales of goods, restaurants and hotels (art. 31.º, n.º 1, a), SIM001). */
    public function sellsGoodsUnderIrs(): bool
    {
        return round($this->irsCoefficient(), 2) === 0.15;
    }

    /** accumulation | pension | minimum | other; null when exempt without saying why. */
    public function ssExemptionReason(): ?string
    {
        $reason = $this->all()['ss_exemption_reason'];

        return in_array($reason, self::SS_EXEMPTION_REASONS, true) ? $reason : null;
    }

    /** The chosen change to the relevant income, as a fraction (−0.25 to 0.25). */
    public function ssBaseAdjustment(): float
    {
        $percent = (int) $this->all()['ss_base_adjustment'];

        return in_array($percent, self::SS_BASE_ADJUSTMENTS, true) ? $percent / 100 : 0.0;
    }

    public function activityOpened(): bool
    {
        return (bool) $this->all()['activity_opened'];
    }

    /** simplified | organised. */
    public function accountingRegime(): string
    {
        return $this->all()['accounting_regime'] === 'organised' ? 'organised' : 'simplified';
    }

    /** IRS and Social Security are only estimated for the simplified regime with an open activity. */
    public function estimatesTaxes(): bool
    {
        return $this->activityOpened() && $this->accountingRegime() === 'simplified';
    }

    /** 1 for monthly VAT returns, 3 for quarterly ones. */
    public function vatPeriodMonths(): int
    {
        return $this->all()['vat_period'] === 'monthly' ? 1 : 3;
    }

    public function withholdingWaiver(): bool
    {
        return (bool) $this->all()['withholding_waiver'];
    }

    /** @return array{income_year: int, tax: int, withheld: int, net_business: int, net_total: int}|null */
    public function irsPrepaymentBasis(): ?array
    {
        $basis = $this->all()['irs_prepayment_basis'];

        if (! is_array($basis) || ! isset($basis['income_year'], $basis['tax'], $basis['withheld'], $basis['net_business'], $basis['net_total'])) {
            return null;
        }

        return array_map('intval', array_intersect_key($basis, array_flip(['income_year', 'tax', 'withheld', 'net_business', 'net_total'])));
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

    /** From when overdue obligations are listed; null: since the account was created. */
    public function obligationsTrackedFrom(): ?CarbonImmutable
    {
        return $this->date('obligations_tracked_from');
    }

    public function reverseChargeVatRate(): float
    {
        return (float) $this->all()['reverse_charge_vat_rate'];
    }

    /**
     * The account still has to say when and how the activity started. An account waiting to open
     * the activity is not incomplete: it has nothing to say yet.
     */
    public function isIncomplete(): bool
    {
        return $this->activityOpened() && ($this->activityStartedOn() === null || $this->activityStartKind() === null);
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->all()[$key];

        return blank($value) ? null : CarbonImmutable::parse($value)->startOfDay();
    }
}
