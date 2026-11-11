<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseKind;
use App\Enums\Language;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Expense;
use App\Support\AnnualFiscalReview;
use App\Support\CurrentAccount;
use App\Support\PeriodSummary;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
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
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Despesas" as in the mock: the date, the supplier with what it was and its category under it,
 * overhead or direct, the total, paid or unpaid, what is missing (the supporting document), and
 * "Mark as paid" on an unpaid one. The rest of the columns can be switched on; the export sits in
 * the page header (ListExpenses) and the selection's actions over the list.
 */
class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            // Rows load right after the page; a skeleton shows meanwhile (filament-skeleton-loading).
            ->deferLoading()
            ->searchPlaceholder(__('Supplier or description'))
            ->defaultSort('spent_on', 'desc')
            ->columns([
                TextColumn::make('spent_on')
                    ->label(fn (): string => __('Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label(fn (): string => __('Expense'))
                    ->description(fn (Expense $record): string => collect([$record->description, $record->category?->name])->filter()->implode(' · '))
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->where('description', 'like', "%{$search}%")
                        ->orWhereRelation('supplier', 'name', 'like', "%{$search}%"))),
                TextColumn::make('kind')
                    ->label(fn (): string => __('Type'))
                    ->badge(),
                TextColumn::make('cost_centre')
                    ->label(fn (): string => __('Charged to'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')
                    ->label(fn (): string => __('Total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(Summarizer::make()
                        ->money('EUR', divideBy: 100)
                        ->using(fn ($query) => $query->get()->sum(fn ($row) => round((float) $row->net_amount * (1 + (float) $row->vat_rate), 2)))),
                TextColumn::make('is_paid')
                    ->label(fn (): string => __('Status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Expense paid') : __('Unpaid'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('missing')
                    ->label(fn (): string => __('Missing'))
                    ->state(fn (Expense $record): ?string => $record->hasProof() ? null : __('Supporting document'))
                    ->color('gray')
                    ->placeholder('—'),
                IconColumn::make('is_reviewed')
                    ->label(fn (): string => __('Reviewed'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attribution')
                    ->label(fn (): string => __('Allocation'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('method')
                    ->label(fn (): string => __('Method'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice_number')
                    ->label(fn (): string => __('Invoice'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('proof_reference')
                    ->label(fn (): string => __('Supporting document reference'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                ReconciliationColumns::expenseColumn()->label(fn (): string => __('Bank'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('spent_on')
                    ->label(__('Month'))
                    ->getKeyFromRecordUsing(fn (Expense $record) => $record->spent_on->format('Y-m'))
                    ->getTitleFromRecordUsing(fn (Expense $record) => ucfirst($record->spent_on->locale(app()->getLocale())->translatedFormat('F Y')))
                    ->groupQueryUsing(fn (Builder $query) => $query->groupByRaw(static::monthExpression()))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('spent_on', $direction))
                    ->scopeQueryByKeyUsing(function (Builder $query, ?string $key) {
                        $month = Carbon::createFromFormat('Y-m', (string) $key);

                        return $query->whereYear('spent_on', $month->year)->whereMonth('spent_on', $month->month);
                    })
                    ->collapsible(),
                Group::make('supplier.name')
                    ->label(__('Supplier'))
                    ->collapsible(),
                Group::make('category.name')
                    ->label(__('Category'))
                    ->collapsible(),
            ])
            ->filters([
                ReconciliationColumns::expenseFilter(),
                SelectFilter::make('supplier')
                    ->label(__('Supplier'))
                    ->relationship('supplier', 'name')
                    ->preload(),
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                    ->preload(),
                SelectFilter::make('kind')
                    ->label(__('Type'))
                    ->options(ExpenseKind::class),
                SelectFilter::make('year')
                    ->label(__('Year'))
                    ->options(fn () => Expense::query()->pluck('spent_on')->map->year->unique()->sortDesc()->mapWithKeys(fn ($year) => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('spent_on', $year))),
                SelectFilter::make('paid_tax_year')
                    ->label(__('Taxes and contributions paid in the year'))
                    ->options(fn (): array => PeriodSummary::availableYears())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $year): Builder => AnnualFiscalReview::taxPayments((int) $year, $query),
                    )),
                SelectFilter::make('month')
                    ->label(__('Month'))
                    ->options(collect(range(1, 12))->mapWithKeys(fn (int $month) => [$month => ucfirst(Carbon::create(2000, $month)->locale(app()->getLocale())->translatedFormat('F'))])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $month) => $query->whereMonth('spent_on', $month))),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label(__('Mark expense as paid'))
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    // Not behind a policy: the read-only gate cannot hide it.
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->tooltip(fn () => CurrentAccount::readOnly() ? __('The account is read-only') : __('Mark expense as paid'))
                    ->visible(fn (Expense $record) => ! $record->is_paid)
                    ->action(function (Expense $record): void {
                        $record->update(['is_paid' => true, 'paid_on' => today()]);

                        Notification::make()->title(__('Expense marked as paid'))->success()->send();
                    }),
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('downloadProof')
                        ->label(__('Download supporting document'))
                        ->extraAttributes(['download' => true])
                        ->icon(Phosphor::DownloadSimple)
                        ->visible(fn (Expense $record): bool => $record->hasProof() && ExpenseResource::canView($record))
                        ->url(fn (Expense $record): string => ExpenseResource::getUrl('download-proof', ['record' => $record])),
                    ReconciliationColumns::detailsAction(),
                    ReplicateAction::make()
                        ->label(__('Duplicate'))
                        ->modalDescription(__('Create an unpaid copy dated today so you can update what changed.'))
                        ->excludeAttributes(['proof_path', 'proof_reference', 'is_reviewed'])
                        ->beforeReplicaSaved(fn (Model $replica) => $replica->fill([
                            'spent_on' => today(),
                            'is_paid' => false,
                            'paid_on' => null,
                            'invoice_number' => null,
                        ]))
                        ->successNotificationTitle(__('Expense duplicated'))
                        ->successRedirectUrl(fn (Model $replica) => ExpenseResource::getUrl('edit', ['record' => $replica])),
                    DeleteAction::make(),
                ]),
            ])
            // As in the mock: the selection's actions show as buttons over the list.
            ->toolbarActions([
                BulkAction::make('markPaid')
                    ->label(__('Mark expense as paid'))
                    ->color('gray')
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->where('is_paid', false)->each->update(['is_paid' => true, 'paid_on' => today()]);

                        Notification::make()->title(__('Expenses marked as paid'))->success()->send();
                    }),
                ExportBulkAction::make()
                    ->label(__('Export selected'))
                    ->color('gray')
                    ->exports([static::excelExport()]),
                DeleteBulkAction::make(),
            ])
            ->emptyStateHeading(__('No expenses'))
            ->emptyStateDescription(__('Record an expense or create a recurring expense.'));
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->withFilename(fn () => __('expenses').'-'.now()->format('Y-m-d'));
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
