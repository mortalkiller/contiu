<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\MonthClose\Columns\FiscalDocumentColumn;
use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\CurrentAccount;
use App\Support\MonthReview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MonthExpensesTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            // As in the mock: "Despesas de novembro" with how many are still unpaid beside it.
            ->heading(fn (): Htmlable => $this->headingWithBadge(
                __('Expenses in :month', ['month' => $this->monthName()]),
                ($unpaid = $this->review()->pendingExpenses()) > 0 ? __(':count unpaid', ['count' => $unpaid]) : null,
            ))
            ->query(fn () => $this->review()->expensesQuery(withFiscalReference: true))
            ->paginated(false)
            // Where the "Recorded data" step of the page points when this table has records to complete.
            ->extraAttributes(['id' => 'month-expenses', 'class' => 'scroll-mt-20'])
            ->columns([
                TextColumn::make('spent_on')
                    ->label(__('Date'))
                    ->date()
                    ->description(fn (Expense $record) => $this->review()->isCarriedOver($record) ? __('from previous months') : null),
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->description(fn (Expense $record) => $record->description),
                TextColumn::make('kind')->label(__('Type'))->badge(),
                TextColumn::make('total')->label(__('Total'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('is_paid')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Expense paid') : __('Unpaid'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                FiscalDocumentColumn::make(),
                TextColumn::make('proof')
                    ->label(__('Proof'))
                    ->state(fn (Expense $record): string => $record->hasProof() ? __('Attached') : __('Missing'))
                    ->badge()
                    ->color(fn (Expense $record): string => $record->hasProof() ? 'success' : 'warning'),
                // Old references from the spreadsheet import, to confirm: only while the month has one.
                TextInputColumn::make('invoice_number')
                    ->label(__('Previous reference awaiting confirmation'))
                    ->state(fn (Expense $record): ?string => MonthReview::unconfirmedLegacyReference($record))
                    ->placeholder(__('E.g. FT 2026/12'))
                    ->disabled(fn (): bool => CurrentAccount::readOnly())
                    ->rules(['nullable', 'max:100'])
                    ->afterStateUpdated(fn () => $this->monthChanged())
                    ->visible(fn (): bool => $this->review()->expenses()->contains(fn (Expense $expense): bool => MonthReview::unconfirmedLegacyReference($expense) !== null)),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label(__('Mark paid'))
                    ->color('gray')
                    ->button()
                    ->size('sm')
                    // Not behind a policy: the read-only gate cannot hide it.
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->tooltip(fn () => CurrentAccount::readOnly() ? __('The account is read-only') : null)
                    ->visible(fn (Expense $record) => ! $record->is_paid)
                    ->action(function (Expense $record): void {
                        $record->update(['is_paid' => true, 'paid_on' => today()]);

                        Notification::make()->title(__('Expense marked as paid'))->success()->send();
                        $this->monthChanged();
                    }),
                Action::make('edit')
                    ->label(__('Edit'))
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (Expense $record): bool => ! CurrentAccount::readOnly() && ExpenseResource::canEdit($record))
                    ->url(fn (Expense $record) => ExpenseResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading(__('No expenses this month'))
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
