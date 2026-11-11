<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\MonthClose\Columns\FiscalDocumentColumn;
use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Expense;
use App\Support\CurrentAccount;
use App\Support\MonthReview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
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
            ->heading(__('Expenses'))
            ->description(__('Expenses from this month and any still unpaid.'))
            ->query(fn () => $this->review()->expensesQuery(withFiscalReference: true))
            ->paginated(false)
            ->columns([
                TextColumn::make('spent_on')
                    ->label(__('Date'))
                    ->date()
                    ->description(fn (Expense $record) => $this->review()->isCarriedOver($record) ? __('from previous months') : null),
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->description(fn (Expense $record) => $record->description),
                TextColumn::make('kind')->label(__('Type'))->badge()->toggleable(),
                TextColumn::make('total')->label(__('Total'))->money('EUR', divideBy: 100)->alignEnd(),
                IconColumn::make('is_paid')->label(__('Paid expense'))->boolean(),
                ReconciliationColumns::expenseColumn(),
                FiscalDocumentColumn::make(),
                TextColumn::make('proof')
                    ->label(__('Proof'))
                    ->state(fn (Expense $record): string => $record->hasProof() ? __('Proof attached') : __('No proof attached'))
                    ->badge()
                    ->color(fn (Expense $record): string => $record->hasProof() ? 'success' : 'warning'),
                TextInputColumn::make('invoice_number')
                    ->label(__('Previous reference awaiting confirmation'))
                    ->state(fn (Expense $record): ?string => MonthReview::unconfirmedLegacyReference($record))
                    ->placeholder(__('E.g. FT 2026/12'))
                    ->disabled(fn (): bool => CurrentAccount::readOnly())
                    ->rules(['nullable', 'max:100'])
                    ->afterStateUpdated(fn () => $this->monthChanged()),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label(__('Mark paid'))
                    ->icon(Phosphor::CheckCircle)
                    ->color('success')
                    ->button()
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
                    ->icon(Phosphor::PencilSimple)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (Expense $record): bool => ! CurrentAccount::readOnly() && ExpenseResource::canEdit($record))
                    ->url(fn (Expense $record) => ExpenseResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading(__('No expenses this month'))
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
