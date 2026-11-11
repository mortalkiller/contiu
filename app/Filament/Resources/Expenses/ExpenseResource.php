<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\DownloadExpenseEvidence;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Filament\Resources\Expenses\Widgets\ExpensesSummary;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static ?string $recordTitleAttribute = 'description';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Receipt;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('expenses');
    }

    public static function getNavigationGroup(): string
    {
        return __('Expenses');
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema, withEvidence: true);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    /** In the menu: how many expenses are still to pay, the same count as the "Por pagar" tab. */
    public static function getNavigationBadge(): ?string
    {
        $unpaid = Expense::query()->where('is_paid', false)->count();

        return $unpaid > 0 ? (string) $unpaid : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Expenses to pay');
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'category', 'project', 'retainer']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['description', 'supplier.name', 'invoice_number'];
    }

    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [ExpensesSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
            'download-proof' => DownloadExpenseEvidence::route('/{record}/comprovativo'),
        ];
    }
}
