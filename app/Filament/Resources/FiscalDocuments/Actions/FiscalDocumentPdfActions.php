<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Exceptions\InvoicingProviderException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use ToneGabes\Filament\Icons\Enums\Phosphor;

final class FiscalDocumentPdfActions
{
    public static function download(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('Download PDF'))
            ->icon(Phosphor::DownloadSimple)
            ->visible(fn (FiscalDocument $record): bool => $record->currentPdf !== null && FiscalDocumentResource::canView($record))
            ->url(fn (FiscalDocument $record): string => FiscalDocumentResource::getUrl('download-pdf', ['record' => $record]));
    }

    /** Fetches the official PDF from the connected invoicing provider and keeps it with the document. */
    public static function retrieve(): Action
    {
        return Action::make('retrieveOfficialPdf')
            ->label(fn (): string => __('Get PDF from :provider', ['provider' => Invoicing::providerLabel() ?? '']))
            ->icon(Phosphor::FilePdf)
            ->authorize(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
            ->visible(fn (FiscalDocument $record): bool => ! $record->isDraft() && $record->integrationRecord !== null && Invoicing::connected())
            ->requiresConfirmation()
            ->modalDescription(__('The official PDF will be saved to this fiscal document. Previous versions are kept.'))
            ->action(function (FiscalDocument $record): void {
                FiscalDocumentResource::authorizeEdit($record);

                try {
                    $stored = app(FiscalDocumentPdfs::class)->retrieveFromProvider($record);
                } catch (InvoicingProviderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                if ($stored) {
                    $record->unsetRelation('currentPdf');
                    Notification::make()->success()->title(__('Official PDF saved'))->send();
                } else {
                    Notification::make()->info()
                        ->title(__('The PDF is still being prepared.'))
                        ->body(__('Try requesting the PDF again in a moment.'))
                        ->send();
                }
            });
    }
}
