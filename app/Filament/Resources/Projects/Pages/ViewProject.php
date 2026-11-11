<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

class ViewProject extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->color('gray'),
            Action::make('issueInvoice')
                ->label(__('Prepare invoice'))
                ->color('gray')
                ->url(fn (): string => InvoiceXpressIssuePage::getUrl())
                ->visible(fn (): bool => $this->record->outstanding_amount > 0 && InvoiceXpressIssuePage::canAccess()),
            RecordPaymentAction::make()->after(fn () => $this->record->refresh()),
        ];
    }
}
