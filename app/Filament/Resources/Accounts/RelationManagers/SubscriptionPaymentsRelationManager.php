<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * The account's paid subscription invoices, to tick off once the legal invoice is issued
 * (outside the app). Super admins only, like the accounts list.
 */
class SubscriptionPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptionPayments';

    protected static ?string $title = 'Pagamentos da assinatura';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')->label('Data')->date(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('period')
                    ->label('Período')
                    ->state(fn (SubscriptionPayment $record): string => $record->periodLabel())
                    ->placeholder('—'),
                TextColumn::make('address')
                    ->label('Morada')
                    ->state(fn (SubscriptionPayment $record): string => $record->addressLine())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('stripe_invoice_id')->label('Fatura Stripe')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoiced_at')
                    ->label('Faturado')
                    ->date()
                    ->placeholder('Por faturar'),
            ])
            ->recordActions([
                Action::make('markInvoiced')
                    ->label('Marcar como faturado')
                    ->icon(Phosphor::CheckCircle)
                    ->requiresConfirmation()
                    ->modalDescription('Confirma que já emitiste a fatura deste pagamento.')
                    ->visible(fn (SubscriptionPayment $record): bool => $record->invoiced_at === null)
                    ->action(fn (SubscriptionPayment $record) => $record->update(['invoiced_at' => now()])),
            ]);
    }
}
