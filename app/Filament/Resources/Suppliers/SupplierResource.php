<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Filament\Resources\Suppliers\Widgets\SuppliersSummary;
use App\Models\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Storefront;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('suppliers');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('Contacts');
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('expenses.category')->withCount('expenses');
    }

    public static function getWidgets(): array
    {
        return [SuppliersSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
