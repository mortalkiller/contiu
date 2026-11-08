<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Must verify the email before opening the panel (->emailVerification()). Users from before the
 * public sign-up were marked verified by the accounts migration.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** @return BelongsToMany<Account, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Super admins (Shield's global role) support every account. The role is only ever given
     * by command, never from the Users screen.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole((string) config('filament-shield.super_admin.name', 'super_admin'));
    }

    /** For the system pages (users, roles, audits, backups, Excel import): super admins only. */
    public static function loggedInIsSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof self && $user->isSuperAdmin();
    }

    /** @return Collection<int, Account> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->isSuperAdmin()
            ? Account::query()->orderBy('name')->get()
            : $this->accounts()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Account
            && ($this->isSuperAdmin() || $this->accounts()->whereKey($tenant->getKey())->exists());
    }

    /** "/" opens the user's own account first, even for a super admin who can open them all. */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->accounts()->orderBy('accounts.id')->first();
    }

    /**
     * The panel's own confirmation email: Laravel's default links to a "verification.verify"
     * route this app does not have; Filament's verify route opens the user's account afterwards.
     */
    public function sendEmailVerificationNotification(): void
    {
        $notification = app(VerifyEmail::class);
        $notification->url = Filament::getVerifyEmailUrl($this);

        $this->notify($notification);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'terms_accepted_at' => 'datetime',
        ];
    }
}
