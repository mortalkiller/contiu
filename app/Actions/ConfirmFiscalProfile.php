<?php

namespace App\Actions;

use App\Models\FiscalProfile;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConfirmFiscalProfile
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input): FiscalProfile
    {
        $account = CurrentAccount::get();

        abort_unless($account && $actor->canAccessTenant($account) && ! CurrentAccount::readOnly(), 403);

        $data = Validator::make($input, [
            'legal_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'regex:/\A[A-Za-z]{2}\z/'],
            'vat_number' => ['required', 'string', 'max:64'],
            'vat_regime' => ['required', 'string', 'max:160'],
            'vat_exempt' => ['nullable', 'in:yes,no'],
            'vat_exemption_threshold' => ['nullable', 'prohibited_unless:vat_exempt,yes', 'integer', 'min:1'],
            'ss_contribution_exempt' => ['nullable', 'in:yes,no'],
            'activity_started_on' => ['nullable', 'date_format:Y-m-d'],
            'has_dependent_employment' => ['nullable', 'in:yes,no'],
            'recognised_exemptions' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'confirmed' => ['accepted'],
        ])->validated();

        return FiscalProfile::query()->create([
            'legal_name' => trim($data['legal_name']),
            'country_code' => Str::upper($data['country_code']),
            'vat_number' => trim($data['vat_number']),
            'vat_regime' => trim($data['vat_regime']),
            'vat_exempt' => $this->confirmedBoolean($data['vat_exempt'] ?? null),
            'vat_exemption_threshold' => $data['vat_exemption_threshold'] ?? null,
            'ss_contribution_exempt' => $this->confirmedBoolean($data['ss_contribution_exempt'] ?? null),
            'activity_started_on' => $data['activity_started_on'] ?? null,
            'has_dependent_employment' => $this->confirmedBoolean($data['has_dependent_employment'] ?? null),
            'recognised_exemptions' => trim($data['recognised_exemptions'] ?? '') ?: null,
            'effective_from' => $data['effective_from'],
            'confirmed_at' => now(),
            'confirmed_by' => $actor->getKey(),
        ]);
    }

    private function confirmedBoolean(?string $value): ?bool
    {
        return match ($value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }
}
