<?php

namespace App\Filament\Resources\FiscalDocuments;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Filament\Resources\FiscalDocuments\Actions\CreditApplicationActions;
use App\Filament\Resources\FiscalDocuments\Actions\CustomerRefundAction;
use App\Filament\Resources\FiscalDocuments\Actions\DebitNoteChargeAction;
use App\Filament\Resources\FiscalDocuments\Actions\FiscalDocumentPdfActions;
use App\Filament\Resources\FiscalDocuments\Actions\InvoiceAdvanceAction;
use App\Filament\Resources\FiscalDocuments\Actions\InvoiceXpressCancellationAction;
use App\Filament\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\DownloadFiscalDocumentPdf;
use App\Filament\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Filament\Resources\FiscalDocuments\Widgets\FiscalDocumentsSummary;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class FiscalDocumentResource extends Resource
{
    protected static ?string $model = FiscalDocument::class;

    protected static ?string $slug = 'documentos-fiscais';

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Invoice;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('fiscal document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fiscal documents');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fiscal documents');
    }

    public static function getNavigationGroup(): string
    {
        return __('Revenue');
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $account = CurrentAccount::get();

        if ($account === null || ($record !== null && $record->account_id !== $account->getKey())) {
            return Response::deny();
        }

        if (! in_array($action, ['viewAny', 'view', 'create', 'update'], true)) {
            return Response::deny();
        }

        return Gate::forUser(Filament::auth()->user())->inspect($action, $record ?? FiscalDocument::class);
    }

    public static function form(Schema $schema): Schema
    {
        return FiscalDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'currentPdf', 'invoiceXpressSnapshot',
                'links.receivable.billable', 'links.payment', 'links.expense.supplier', 'links.relatedFiscalDocument',
            ]))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Document'))->searchable()->sortable()
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (FiscalDocument $record): string => self::documentDescription($record)),
                TextColumn::make('document_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('party_name')->label(__('Counterparty'))->searchable()->placeholder('—'),
                TextColumn::make('linkedTo')->label(__('Linked to'))
                    ->state(fn (FiscalDocument $record): ?string => self::linkedTo($record)['label'] ?? null)
                    ->description(fn (FiscalDocument $record): ?string => self::linkedTo($record)['detail'] ?? null)
                    ->placeholder(__('Not linked'))
                    ->wrap(),
                TextColumn::make('net_amount')->label(__('Excluding VAT'))->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)->alignEnd()
                    ->color(fn (FiscalDocument $record): ?string => $record->net_amount < 0 ? 'danger' : null),
                TextColumn::make('total_amount')->label(__('Total'))->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)->alignEnd()
                    ->color(fn (FiscalDocument $record): ?string => $record->total_amount < 0 ? 'danger' : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(FiscalDocumentType::class),
                SelectFilter::make('direction')->label(__('Direction'))->options(FiscalDocumentDirection::class),
                SelectFilter::make('status')->label(__('Status'))->options(FiscalDocumentStatus::class),
                SelectFilter::make('billed_year')->label(__('Documented turnover in EUR'))
                    ->options(fn (): array => collect(DocumentedTurnover::issueYears())->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, int|string $year): Builder => DocumentedTurnover::billingDocuments($query)
                            ->where('currency', 'EUR')->whereYear('document_date', (int) $year),
                    )),
            ])
            ->recordActions([
                ViewAction::make()->mutateRecordDataUsing(FiscalDocumentForm::withLinkData(...)),
                EditAction::make(),
                FiscalDocumentPdfActions::download(),
                FiscalDocumentPdfActions::retrieve(),
                CreditApplicationActions::apply(),
                CreditApplicationActions::reverse(),
                CustomerRefundAction::make(),
                DebitNoteChargeAction::make(),
                InvoiceAdvanceAction::make(),
                InvoiceXpressCancellationAction::make(),
            ])
            ->emptyStateHeading(__('No fiscal documents'));
    }

    /**
     * What a document is attached to, for the "Ligado a" column: the charge, payment, expense or
     * original document, with a short second line saying which of those it is.
     *
     * @return array{label: string, detail: string}|null
     */
    private static function linkedTo(FiscalDocument $record): ?array
    {
        $link = $record->links;

        return match (true) {
            $link?->receivable !== null => ['label' => $link->receivable->label, 'detail' => __('Receivable · due on :date', ['date' => $link->receivable->due_on->format(Language::resolve(app()->getLocale())->dateFormat())])],
            $link?->payment !== null => ['label' => $link->payment->receivableLabels(), 'detail' => __('Payment on :date', ['date' => $link->payment->paid_on->format(Language::resolve(app()->getLocale())->dateFormat())])],
            $link?->expense !== null => ['label' => $link->expense->supplier?->name ?? __('Expense'), 'detail' => __('Expense on :date', ['date' => $link->expense->spent_on->format(Language::resolve(app()->getLocale())->dateFormat())])],
            $link?->relatedFiscalDocument !== null => ['label' => $link->relatedFiscalDocument->number, 'detail' => __('Original document')],
            default => null,
        };
    }

    private static function documentDescription(FiscalDocument $record): string
    {
        $issued = $record->direction === FiscalDocumentDirection::Issued;

        if ($record->type === FiscalDocumentType::Receipt) {
            return $issued ? __('Receipt · issued') : __('Receipt · received');
        }

        $type = $record->type->getLabel();

        return $issued ? __(':type · issued', ['type' => $type]) : __(':type · received', ['type' => $type]);
    }

    public static function getWidgets(): array
    {
        return [FiscalDocumentsSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalDocuments::route('/'),
            'create' => CreateFiscalDocument::route('/create'),
            'edit' => EditFiscalDocument::route('/{record}/edit'),
            'download-pdf' => DownloadFiscalDocumentPdf::route('/{record}/pdf/{version?}'),
        ];
    }
}
