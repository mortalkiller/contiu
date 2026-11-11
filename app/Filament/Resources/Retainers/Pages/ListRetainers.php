<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Retainers\Tables\RetainersTable;
use App\Filament\Resources\Retainers\Widgets\RetainersAhead;
use App\Filament\Resources\Retainers\Widgets\RetainersSummary;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ListRetainers extends ListRecords
{
    protected static string $resource = RetainerResource::class;

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): string
    {
        return __('Amounts that repeat. Contiu creates the amount to receive for each period.');
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('Export to Excel'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->exports([RetainersTable::excelExport()]),
            CreateAction::make()
                ->label(__('New retainer'))
                ->icon(Phosphor::Plus),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [RetainersSummary::class];
    }

    protected function getFooterWidgets(): array
    {
        return [RetainersAhead::class];
    }

    public function getTabs(): array
    {
        $count = fn (bool $active): ?int => RetainerResource::getModel()::query()->where('is_active', $active)->count() ?: null;

        return [
            'active' => Tab::make(__('Active retainers'))->badge($count(true))->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),
            'ended' => Tab::make(__('Ended retainers'))->badge($count(false))->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'all' => Tab::make(__('All retainers')),
        ];
    }
}
