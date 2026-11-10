<?php

namespace App\Filament\Resources\Retainers;

use App\Filament\Resources\Retainers\Pages\CreateRetainer;
use App\Filament\Resources\Retainers\Pages\EditRetainer;
use App\Filament\Resources\Retainers\Pages\ListRetainers;
use App\Filament\Resources\Retainers\Pages\ViewRetainer;
use App\Filament\Resources\Retainers\Schemas\RetainerForm;
use App\Filament\Resources\Retainers\Schemas\RetainerInfolist;
use App\Filament\Resources\Retainers\Tables\RetainersTable;
use App\Filament\Resources\Retainers\Widgets\RetainersAhead;
use App\Filament\Resources\Retainers\Widgets\RetainersSummary;
use App\Filament\Resources\Shared\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Shared\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Shared\RelationManagers\ReceivablesRelationManager;
use App\Models\Retainer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class RetainerResource extends Resource
{
    protected static ?string $model = Retainer::class;

    protected static ?string $modelLabel = 'avença';

    protected static ?string $pluralModelLabel = 'avenças';

    protected static ?string $recordTitleAttribute = 'code';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Repeat;

    protected static string|UnitEnum|null $navigationGroup = 'Receitas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return RetainerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RetainerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RetainersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'category', 'receivables.payments', 'expenses']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'title', 'client.name'];
    }

    public static function getRelations(): array
    {
        return [
            ReceivablesRelationManager::class,
            PaymentsRelationManager::class,
            ExpensesRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [RetainersSummary::class, RetainersAhead::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRetainers::route('/'),
            'create' => CreateRetainer::route('/create'),
            'view' => ViewRetainer::route('/{record}'),
            'edit' => EditRetainer::route('/{record}/edit'),
        ];
    }
}
