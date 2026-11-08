<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * "Perfil da conta": the account's own name and NIF (the NIF is what the subscription invoice and
 * the quarterly declaration need).
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
        ]);
    }
}
