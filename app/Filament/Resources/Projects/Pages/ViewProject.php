<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Projects\ProjectResource;
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
            RecordPaymentAction::make()->after(fn () => $this->record->refresh()),
            EditAction::make(),
        ];
    }
}
