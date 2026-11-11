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
            ->label(__('Cancel in InvoiceXpress'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel invoice in InvoiceXpress'))
            ->modalDescription(fn (FiscalDocument $record): string => __('Confirm cancellation of invoice :number for :amount. The reason will be kept in InvoiceXpress.', ['number' => $record->number, 'amount' => Money::format($record->total_amount, locale: app()->getLocale())]))
            ->modalSubmitActionLabel(__('Confirm cancellation'))
            ->visible(fn (FiscalDocument $record): bool => app(InvoiceXpressCancellation::class)->canCancel($record))
            ->fillForm(fn (FiscalDocument $record): array => ['sourceHash' => $record->invoiceXpressSnapshot?->source_hash])
            ->schema([
                TextInput::make('reason')->label(__('Cancellation reason'))->required()->maxLength(InvoiceXpressCancellation::MAX_REASON_LENGTH),
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
            ->successNotificationTitle(__('Invoice cancellation confirmed in InvoiceXpress'));
    }
}
