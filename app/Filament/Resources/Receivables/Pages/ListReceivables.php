<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Receivables\Tables\ReceivablesTable;
use App\Filament\Resources\Receivables\Widgets\ReceivablesByClient;
use App\Filament\Resources\Receivables\Widgets\ReceivablesSummary;
use App\Models\Receivable;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    /** "A receber", as in the menu and the mock. */
    public function getTitle(): string
    {
        return __('To receive');
    }

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** "4 amounts outstanding, from 3 clients. They come from projects and retainers." */
    public function getSubheading(): string
    {
        $open = Receivable::query()->open()->excludingCancelled()->with('billable')->get();

        return __(':amounts outstanding, from :clients. They come from projects and retainers.', [
            'amounts' => trans_choice('{0} No amounts|{1} 1 amount|[2,*] :count amounts', $open->count()),
            'clients' => trans_choice('{0} no clients|{1} 1 client|[2,*] :count clients', $open->map(fn (Receivable $receivable) => $receivable->billable?->client_id)->filter()->unique()->count()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('Export to Excel'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->exports([ReceivablesTable::excelExport()]),
            RecordPaymentAction::make()
                ->icon(null)
                ->after(fn () => $this->resetTable()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ReceivablesSummary::class];
    }

    /**
     * As in the mock: the tabs, then the list with "Quem deve" in a narrower column beside it
     * (about 70/30), stacked on smaller screens.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            Grid::make(['default' => 1, 'xl' => 7])->schema([
                Group::make([
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->columnSpan(['default' => 1, 'xl' => 5]),
                Livewire::make(ReceivablesByClient::class)
                    ->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    public function getTabs(): array
    {
        $count = fn (callable $scope) => $scope(Receivable::query()->excludingCancelled())->count() ?: null;

        $overdue = fn (Builder $query) => $query->open()->whereDate('due_on', '<', today());
        $next30 = fn (Builder $query) => $query->open()->whereBetween('due_on', [today(), today()->addDays(30)]);
        $open = fn (Builder $query) => $query->open();

        return [
            'overdue' => Tab::make(__('Overdue receivables'))->modifyQueryUsing($overdue)->badge($count($overdue))->badgeColor('danger'),
            'next30' => Tab::make(__('Next 30 days'))->modifyQueryUsing($next30)->badge($count($next30))->badgeColor('warning'),
            'open' => Tab::make(__('Outstanding'))->modifyQueryUsing($open)->badge($count($open)),
            'paid' => Tab::make(__('Paid receivables'))->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('receivables.id', Receivable::query()->open()->select('id'))),
            'all' => Tab::make(__('All')),
        ];
    }
}
