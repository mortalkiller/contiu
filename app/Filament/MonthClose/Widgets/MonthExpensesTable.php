<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Expense;
use App\Support\CurrentAccount;
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
            ->heading('Despesas')
            ->description('Despesas deste mês e as que ficaram por pagar. Confirma o pagamento e escreve o n.º da fatura na própria linha.')
            ->query(fn () => $this->review()->expensesQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('spent_on')
                    ->label('Data')
                    ->date()
                    ->description(fn (Expense $record) => $this->review()->isCarriedOver($record) ? 'de meses anteriores' : null),
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->description(fn (Expense $record) => $record->description),
                TextColumn::make('kind')->label('Tipo')->badge()->toggleable(),
                TextColumn::make('total')->label('Total')->money('EUR', divideBy: 100)->alignEnd(),
                IconColumn::make('is_paid')->label('Paga')->boolean(),
                ReconciliationColumns::expenseColumn(),
                TextInputColumn::make('invoice_number')
                    ->label('N.º da fatura')
                    ->placeholder('Ex.: FT 2026/12')
                    ->rules(['nullable', 'max:100'])
                    ->afterStateUpdated(fn () => $this->monthChanged()),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label('Marcar paga')
                    ->icon(Phosphor::CheckCircle)
                    ->color('success')
                    ->button()
                    // Not behind a policy: the read-only gate cannot hide it.
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->tooltip(fn () => CurrentAccount::readOnly() ? 'A conta está só de leitura' : null)
                    ->visible(fn (Expense $record) => ! $record->is_paid)
                    ->action(function (Expense $record): void {
                        $record->update(['is_paid' => true, 'paid_on' => today()]);

                        Notification::make()->title('Despesa marcada como paga')->success()->send();
                        $this->monthChanged();
                    }),
                Action::make('edit')
                    ->label('Editar')
                    ->icon(Phosphor::PencilSimple)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (Expense $record) => ExpenseResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Sem despesas neste mês')
            ->emptyStateIcon(Phosphor::CheckCircle);
    }
}
