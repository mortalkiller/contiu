<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Typed access to the fiscal / cash settings used by the analysis pages.
 * Defaults follow what Pedro told us: ENI, regime simplificado, isento de IRS, IVA art. 53.º.
 */
final class FinanceSettings
{
    public const DEFAULTS = [
        'activity_started_on' => null,
        'irs_exempt' => true,
        'vat_exempt' => true,
        'vat_exemption_threshold' => 15000.0,
        'ss_rate' => 0.214,
        'ss_relevant_income_share' => 0.70,
        'ss_first_year_exempt' => true,
        'current_bank_balance' => null,
        'current_bank_balance_on' => null,
        'payments_exempt_by_default' => true,
        'account_holder_name' => null,
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

    /** The name the bank shows for Pedro's own transfers ("PEDRO FILIPE PEREIRA MONTEIRO"). */
    public function accountHolderName(): ?string
    {
        return filled($this->all()['account_holder_name']) ? (string) $this->all()['account_holder_name'] : null;
    }

    public function irsExempt(): bool
    {
        return (bool) $this->all()['irs_exempt'];
    }

    public function vatExempt(): bool
    {
        return (bool) $this->all()['vat_exempt'];
    }

    public function vatExemptionThreshold(): float
    {
        return (float) $this->all()['vat_exemption_threshold'];
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

    public function currentBankBalance(): ?float
    {
        $value = $this->all()['current_bank_balance'];

        return $value === null ? null : (float) $value;
    }

    public function currentBankBalanceOn(): ?CarbonImmutable
    {
        return $this->date('current_bank_balance_on');
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->all()[$key];

        return blank($value) ? null : CarbonImmutable::parse($value)->startOfDay();
    }
}
