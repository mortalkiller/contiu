<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::UserGear;

    protected static ?int $navigationSort = 5;

    /** Users are global (one login can belong to several accounts), not rows of an account. */
    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Settings');
    }

    /** Every login of every account is listed here, so only super admins may open it. */
    public static function canAccess(): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('Email'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label(fn (string $operation) => $operation === 'create' ? __('Password') : __('New password'))
                ->helperText(fn (string $operation) => $operation === 'create' ? null : __('Leave blank to keep the current password.'))
                ->password()
                ->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->rule(Password::default())
                ->confirmed()
                ->dehydrated(fn (?string $state) => filled($state)),
            TextInput::make('password_confirmation')
                ->label(__('Confirm password'))
                ->password()
                ->revealable()
                ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                ->dehydrated(false),
            // super_admin opens every account: it is only given by command (RGPD), never here.
            // Hiding it from the query also keeps it on a super admin when their roles are saved.
            Select::make('roles')
                ->label(__('Roles'))
                ->relationship('roles', 'name', fn (Builder $query) => $query->where('name', '!=', static::superAdminRole()))
                ->multiple()
                ->preload()
                ->columnSpanFull()
                ->rule(fn (): Closure => static::refusesSuperAdmin()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->description(fn (User $record) => $record->email)
                    ->weight('medium')
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label(__('Roles'))
                    ->badge()
                    ->color(fn (string $state) => $state === static::superAdminRole() ? 'primary' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('Created on'))
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()->modalHeading(__('Edit user')),
                DeleteAction::make()
                    ->hidden(fn (User $record) => $record->is(auth()->user()) || static::isLastSuperAdmin($record)),
            ])
            ->emptyStateHeading(__('No users'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }

    public static function superAdminRole(): string
    {
        return (string) config('filament-shield.super_admin.name', 'super_admin');
    }

    public static function isLastSuperAdmin(User $user): bool
    {
        return $user->hasRole(static::superAdminRole())
            && User::role(static::superAdminRole())->whereKeyNot($user->getKey())->doesntExist();
    }

    /** The options already leave super_admin out; this also refuses an id posted by hand. */
    protected static function refusesSuperAdmin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $superAdminId = Role::query()->where('name', static::superAdminRole())->value('id');

            if ($superAdminId !== null && in_array((string) $superAdminId, array_map('strval', (array) $value), true)) {
                $fail(__('The :role role can only be assigned from the command line.', ['role' => static::superAdminRole()]));
            }
        };
    }
}
