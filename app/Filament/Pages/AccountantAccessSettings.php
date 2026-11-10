<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class AccountantAccessSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.accountant-review';

    protected static ?string $slug = 'acesso-de-contabilistas';

    protected static ?string $title = 'Acesso de contabilistas';

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Users;

    public static function canAccess(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grant')
                ->label('Conceder acesso')
                ->modalDescription('Indica o email de um utilizador registado e com email confirmado.')
                ->visible(fn (): bool => static::canAccess() && CurrentAccount::get()?->hasAccess())
                ->schema([TextInput::make('email')->label('Email')->email()->required()->maxLength(255)])
                ->action(function (array $data): void {
                    try {
                        app(AccountantAccess::class)->grant($data['email']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            $this->getMountedActionSchema()->getStatePath().'.email' => $exception->errors()['email'],
                        ]);
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        return $table
            ->query(CurrentAccount::get()->users()->wherePivot('role', AccountantAccess::ROLE)->getQuery()->select('users.*'))
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('access_since')
                    ->label('Acesso desde')
                    ->state(fn (User $record) => CurrentAccount::get()->users()->whereKey($record->getKey())->first()?->pivot->created_at)
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Nenhum contabilista com acesso')
            ->recordActions([
                Action::make('revoke')
                    ->label('Revogar acesso')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => app(AccountantAccess::class)->revoke((int) $record->getKey())),
            ]);
    }
}
