<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

class EditProject extends EditRecord
{
    use HasPageHeader;
    use HasSaveBar;

    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->receivables()->whereHas('payments')->exists()),
        ];
    }
}
