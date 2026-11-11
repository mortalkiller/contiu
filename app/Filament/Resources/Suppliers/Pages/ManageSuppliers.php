<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Filament\Resources\Suppliers\Widgets\SuppliersBySpend;
use App\Models\Supplier;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** "A quem pagas. 4 fornecedores com despesas em 2026." */
    public function getSubheading(): string
    {
        $withExpenses = Supplier::query()->whereHas('expenses', fn ($query) => $query->whereYear('spent_on', today()->year))->count();

        return __('Who you pay. :suppliers with expenses in :year.', [
            'suppliers' => trans_choice('{0} No suppliers|{1} 1 supplier|[2,*] :count suppliers', $withExpenses),
            'year' => today()->year,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('Export to Excel'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->exports([SuppliersTable::excelExport()]),
            CreateAction::make()->label(__('New supplier')),
        ];
    }

    /**
     * As in the mock: the list, with "Onde gastas mais" in a narrower column beside it (about
     * 70/30), stacked on smaller screens.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 7])->schema([
                Group::make([
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->columnSpan(['default' => 1, 'xl' => 5]),
                Livewire::make(SuppliersBySpend::class)
                    ->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }
}
