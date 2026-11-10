<?php

namespace App\Filament\Resources\FiscalDocuments;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
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

    protected static ?string $modelLabel = 'documento fiscal';

    protected static ?string $pluralModelLabel = 'documentos fiscais';

    protected static ?string $navigationLabel = 'Documentos fiscais';

    protected static ?string $slug = 'documentos-fiscais';

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Invoice;

    protected static string|UnitEnum|null $navigationGroup = 'Receitas';

    protected static ?int $navigationSort = 5;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'currentPdf', 'invoiceXpressSnapshot',
                'links.receivable.billable', 'links.payment', 'links.expense.supplier', 'links.relatedFiscalDocument',
            ]))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('Documento')->searchable()->sortable()
                    ->fontFamily(FontFamily::Mono)
                    ->description(fn (FiscalDocument $record): string => $record->type->getLabel().' · '.self::directionWord($record)),
                TextColumn::make('document_date')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('party_name')->label('Contraparte')->searchable()->placeholder('—'),
                TextColumn::make('linkedTo')->label('Ligado a')
                    ->state(fn (FiscalDocument $record): ?string => self::linkedTo($record)['label'] ?? null)
                    ->description(fn (FiscalDocument $record): ?string => self::linkedTo($record)['detail'] ?? null)
                    ->placeholder('Sem ligação')
                    ->wrap(),
                TextColumn::make('net_amount')->label('Sem IVA')->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)->alignEnd()
                    ->color(fn (FiscalDocument $record): ?string => $record->net_amount < 0 ? 'danger' : null),
                TextColumn::make('total_amount')->label('Total')->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)->alignEnd()
                    ->color(fn (FiscalDocument $record): ?string => $record->total_amount < 0 ? 'danger' : null),
                TextColumn::make('status')->label('Estado')->badge(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(FiscalDocumentType::class),
                SelectFilter::make('direction')->label('Sentido')->options(FiscalDocumentDirection::class),
                SelectFilter::make('status')->label('Estado')->options(FiscalDocumentStatus::class),
                SelectFilter::make('billed_year')->label('Faturação documentada em EUR')
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
            ->emptyStateHeading('Sem documentos fiscais');
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
            $link?->receivable !== null => ['label' => $link->receivable->label, 'detail' => 'Valor a receber · vence a '.$link->receivable->due_on->format('d/m/Y')],
            $link?->payment !== null => ['label' => $link->payment->receivableLabels(), 'detail' => 'Recebimento de '.$link->payment->paid_on->format('d/m/Y')],
            $link?->expense !== null => ['label' => $link->expense->supplier?->name ?? 'Despesa', 'detail' => 'Despesa de '.$link->expense->spent_on->format('d/m/Y')],
            $link?->relatedFiscalDocument !== null => ['label' => $link->relatedFiscalDocument->number, 'detail' => 'Documento original'],
            default => null,
        };
    }

    /** "emitida" or "recebida", agreeing with the document type ("Recibo" is the only masculine one). */
    private static function directionWord(FiscalDocument $record): string
    {
        $ending = $record->type === FiscalDocumentType::Receipt ? 'o' : 'a';

        return ($record->direction === FiscalDocumentDirection::Issued ? 'emitid' : 'recebid').$ending;
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
