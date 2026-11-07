<?php

namespace App\Filament\Resources\Receivables;

use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Tables\ReceivablesTable;
use App\Models\Receivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "A receber": every instalment and retainer period in one place, oldest due first.
 * Receivables are created from projects and retainers, never here.
 */
class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static ?string $modelLabel = 'valor a receber';

    protected static ?string $pluralModelLabel = 'valores a receber';

    protected static ?string $navigationLabel = 'A receber';

    protected static ?string $slug = 'a-receber';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::HourglassMedium;

    protected static string|UnitEnum|null $navigationGroup = 'Receitas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $overdue = Receivable::query()->open()->excludingCancelled()->whereDate('due_on', '<', today())->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Vencidos';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ReceivablesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->excludingCancelled()->with(['payments', 'billable.client']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
        ];
    }
}
