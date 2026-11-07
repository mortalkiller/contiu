<?php

namespace App\Filament\Widgets;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Models\Receivable;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * "A receber": open amounts, oldest due first. Overdue rows show in red.
 */
class UpcomingReceivablesTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use UsesDashboardPeriod;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('A receber')
            ->description(fn () => 'Em aberto do que foi contratado em '.$this->period()->label().', do vencimento mais antigo para o mais recente.')
            ->query(fn () => $this->summary()->contractedReceivablesQuery()->open()->with(['billable.client'])->orderBy('due_on'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('due_on')
                    ->label('Vencimento')
                    ->date()
                    ->color(fn (Receivable $record) => $record->due_on->isBefore(today()) ? 'danger' : null),
                TextColumn::make('billable.code')
                    ->label('Referente a')
                    // Client and instalment under the code: keeps the table inside the wide column.
                    ->description(fn (Receivable $record) => trim(($record->client?->name ?? '').' · '.$record->description, ' ·'))
                    ->wrap()
                    ->url(fn (Receivable $record) => $record->billableUrl()),
                TextColumn::make('outstanding')
                    ->label('Em aberto')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->recordActions([
                RecordPaymentAction::make()->button()->size('sm')->label('Receber'),
            ])
            ->headerActions([
                Action::make('all')
                    ->label('Ver tudo')
                    ->link()
                    ->url(ReceivableResource::getUrl()),
            ])
            ->emptyStateHeading('Nada por receber')
            ->emptyStateDescription('Todos os valores estão pagos.');
    }
}
