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

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('To receive'))
            ->description(fn () => __('Outstanding from work contracted in :period, from oldest to newest due date.', ['period' => $this->period()->label()]))
            ->query(fn () => $this->summary()->contractedReceivablesQuery()->open()->with(['billable.client'])->orderBy('due_on'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('due_on')
                    ->label(__('Due date'))
                    ->date()
                    ->color(fn (Receivable $record) => $record->due_on->isBefore(today()) ? 'danger' : null),
                TextColumn::make('billable.code')
                    ->label(__('For'))
                    // Client and instalment under the code: keeps the table inside the wide column.
                    ->description(fn (Receivable $record) => trim(($record->client?->name ?? '').' · '.$record->description, ' ·'))
                    ->wrap()
                    ->url(fn (Receivable $record) => $record->billableUrl()),
                TextColumn::make('outstanding')
                    ->label(__('Outstanding'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
            ])
            ->recordActions([
                RecordPaymentAction::make()->button()->size('sm')->label(__('Receive payment')),
            ])
            ->headerActions([
                Action::make('all')
                    ->label(__('View all'))
                    ->link()
                    ->url(ReceivableResource::getUrl()),
            ])
            ->emptyStateHeading(__('Nothing to receive'))
            ->emptyStateDescription(__('All amounts have been paid.'));
    }
}
