<?php

namespace App\Filament\Support;

use App\Enums\Language;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * The accountants with access to the account: who they are, since when, and the ways to grant
 * and revoke it. Shared by the "Acesso de contabilistas" page and the card on "Assinatura".
 */
final class AccountantAccessTable
{
    public static function canManage(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
    }

    public static function configure(Table $table): Table
    {
        abort_unless(self::canManage(), 403);

        return $table
            ->query(CurrentAccount::get()->users()->wherePivot('role', AccountantAccess::ROLE)->getQuery()->select('users.*'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('medium')->searchable(),
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
                    ->link()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => app(AccountantAccess::class)->revoke((int) $record->getKey())),
            ]);
    }

    public static function grantAction(): Action
    {
        return Action::make('grant')
            ->label(__('Grant access'))
            ->color('gray')
            ->modalDescription(__('Enter the email address of a registered user who has verified their email.'))
            ->visible(fn (): bool => self::canManage() && CurrentAccount::get()?->hasAccess())
            ->schema([TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)])
            ->action(function (array $data, HasActions $livewire): void {
                try {
                    app(AccountantAccess::class)->grant($data['email']);
                } catch (ValidationException $exception) {
                    // The modal's own state path, read from inside the page or widget that mounted it.
                    $statePath = (fn (): string => $this->getMountedActionSchema()->getStatePath())->call($livewire);

                    throw ValidationException::withMessages([$statePath.'.email' => $exception->errors()['email']]);
                }
            });
    }
}
