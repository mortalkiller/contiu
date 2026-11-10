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
            ->label('Descarregar PDF')
            ->icon(Phosphor::DownloadSimple)
            ->visible(fn (FiscalDocument $record): bool => $record->currentPdf !== null && FiscalDocumentResource::canView($record))
            ->url(fn (FiscalDocument $record): string => FiscalDocumentResource::getUrl('download-pdf', ['record' => $record]));
    }

    public static function retrieve(): Action
    {
        return Action::make('retrieveOfficialPdf')
            ->label('Obter PDF do InvoiceXpress')
            ->icon(Phosphor::FilePdf)
            ->authorize(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
            ->visible(function (FiscalDocument $record): bool {
                $snapshot = $record->invoiceXpressSnapshot;

                return $snapshot !== null
                    && once(fn (): ?string => InvoiceXpressConfig::current()?->subdomain) === $snapshot->subdomain;
            })
            ->requiresConfirmation()
            ->modalDescription('O PDF oficial será guardado neste documento fiscal. As versões anteriores ficam guardadas.')
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
                    Notification::make()->success()->title('PDF oficial guardado')->send();
                } else {
                    Notification::make()->info()
                        ->title('O InvoiceXpress ainda está a preparar o PDF.')
                        ->body('Volta a pedir o PDF daqui a pouco.')
                        ->send();
                }
            });
    }
}
