<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class AccountantAccessSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.accountant-review';

    protected static ?string $slug = 'acesso-de-contabilistas';

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
                ->label(__('Grant access'))
                ->modalDescription(__('Enter the email address of a registered user who has verified their email.'))
                ->visible(fn (): bool => static::canAccess() && CurrentAccount::get()?->hasAccess())
                ->schema([TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)])
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
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                TextColumn::make('access_since')
                    ->label(__('Access since'))
                    ->state(fn (User $record) => CurrentAccount::get()->users()->whereKey($record->getKey())->first()?->pivot->created_at)
                    ->date(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No accountants have access'))
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke access'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => app(AccountantAccess::class)->revoke((int) $record->getKey())),
            ]);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Accountant access');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Accountant access');
    }
}
