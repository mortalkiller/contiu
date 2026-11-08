<?php

namespace App\Filament\Pages\Tenancy;

use App\Actions\CreateAccount;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
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
        return 'Perfil da conta';
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
                ->label('Nome')
                ->required()
                ->maxLength(255),
            TextInput::make('nif')
                ->label('NIF')
                ->rule(new PortugueseNif)
                ->unique(ignoreRecord: true)
                // Accounts migrated from before multi-account (billing exempt) may not have one yet.
                ->required(fn (?Account $record): bool => ! $record?->billing_exempt),
            // Changing it breaks every saved link to the account, so it is for support only (e.g.
            // the migrated account named "conta-principal" when the release ran with cached config).
            // Hidden fields are not saved, so an owner cannot send one either.
            TextInput::make('slug')
                ->label('Endereço')
                ->prefix(url('/').'/')
                ->helperText('Só minúsculas, números e hífenes. Os links antigos para a conta deixam de funcionar.')
                ->visible(fn (): bool => User::loggedInIsSuperAdmin())
                ->required()
                ->maxLength(CreateAccount::SLUG_MAX_LENGTH)
                ->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')
                ->validationMessages(['regex' => 'Usa só minúsculas, números e hífenes (sem espaços nem acentos).'])
                ->rule(fn (?Account $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if (is_string($value) && ! app(CreateAccount::class)->slugIsAvailable($value, $record)) {
                        $fail('Este endereço não está disponível.');
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
