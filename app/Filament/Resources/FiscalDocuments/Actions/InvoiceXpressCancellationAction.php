<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Models\FiscalDocument;
use App\Support\InvoiceXpressCancellation;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressCancellationAction
{
    public static function make(): Action
    {
        return Action::make('cancelInvoiceXpress')
            ->label('Cancelar no InvoiceXpress')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancelar fatura no InvoiceXpress')
            ->modalDescription(fn (FiscalDocument $record): string => 'Confirma o cancelamento da fatura '.$record->number.' de '.Money::format($record->total_amount).'. O motivo fica no InvoiceXpress.')
            ->modalSubmitActionLabel('Confirmar cancelamento')
            ->visible(fn (FiscalDocument $record): bool => app(InvoiceXpressCancellation::class)->canCancel($record))
            ->fillForm(fn (FiscalDocument $record): array => ['sourceHash' => $record->invoiceXpressSnapshot?->source_hash])
            ->schema([
                TextInput::make('reason')->label('Motivo do cancelamento')->required()->maxLength(InvoiceXpressCancellation::MAX_REASON_LENGTH),
                Hidden::make('sourceHash')->required()->regex('/\A[a-f0-9]{64}\z/'),
            ])
            ->action(function (FiscalDocument $record, array $data, Schema $schema): void {
                try {
                    app(InvoiceXpressCancellation::class)->cancel($record->id, $data['reason'], $data['sourceHash']);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(
                        Arr::prependKeysWith($exception->errors(), $schema->getStatePath().'.'),
                    );
                }
            })
            ->successNotificationTitle('Fatura cancelada e confirmada no InvoiceXpress');
    }
}
