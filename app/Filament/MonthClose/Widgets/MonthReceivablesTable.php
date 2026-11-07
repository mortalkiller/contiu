<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Models\Receivable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MonthReceivablesTable extends TableWidget
{
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('A receber')
            ->description('O que vence este mês, o que ficou em aberto de meses anteriores e o que, vencendo mais tarde, já foi pago em parte este mês.')
            ->query(fn () => $this->review()->receivablesQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('due_on')
                    ->label('Vencimento')
                    ->date()
                    ->description(fn (Receivable $record) => match (true) {
                        $this->review()->isCarriedOver($record) => 'de meses anteriores',
                        $this->review()->isDueLater($record) => 'pago em parte este mês',
                        default => null,
                    })
                    ->color(fn (Receivable $record) => $record->status->value === 'overdue' ? 'danger' : null),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->state(fn (Receivable $record) => $record->client?->name),
                TextColumn::make('billable.code')
                    ->label('Referente a')
                    ->description(fn (Receivable $record) => $record->description)
                    ->url(fn (Receivable $record) => $record->billableUrl()),
                TextColumn::make('amount')->label('Valor')->money('EUR')->alignEnd(),
                TextColumn::make('outstanding')->label('Em aberto')->money('EUR')->alignEnd()
                    ->weight('medium')
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->label('Estado')->badge(),
            ])
            ->recordActions([
                RecordPaymentAction::make()
                    ->button()
                    ->label('Receber')
                    ->visible(fn (Receivable $record) => $record->outstanding > 0)
                    ->after(fn () => $this->monthChanged()),
            ])
            ->emptyStateHeading('Nada a receber neste mês')
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
