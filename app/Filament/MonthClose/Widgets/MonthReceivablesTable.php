<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\MonthClose\Columns\FiscalDocumentColumn;
use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Models\Receivable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MonthReceivablesTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('To receive'))
            ->description(__('Charges due, invoiced or paid in advance during the month, and outstanding amounts from previous months.'))
            ->query(fn () => $this->review()->receivablesQuery(withFiscalReference: true))
            ->paginated(false)
            ->columns([
                TextColumn::make('due_on')
                    ->label(__('Due date'))
                    ->date()
                    ->description(fn (Receivable $record) => match (true) {
                        $this->review()->isCarriedOver($record) => __('from previous months'),
                        $this->review()->isDueLater($record) => __('due after this month'),
                        default => null,
                    })
                    ->color(fn (Receivable $record) => $record->status->value === 'overdue' ? 'danger' : null),
                TextColumn::make('client')
                    ->label(__('Client'))
                    ->state(fn (Receivable $record) => $record->client?->name),
                TextColumn::make('billable.code')
                    ->label(__('For'))
                    ->description(fn (Receivable $record) => $record->description)
                    ->url(fn (Receivable $record) => $record->billableUrl()),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('outstanding')->label(__('Current outstanding balance'))->money('EUR', divideBy: 100)->alignEnd()
                    ->weight('medium')
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('outstandingAtClose')->label(__('End-of-month balance'))->money('EUR', divideBy: 100)->alignEnd()
                    ->state(fn (Receivable $record): int => $this->review()->outstandingAtClose($record)),
                TextColumn::make('status')->label(__('Current status'))->badge(),
                FiscalDocumentColumn::make(),
            ])
            ->recordActions([
                RecordPaymentAction::make()
                    ->button()
                    ->label(__('Receive payment'))
                    ->visible(fn (Receivable $record) => $record->outstanding > 0)
                    ->after(fn () => $this->monthChanged()),
            ])
            ->emptyStateHeading(__('Nothing due this month'))
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
