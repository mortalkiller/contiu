<?php

namespace App\Filament\Resources\FiscalDocuments;

use App\Filament\Pages\InvoicingSyncPage;
use App\Filament\Resources\FiscalDocuments\Pages\ComposeFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\DownloadFiscalDocumentPdf;
use App\Filament\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentInfolist;
use App\Filament\Resources\FiscalDocuments\Tables\FiscalDocumentsTable;
use App\Filament\Resources\FiscalDocuments\Widgets\FiscalDocumentsSummary;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
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

    /** The screens reached from the list keep "Fiscal documents" highlighted in the sidebar. */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [...(array) parent::getNavigationItemActiveRoutePattern(), InvoicingSyncPage::getRouteName()];
    }

    /** Documents are never deleted from the screens: an issued one is cancelled, a draft is discarded by its own action. */
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

    public static function infolist(Schema $schema): Schema
    {
        return FiscalDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalDocumentsTable::configure($table);
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
            'compose' => ComposeFiscalDocument::route('/novo/{type}'),
            'draft' => ComposeFiscalDocument::route('/{record}/rascunho'),
            'view' => ViewFiscalDocument::route('/{record}'),
            'edit' => EditFiscalDocument::route('/{record}/edit'),
            'download-pdf' => DownloadFiscalDocumentPdf::route('/{record}/pdf/{version?}'),
        ];
    }
}
