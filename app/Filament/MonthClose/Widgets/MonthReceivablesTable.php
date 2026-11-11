<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\MonthClose\Columns\FiscalDocumentColumn;
use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Filament\Support\OutstandingColumn;
use App\Models\Receivable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MonthReceivablesTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    /**
     * "A receber em novembro", as in the mock: a badge with how many are still open, and the
     * due date, client, what it is for, the amount, what is open, the state, the fiscal document
     * and "Receive"; in a past month, also what was still open on its last day.
     */
    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): Htmlable => $this->headingWithBadge(
                __('To receive in :month', ['month' => $this->monthName()]),
                ($open = $this->review()->pendingReceivables()) > 0 ? trans_choice('{1} :count open|[2,*] :count open', $open) : null,
            ))
            ->query(fn () => $this->review()->receivablesQuery(withFiscalReference: true))
            ->paginated(false)
            // Where the "Recorded data" step of the page points when this table has records to complete.
            ->extraAttributes(['id' => 'month-receivables', 'class' => 'scroll-mt-20'])
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
                TextColumn::make('description')
                    ->label(__('For'))
                    ->state(fn (Receivable $record): string => $record->description ?: (string) $record->label)
                    ->description(fn (Receivable $record): ?string => $record->billable?->code)
                    ->url(fn (Receivable $record) => $record->billableUrl()),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR', divideBy: 100)->alignEnd(),
                // Beside the end-of-month balance of a past month, it says it is today's.
                OutstandingColumn::make('outstanding')
                    ->label(fn (): string => $this->review()->isPast() ? __('Current outstanding balance') : __('Outstanding')),
                // In a month that is over, what was still open on its last day.
                TextColumn::make('outstandingAtClose')->label(__('End-of-month balance'))->money('EUR', divideBy: 100)->alignEnd()
                    ->state(fn (Receivable $record): int => $this->review()->outstandingAtClose($record))
                    ->visible(fn (): bool => $this->review()->isPast()),
                TextColumn::make('status')->label(__('Status'))->badge(),
                FiscalDocumentColumn::make(),
            ])
            ->recordActions([
                RecordPaymentAction::rowButton()
                    ->visible(fn (Receivable $record) => $record->outstanding > 0)
                    ->after(fn () => $this->monthChanged()),
            ])
            ->emptyStateHeading(__('Nothing due this month'))
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
