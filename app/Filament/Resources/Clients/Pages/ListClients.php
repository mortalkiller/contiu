<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Resources\Clients\Widgets\ClientsSummary;
use App\Models\Client;
use App\Models\Receivable;
use App\Support\InvoiceXpressContactSync;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** "Para quem trabalhas. 3 clientes · 910,00 € por receber." */
    public function getSubheading(): string
    {
        $clientIds = Client::query()->pluck('id')->all();
        $outstanding = ClientsTable::totals($this, $clientIds)['outstanding_amount'];

        return __('Who you work for. :clients · :amount awaiting payment.', [
            'clients' => trans_choice('{0} No clients|{1} 1 client|[2,*] :count clients', count($clientIds)),
            'amount' => Money::format($outstanding),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [ClientsSummary::class];
    }

    /**
     * As in the mock: every client, the ones that owe something, the ones already late, and the
     * ones with nothing due this year.
     */
    public function getTabs(): array
    {
        $open = Receivable::query()->open()->excludingCancelled()->with('billable')->get();
        $owing = $open->map(fn (Receivable $receivable) => $receivable->billable?->client_id)->filter()->unique()->values()->all();
        $late = $open->filter(fn (Receivable $receivable): bool => $receivable->due_on->isBefore(today()))
            ->map(fn (Receivable $receivable) => $receivable->billable?->client_id)->filter()->unique()->values()->all();
        $active = Receivable::query()->excludingCancelled()->whereYear('due_on', today()->year)->with('billable')->get()
            ->map(fn (Receivable $receivable) => $receivable->billable?->client_id)->filter()->unique()->values()->all();

        return [
            'all' => Tab::make(__('All'))->badge(Client::query()->count() ?: null),
            'owing' => Tab::make(__('With amounts awaiting payment'))
                ->badge(count($owing) ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('clients.id', $owing)),
            'late' => Tab::make(__('Late'))
                ->badge(count($late) ?: null)->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('clients.id', $late)),
            'inactive' => Tab::make(__('No activity this year'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('clients.id', $active)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('Export to Excel'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->exports([ClientsTable::excelExport()]),
            Action::make('importInvoiceXpressClient')
                ->label(__('Import from InvoiceXpress'))
                ->color('gray')
                ->visible(fn (): bool => InvoiceXpressContactSync::available())
                ->modalHeading(__('Import client from InvoiceXpress'))
                ->modalDescription(__('If the tax ID already exists in Contiu, the fields filled in on InvoiceXpress update the existing record.'))
                ->modalSubmitActionLabel(__('Import client'))
                ->schema([TextInput::make('nif')->label(__('Portuguese tax ID'))->required()->maxLength(24)])
                ->action(function (array $data, InvoiceXpressContactSync $sync, Action $action): void {
                    try {
                        $sync->pull($data['nif']);
                    } catch (InvoiceXpressException|ValidationException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title(__('Client synced to Contiu'))->send();
                }),
            CreateAction::make()->label(__('New client'))->icon(Phosphor::Plus)->modalWidth('2xl')->databaseTransaction(),
        ];
    }
}
