<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Actions\GenerateRetainerPeriods;
use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRetainer extends CreateRecord
{
    use HasSaveBar;

    protected static string $resource = RetainerResource::class;

    /** The periods already due (and the next 30 days) appear straight away, not only after the daily run. */
    protected function afterCreate(): void
    {
        app(GenerateRetainerPeriods::class)->forRetainer($this->record);
    }

    public function getTitle(): string
    {
        return __('New retainer');
    }
}
