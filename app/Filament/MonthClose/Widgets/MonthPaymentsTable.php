<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\MonthClose\Columns\FiscalDocumentColumn;
use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MonthPaymentsTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Payments received this month'))
            ->query(fn () => $this->review()->paymentsQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('paid_on')->label(__('Date'))->date(),
                TextColumn::make('reference')
                    ->label(__('For'))
                    ->state(fn (Payment $record): string => $record->receivableLabels())
                    ->wrap(),
                TextColumn::make('amount')->label(__('Received amount'))->money('EUR', divideBy: 100)->alignEnd(),
                FiscalDocumentColumn::make(),
                ReconciliationColumns::paymentColumn(),
            ])
            ->recordActions([ReconciliationColumns::detailsAction()])
            ->emptyStateHeading(__('No payments received this month'))
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
