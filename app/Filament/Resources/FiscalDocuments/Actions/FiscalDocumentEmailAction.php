<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Actions\SendFiscalDocumentByEmail;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Enviar por email": an issued document to the client, with its PDF attached. The address comes
 * from the client and can be changed for this email; the message is optional.
 */
final class FiscalDocumentEmailAction
{
    public static function make(): Action
    {
        return Action::make('sendByEmail')
            ->label(__('Send by email'))
            ->icon(Phosphor::PaperPlaneTilt)
            ->color('gray')
            ->authorize(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
            ->visible(fn (FiscalDocument $record): bool => SendFiscalDocumentByEmail::canSend($record)
                && ($record->currentPdf !== null || $record->integrationRecord !== null))
            ->modalHeading(fn (FiscalDocument $record): string => __('Send :number by email', ['number' => FiscalDocumentPresenter::title($record)]))
            ->modalDescription(__('The client receives the document with its PDF attached.'))
            ->modalIcon(Phosphor::PaperPlaneTilt)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('Send'))
            ->fillForm(fn (FiscalDocument $record): array => ['to' => $record->client?->email])
            ->schema([
                TextInput::make('to')->label(__('To'))->email()->required()->maxLength(255),
                Textarea::make('message')->label(__('Message'))->rows(5)->maxLength(2000)
                    ->placeholder(__('Optional. Without one, the email only presents the document.')),
            ])
            ->action(function (FiscalDocument $record, array $data, Action $action): void {
                FiscalDocumentResource::authorizeEdit($record);

                try {
                    app(SendFiscalDocumentByEmail::class)->handle($record, $data['to'], $data['message'] ?? null);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('Sent to :email', ['email' => $data['to']]))->send();
            });
    }
}
