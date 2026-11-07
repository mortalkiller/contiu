<?php

namespace App\Filament\Resources\RecurringExpenses;

use App\Filament\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Filament\Resources\RecurringExpenses\Schemas\RecurringExpenseForm;
use App\Filament\Resources\RecurringExpenses\Tables\RecurringExpensesTable;
use App\Models\RecurringExpense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static ?string $modelLabel = 'despesa recorrente';

    protected static ?string $pluralModelLabel = 'despesas recorrentes';

    protected static ?string $navigationLabel = 'Recorrentes';

    protected static ?string $recordTitleAttribute = 'description';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ArrowsClockwise;

    protected static string|UnitEnum|null $navigationGroup = 'Despesas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return RecurringExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecurringExpensesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'category', 'project', 'retainer'])->withCount('expenses');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRecurringExpenses::route('/'),
        ];
    }
}
