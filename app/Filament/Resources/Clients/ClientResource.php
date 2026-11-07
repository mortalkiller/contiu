<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\ClientStatementPage;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\RetainersRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clientes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Users;

    protected static string|UnitEnum|null $navigationGroup = 'Contactos';

    protected static ?int $navigationSort = 1;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProjectsRelationManager::class,
            RetainersRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /** Tabs on a client: Ver · Extrato · Editar. */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewClient::class,
            ClientStatementPage::class,
            EditClient::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'view' => ViewClient::route('/{record}'),
            'statement' => ClientStatementPage::route('/{record}/extrato'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
