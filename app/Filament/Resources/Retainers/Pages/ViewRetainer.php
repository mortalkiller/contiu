<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

class ViewRetainer extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = RetainerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make()->after(fn () => $this->record->refresh()),
            EditAction::make(),
        ];
    }
}
