<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Support\OutstandingColumn;
use App\Models\Client;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder as QueryBuilder;
use MortalKiller\FilamentAppearance\Tables\Columns\ProgressColumn;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use WeakMap;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label(__('Export selected'))
                    ->exports([static::excelExport()]),
            ])
            ->modifyQueryUsing(fn ($query) => $query->withCount(['projects', 'retainers'])->withReceivableTotals())
            ->defaultSort('name')
            ->searchPlaceholder(__('Name or tax ID'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Client'))
                    ->description(fn (Client $record) => $record->vat_number ? __('Tax ID :number', ['number' => $record->vat_number]) : $record->email)
                    ->weight('medium')
                    ->searchable(['name', 'vat_number', 'email'])
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label(__('Projects'))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : '—')
                    ->color(fn (int $state): ?string => $state > 0 ? null : 'gray')
                    ->alignEnd(),
                TextColumn::make('retainers_count')
                    ->label(__('Retainers'))
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? (string) $state : '—')
                    ->color(fn (int $state): ?string => $state > 0 ? null : 'gray')
                    ->alignEnd(),
                TextColumn::make('contracted_amount')
                    ->label(__('Contracted'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(static::sumOf('contracted_amount')),
                // How much of what was contracted has come in, as a bar; nothing contracted, no bar.
                ProgressColumn::make('received_share')
                    ->label(__('Received'))
                    ->state(fn (Client $record): ?float => $record->contracted_amount > 0 ? $record->received_amount / $record->contracted_amount * 100 : null)
                    ->placeholder('—')
                    ->precision(0)
                    ->color(fn (?float $state): string => $state >= 100 ? 'success' : 'primary'),
                OutstandingColumn::make()
                    ->label(__('Awaiting payment'))
                    ->summarize(static::sumOf('outstanding_amount')),
                TextColumn::make('overdue_amount')
                    ->label(__('Past due'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight(fn (int $state): ?FontWeight => $state > 0 ? FontWeight::SemiBold : null)
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—')
                    ->summarize(static::sumOf('overdue_amount')),
            ])
            ->recordActions([
                RecordPaymentAction::rowButton()
                    ->visible(fn (Client $record): bool => $record->outstanding_amount > 0),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No clients'));
    }

    /**
     * The total of an amount worked out from each client's receivables, over the clients the
     * table shows, for the foot of the table.
     */
    public static function sumOf(string $attribute): Summarizer
    {
        return Summarizer::make('sum')
            ->money('EUR', divideBy: 100)
            ->using(fn (QueryBuilder $query, HasTable $livewire): int => static::totals($livewire, $query->pluck('clients.id')->all())[$attribute]);
    }

    /**
     * What a set of clients add up to, read once per page load however many totals ask for it
     * (the three at the foot of the table, the heading's "awaiting payment").
     *
     * @param  list<int>  $clientIds
     * @return array{contracted_amount: int, outstanding_amount: int, overdue_amount: int}
     */
    public static function totals(object $livewire, array $clientIds): array
    {
        static $memo = null;
        $memo ??= new WeakMap;

        sort($clientIds);
        $key = implode(',', $clientIds);
        $cached = $memo[$livewire] ?? [];

        if (! isset($cached[$key])) {
            $clients = Client::query()->whereIn('id', $clientIds)->withReceivableTotals()->get();

            $cached[$key] = [
                'contracted_amount' => (int) $clients->sum('contracted_amount'),
                'outstanding_amount' => (int) $clients->sum('outstanding_amount'),
                'overdue_amount' => (int) $clients->sum('overdue_amount'),
            ];
            $memo[$livewire] = $cached;
        }

        return $cached[$key];
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->except(['received_share'])
            ->withFilename(fn () => str(__('Clients'))->slug().'-'.now()->format('Y-m-d'));
    }
}
