<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\CreateAccount;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Support\PanelLocation;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * "Perfil da conta": the account's own name and NIF (the NIF is what the subscription invoice and
 * the quarterly declaration need). Super admins can also change its address (the slug in every URL).
 */
class EditAccountProfile extends EditTenantProfile
{
    protected static ?string $slug = 'perfil-da-conta';

    public static function getLabel(): string
    {
        return __('Account profile');
    }

    /** Only the account's owners, and super admins giving support. */
    public static function canView(Model $tenant): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $tenant instanceof Account) {
            return false;
        }

        return $user->isSuperAdmin()
            || $tenant->users()->whereKey($user->getKey())->wherePivot('role', 'owner')->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('nif')
                ->label(__('Tax ID'))
                ->rule(new PortugueseNif)
                ->unique(ignoreRecord: true)
                // Accounts migrated from before multi-account (billing exempt) may not have one yet.
                ->required(fn (?Account $record): bool => ! $record?->billing_exempt),
            // Changing it breaks every saved link to the account, so it is for support only (e.g.
            // the migrated account named "conta-principal" when the release ran with cached config).
            // Hidden fields are not saved, so an owner cannot send one either.
            TextInput::make('slug')
                ->label(__('Account address'))
                ->prefix(PanelLocation::url().'/')
                ->helperText(__('Use only lowercase letters, numbers and hyphens. Old links to the account will stop working.'))
                ->visible(fn (): bool => User::loggedInIsSuperAdmin())
                ->required()
                ->maxLength(CreateAccount::SLUG_MAX_LENGTH)
                ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->validationMessages(['regex' => __('Use only lowercase letters, numbers and hyphens (no spaces or accents).')])
                ->rule(fn (?Account $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if (is_string($value) && ! app(CreateAccount::class)->slugIsAvailable($value, $record)) {
                        $fail(__('This address is unavailable.'));
                    }
                }),
        ]);
    }

    /** A new address: the page reopens there (the current URL names the old one). */
    protected function getRedirectUrl(): ?string
    {
        return $this->tenant->wasChanged('slug') ? static::getUrl(tenant: $this->tenant) : null;
    }
}
