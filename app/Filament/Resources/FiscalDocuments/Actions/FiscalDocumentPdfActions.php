<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Support\FiscalDocumentPdfs;
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

    public static function retrieve(): Action
    {
        return Action::make('retrieveOfficialPdf')
            ->label(__('Get PDF from InvoiceXpress'))
            ->icon(Phosphor::FilePdf)
            ->authorize(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
            ->visible(function (FiscalDocument $record): bool {
                $snapshot = $record->invoiceXpressSnapshot;

                return $snapshot !== null
                    && once(fn (): ?string => InvoiceXpressConfig::current()?->subdomain) === $snapshot->subdomain;
            })
            ->requiresConfirmation()
            ->modalDescription(__('The official PDF will be saved to this fiscal document. Previous versions are kept.'))
            ->action(function (FiscalDocument $record): void {
                FiscalDocumentResource::authorizeEdit($record);

                try {
                    $stored = app(FiscalDocumentPdfs::class)->retrieveOfficial($record);
                } catch (InvoiceXpressException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                if ($stored) {
                    $record->unsetRelation('currentPdf');
                    Notification::make()->success()->title(__('Official PDF saved'))->send();
                } else {
                    Notification::make()->info()
                        ->title(__('InvoiceXpress is still preparing the PDF.'))
                        ->body(__('Try requesting the PDF again in a moment.'))
                        ->send();
                }
            });
    }
}
