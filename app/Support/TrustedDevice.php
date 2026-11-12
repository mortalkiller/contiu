<?php

namespace App\Support;

use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cookie;
use Mortalkiller\FilamentCompleteUserProfile\Contracts\HasMultiFactorAuthentication;

/**
 * "Não pedir de novo neste dispositivo durante 30 dias" on the two-step sign-in. A cookie per
 * user, signed with the app key over the user, the expiry, the password hash and the two-step
 * set-up: changing the password, or turning two-step verification off and on, stops trusting
 * every device. The password is still asked every time.
 */
final class TrustedDevice
{
    public const int DAYS = 30;

    private const string COOKIE_PREFIX = 'contiu_trusted_device_';

    public function isTrusted(Authenticatable $user): bool
    {
        $value = request()->cookie($this->cookieName($user));

        if (! is_string($value) || ! str_contains($value, '.')) {
            return false;
        }

        [$expires, $signature] = explode('.', $value, 2);

        return ctype_digit($expires)
            && (int) $expires > now()->getTimestamp()
            && hash_equals($this->signature($user, (int) $expires), $signature);
    }

    public function trust(Authenticatable $user): void
    {
        $expires = now()->addDays(self::DAYS)->getTimestamp();

        Cookie::queue(Cookie::make(
            $this->cookieName($user),
            $expires.'.'.$this->signature($user, $expires),
            minutes: self::DAYS * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public function cookieName(Authenticatable $user): string
    {
        return self::COOKIE_PREFIX.substr(hash('sha256', Filament::getUserScopedAuthIdentifier($user)), 0, 16);
    }

    private function signature(Authenticatable $user, int $expires): string
    {
        return hash_hmac('sha256', implode('|', [
            Filament::getUserScopedAuthIdentifier($user),
            $expires,
            $user->getAuthPassword(),
            $user instanceof HasMultiFactorAuthentication ? (string) $user->getAppAuthenticationSecret() : '',
            $user instanceof HasEmailAuthentication && $user->hasEmailAuthentication() ? 'email' : '',
        ]), (string) config('app.key'));
    }
}
