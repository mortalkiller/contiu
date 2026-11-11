<?php

namespace App\Filament\Resources\RecurringExpenses;

use App\Filament\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Filament\Resources\RecurringExpenses\Pages\ViewRecurringExpense;
use App\Filament\Resources\RecurringExpenses\RelationManagers\GeneratedExpensesRelationManager;
use App\Filament\Resources\RecurringExpenses\Schemas\RecurringExpenseForm;
use App\Filament\Resources\RecurringExpenses\Schemas\RecurringExpenseInfolist;
use App\Filament\Resources\RecurringExpenses\Tables\RecurringExpensesTable;
use App\Filament\Resources\RecurringExpenses\Widgets\RecurringExpenseSummary;
use App\Models\RecurringExpense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static ?string $recordTitleAttribute = 'description';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ArrowsClockwise;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('recurring expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('recurring expenses');
    }

    /** Portuguese capitalises the first word only: "Despesas recorrentes", not "Despesas Recorrentes". */
    public static function getTitleCasePluralModelLabel(): string
    {
        return str_starts_with(app()->getLocale(), 'pt') ? Str::ucfirst(static::getPluralModelLabel()) : parent::getTitleCasePluralModelLabel();
    }

    public static function getTitleCaseModelLabel(): string
    {
        return str_starts_with(app()->getLocale(), 'pt') ? Str::ucfirst(static::getModelLabel()) : parent::getTitleCaseModelLabel();
    }

    public static function getNavigationLabel(): string
    {
        return __('Recurring');
    }

    public static function getNavigationGroup(): string
    {
        return __('Expenses');
    }

    public static function form(Schema $schema): Schema
    {
        return RecurringExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecurringExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringExpensesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'category', 'project', 'retainer'])->withCount('expenses');
    }

    public static function getRelations(): array
    {
        return [GeneratedExpensesRelationManager::class];
    }

    public static function getWidgets(): array
    {
        return [RecurringExpenseSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRecurringExpenses::route('/'),
            'view' => ViewRecurringExpense::route('/{record}'),
        ];
    }
}
