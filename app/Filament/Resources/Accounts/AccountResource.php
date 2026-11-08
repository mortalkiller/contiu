<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\SubscriptionPaymentsRelationManager;
use App\Models\Account;
use App\Models\User;
use App\Support\AccountStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Contas": every account, for the super admins — who signed up, how each one pays, and the
 * subscription payments still to invoice. Read-only here: owners edit their account in
 * "Perfil da conta"; billing lives in Stripe.
 */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'contas';

    protected static ?string $modelLabel = 'conta';

    protected static ?string $pluralModelLabel = 'contas';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Buildings;

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static ?int $navigationSort = 4;

    /** Accounts are the tenants themselves: the list spans all of them. */
    protected static bool $isScopedToTenant = false;

    /** Every account's name, NIF, owner and payments: super admins only (no policy, no Shield permission). */
    public static function canAccess(): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public static function canViewAny(): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public static function canView(Model $record): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['md' => 3])
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name')->label('Nome'),
                    TextEntry::make('nif')->label('NIF')->placeholder('—'),
                    TextEntry::make('owner_email')
                        ->label('Email do dono')
                        ->state(fn (Account $record): ?string => static::ownerEmail($record))
                        ->placeholder('—'),
                    TextEntry::make('billing_state')
                        ->label('Estado')
                        ->state(fn (Account $record): string => AccountStatus::label($record))
                        ->badge()
                        ->color(fn (string $state): string => static::stateColor($state)),
                    TextEntry::make('created_at')->label('Registo')->date(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['users', 'subscriptions']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nif')
                    ->label('NIF')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('owner_email')
                    ->label('Email do dono')
                    ->state(fn (Account $record): ?string => static::ownerEmail($record))
                    ->placeholder('—'),
                TextColumn::make('billing_state')
                    ->label('Estado')
                    ->state(fn (Account $record): string => AccountStatus::label($record))
                    ->badge()
                    ->color(fn (string $state): string => static::stateColor($state)),
                TextColumn::make('created_at')
                    ->label('Registo')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Filament takes the tenant from the URL, so this simply opens that account.
                Action::make('enter')
                    ->label('Entrar na conta')
                    ->icon(Phosphor::SignIn)
                    ->url(fn (Account $record): string => Dashboard::getUrl(tenant: $record)),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SubscriptionPaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'view' => ViewAccount::route('/{record}'),
        ];
    }

    /** From the eager-loaded members, so the list makes no query per row. */
    private static function ownerEmail(Account $record): ?string
    {
        return $record->users->first(fn (User $user) => $user->pivot->role === 'owner')?->email;
    }

    private static function stateColor(string $state): string
    {
        return match (true) {
            $state === 'Só leitura' => 'warning',
            $state === 'Isenta' => 'gray',
            str_starts_with($state, 'Experiência') => 'info',
            default => 'success',
        };
    }
}
