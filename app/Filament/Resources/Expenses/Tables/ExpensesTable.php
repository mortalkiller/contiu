<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseKind;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Excel')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'despesas-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('spent_on', 'desc')
            ->columns([
                TextColumn::make('spent_on')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (Expense $record) => $record->supplier->name),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->description(fn (Expense $record) => $record->category?->name)
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('cost_centre')
                    ->label('Imputada a')
                    ->placeholder('—'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('EUR')
                    ->alignEnd()
                    ->summarize(Summarizer::make()
                        ->label('Total')
                        ->money('EUR')
                        ->using(fn ($query) => $query->get()->sum(fn ($row) => round((float) $row->net_amount * (1 + (float) $row->vat_rate), 2)))),
                IconColumn::make('is_paid')
                    ->label('Paga')
                    ->boolean(),
                TextColumn::make('method')
                    ->label('Método')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('invoice_number')
                    ->label('Fatura')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('spent_on')
                    ->label('Mês')
                    ->getKeyFromRecordUsing(fn (Expense $record) => $record->spent_on->format('Y-m'))
                    ->getTitleFromRecordUsing(fn (Expense $record) => ucfirst($record->spent_on->locale('pt')->translatedFormat('F Y')))
                    ->groupQueryUsing(fn (Builder $query) => $query->groupByRaw(static::monthExpression()))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('spent_on', $direction))
                    ->scopeQueryByKeyUsing(function (Builder $query, ?string $key) {
                        $month = Carbon::createFromFormat('Y-m', (string) $key);

                        return $query->whereYear('spent_on', $month->year)->whereMonth('spent_on', $month->month);
                    })
                    ->collapsible(),
                Group::make('supplier.name')
                    ->label('Fornecedor')
                    ->collapsible(),
                Group::make('category.name')
                    ->label('Categoria')
                    ->collapsible(),
            ])
            ->filters([
                SelectFilter::make('supplier')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'name')
                    ->preload(),
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                    ->preload(),
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(ExpenseKind::class),
                SelectFilter::make('year')
                    ->label('Ano')
                    ->options(fn () => Expense::query()->pluck('spent_on')->map->year->unique()->sortDesc()->mapWithKeys(fn ($year) => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('spent_on', $year))),
                SelectFilter::make('month')
                    ->label('Mês')
                    ->options(collect(range(1, 12))->mapWithKeys(fn (int $month) => [$month => ucfirst(Carbon::create(2000, $month)->locale('pt')->translatedFormat('F'))])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $month) => $query->whereMonth('spent_on', $month))),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label('Marcar como paga')
                    ->icon(Phosphor::CheckCircle)
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Marcar como paga')
                    ->visible(fn (Expense $record) => ! $record->is_paid)
                    ->action(function (Expense $record): void {
                        $record->update(['is_paid' => true, 'paid_on' => today()]);

                        Notification::make()->title('Despesa marcada como paga')->success()->send();
                    }),
                ActionGroup::make([
                    EditAction::make(),
                    ReplicateAction::make()
                        ->label('Duplicar')
                        ->modalDescription('Cria uma cópia com a data de hoje, por pagar, para corrigires o que mudou.')
                        ->beforeReplicaSaved(fn (Model $replica) => $replica->fill([
                            'spent_on' => today(),
                            'is_paid' => false,
                            'paid_on' => null,
                            'invoice_number' => null,
                        ]))
                        ->successNotificationTitle('Despesa duplicada')
                        ->successRedirectUrl(fn (Model $replica) => ExpenseResource::getUrl('edit', ['record' => $replica])),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markPaid')
                        ->label('Marcar como pagas')
                        ->icon(Phosphor::CheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->where('is_paid', false)->each->update(['is_paid' => true, 'paid_on' => today()]);

                            Notification::make()->title('Despesas marcadas como pagas')->success()->send();
                        }),
                    ExportBulkAction::make()
                        ->label('Exportar seleção')
                        ->exports([
                            ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'despesas-'.now()->format('Y-m-d')),
                        ]),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sem despesas')
            ->emptyStateDescription('Regista uma despesa ou cria uma despesa recorrente.');
    }

    protected static function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', spent_on)",
            'pgsql' => "to_char(spent_on, 'YYYY-MM')",
            default => "DATE_FORMAT(spent_on, '%Y-%m')",
        };
    }
}
