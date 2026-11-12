<?php

namespace App\Filament\Pages\Auth;

use App\Support\TrustedDevice;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The sign-in's two-step challenge (Login), skipped on a device the user asked to trust.
 */
class TrustedDeviceChallenge extends MultiFactorChallenge
{
    public function getFirstEnabledProvider(Authenticatable $user): ?MultiFactorAuthenticationProvider
    {
        if (app(TrustedDevice::class)->isTrusted($user)) {
            return null;
        }

        return parent::getFirstEnabledProvider($user);
    }
}
