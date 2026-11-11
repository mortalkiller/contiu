<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\ClientStatementPage;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientExpensesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientPaymentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientReceivablesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\RetainersRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Filament\Resources\Clients\Widgets\ClientsSummary;
use App\Models\Client;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Users;

    protected static ?int $navigationSort = 1;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getModelLabel(): string
    {
        return __('client');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clients');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Contacts');
    }

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
            ClientReceivablesRelationManager::class,
            ClientPaymentsRelationManager::class,
            ClientExpensesRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [ClientsSummary::class];
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
