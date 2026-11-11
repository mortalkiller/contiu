<?php

namespace App\Filament\Resources\Receivables;

use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Receivables\Tables\ReceivablesTable;
use App\Filament\Resources\Receivables\Widgets\ReceivablesSummary;
use App\Models\Receivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "A receber": every instalment and retainer period in one place, oldest due first.
 * Receivables are created from projects and retainers, never here.
 */
class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static ?string $slug = 'a-receber';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::HourglassMedium;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('receivable');
    }

    public static function getPluralModelLabel(): string
    {
        return __('receivables');
    }

    public static function getNavigationLabel(): string
    {
        return __('Amounts to receive');
    }

    public static function getNavigationGroup(): string
    {
        return __('Revenue');
    }

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
        return __('Overdue receivables');
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
        return parent::getEloquentQuery()->excludingCancelled()->withOutstandingBalance()->with(['payments', 'billable.client']);
    }

    public static function getWidgets(): array
    {
        return [ReceivablesSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
        ];
    }
}
