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
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'utilizador';

    protected static ?string $pluralModelLabel = 'utilizadores';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::UserGear;

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label(fn (string $operation) => $operation === 'create' ? 'Palavra-passe' : 'Nova palavra-passe')
                ->helperText(fn (string $operation) => $operation === 'create' ? null : 'Deixa em branco para manter a atual.')
                ->password()
                ->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->rule(Password::min(8))
                ->confirmed()
                ->dehydrated(fn (?string $state) => filled($state)),
            TextInput::make('password_confirmation')
                ->label('Confirmar palavra-passe')
                ->password()
                ->revealable()
                ->required(fn (string $operation, $get) => $operation === 'create' || filled($get('password')))
                ->dehydrated(false),
            Select::make('roles')
                ->label('Papéis')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->columnSpanFull()
                ->rule(fn (?User $record): Closure => static::keepsASuperAdmin($record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->description(fn (User $record) => $record->email)
                    ->weight('medium')
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Papéis')
                    ->badge()
                    ->color(fn (string $state) => $state === static::superAdminRole() ? 'primary' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()->modalHeading('Editar utilizador'),
                DeleteAction::make()
                    ->hidden(fn (User $record) => $record->is(auth()->user()) || static::isLastSuperAdmin($record)),
            ])
            ->emptyStateHeading('Sem utilizadores');
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

    /**
     * The app always needs someone who can manage everything: refuse to drop the super admin role
     * from its last holder.
     */
    protected static function keepsASuperAdmin(?User $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || ! static::isLastSuperAdmin($record)) {
                return;
            }

            $superAdminId = Role::query()->where('name', static::superAdminRole())->value('id');

            if (! in_array((string) $superAdminId, array_map('strval', (array) $value), true)) {
                $fail('É preciso manter pelo menos um utilizador com o papel '.static::superAdminRole().'.');
            }
        };
    }
}
