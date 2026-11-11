<?php

namespace App\Filament\Resources\FiscalDocuments\Tables;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Filament\Resources\FiscalDocuments\Actions\FiscalDocumentPdfActions;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\DocumentedTurnover;
use App\Support\Invoicing\Invoicing;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class FiscalDocumentsTable
{
    /** Everything a row shows, loaded with the page so no row asks the database on its own. */
    public const array RELATIONS = [
        'currentPdf', 'integrationRecord',
        'links.receivable.billable', 'links.payment', 'links.expense.supplier', 'links.relatedFiscalDocument',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(self::RELATIONS)->withCount('lines'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('document_date')->orderByDesc('id'))
            ->recordUrl(fn (FiscalDocument $record): string => $record->isDraft() && $record->integrationRecord !== null
                ? FiscalDocumentResource::getUrl('draft', ['record' => $record])
                : FiscalDocumentResource::getUrl('view', ['record' => $record]))
            ->searchPlaceholder(__('Number, client or tax ID'))
            ->columns([
                TextColumn::make('number')
                    ->label(__('Document'))
                    ->state(fn (FiscalDocument $record): string => FiscalDocumentPresenter::title($record))
                    ->description(fn (FiscalDocument $record): string => FiscalDocumentPresenter::kind($record))
                    ->fontFamily(fn (FiscalDocument $record): ?FontFamily => $record->number === null ? null : FontFamily::Mono)
                    ->weight(fn (FiscalDocument $record): ?string => $record->number === null ? 'medium' : null)
                    ->searchable(['number', 'party_name', 'party_tax_id'])
                    ->sortable(),
                TextColumn::make('document_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('party_name')->label(__('Client or supplier'))->placeholder('—'),
                TextColumn::make('linkedTo')
                    ->label(__('Linked to'))
                    ->state(fn (FiscalDocument $record): ?string => self::linkedTo($record)['label'] ?? null)
                    ->description(fn (FiscalDocument $record): ?string => self::linkedTo($record)['detail'] ?? null)
                    ->placeholder(__('Not linked')),
                TextColumn::make('total_amount')
                    ->label(__('Total'))
                    ->state(fn (FiscalDocument $record): int => FiscalDocumentPresenter::signed($record, $record->total_amount))
                    ->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)
                    ->weight('semibold')
                    ->color(fn (FiscalDocument $record): ?string => $record->type === FiscalDocumentType::CreditNote ? 'danger' : null)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (FiscalDocument $record): string => FiscalDocumentPresenter::state($record)['label'])
                    ->color(fn (FiscalDocument $record): string => FiscalDocumentPresenter::state($record)['color'])
                    ->badge(),
                TextColumn::make('origin')
                    ->label(__('Issued by'))
                    ->state(fn (FiscalDocument $record): string => FiscalDocumentPresenter::issuer($record)['label'])
                    ->description(fn (FiscalDocument $record): ?string => FiscalDocumentPresenter::issuer($record)['detail'])
                    ->color(fn (FiscalDocument $record): ?string => FiscalDocumentPresenter::issuer($record)['color']),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(FiscalDocumentType::class),
                SelectFilter::make('direction')->label(__('Direction'))->options(FiscalDocumentDirection::class),
                SelectFilter::make('status')->label(__('Status'))->options(FiscalDocumentStatus::class),
                SelectFilter::make('billed_year')
                    ->label(__('Documented turnover in EUR'))
                    ->options(fn (): array => collect(DocumentedTurnover::issueYears())->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $year): Builder => DocumentedTurnover::billingDocuments($query)
                            ->where('currency', 'EUR')->whereYear('document_date', (int) $year),
                    )),
            ])
            ->recordActions([
                Action::make('continue')
                    ->label(__('Continue'))
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->visible(fn (FiscalDocument $record): bool => $record->isDraft() && static::canIssue())
                    ->url(fn (FiscalDocument $record): string => FiscalDocumentResource::getUrl('draft', ['record' => $record])),
                FiscalDocumentPdfActions::download()->label(__('PDF'))->icon(null)->button()->color('gray')->size('sm')->outlined()
                    ->visible(fn (FiscalDocument $record): bool => $record->currentPdf !== null),
            ])
            ->emptyStateHeading(__('No fiscal documents'))
            ->emptyStateDescription(__('Documents you issue or record appear here.'));
    }

    /** Asked once for the whole page of rows, not once per row. */
    private static function canIssue(): bool
    {
        return once(fn (): bool => Invoicing::canIssue());
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(['total_amount'])
            ->withFilename(fn () => str(__('Fiscal documents'))->slug().'-'.now()->format('Y-m-d'));
    }

    /**
     * What a document is attached to: the charge, payment, expense or original document, with a
     * short second line saying which of those it is.
     *
     * @return array{label: string, detail: string}|null
     */
    private static function linkedTo(FiscalDocument $record): ?array
    {
        $link = $record->links;
        $date = fn ($value): string => $value->format(Language::resolve(app()->getLocale())->dateFormat());

        return match (true) {
            $link?->receivable !== null => ['label' => $link->receivable->label, 'detail' => __('Receivable · due on :date', ['date' => $date($link->receivable->due_on)])],
            $link?->payment !== null => ['label' => $link->payment->receivableLabels(), 'detail' => __('Payment on :date', ['date' => $date($link->payment->paid_on)])],
            $link?->expense !== null => ['label' => $link->expense->supplier?->name ?? __('Expense'), 'detail' => __('Expense on :date', ['date' => $date($link->expense->spent_on)])],
            $link?->relatedFiscalDocument !== null => ['label' => FiscalDocumentPresenter::title($link->relatedFiscalDocument), 'detail' => __('Original document')],
            $record->lines_count > 0 => ['label' => trans_choice('{1} 1 line|[2,*] :count lines', $record->lines_count), 'detail' => Money::format($record->net_amount)],
            default => null,
        };
    }
}
