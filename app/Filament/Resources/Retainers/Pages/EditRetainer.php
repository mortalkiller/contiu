<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Actions\GenerateRetainerPeriods;
use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

class EditRetainer extends EditRecord
{
    use HasPageHeader;
    use HasSaveBar;

    protected static string $resource = RetainerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->receivables()->whereHas('payments')->exists()),
        ];
    }

    /** Re-activating a retainer or moving its start date catches up on the periods now due. */
    protected function afterSave(): void
    {
        app(GenerateRetainerPeriods::class)->forRetainer($this->record);
    }
}
