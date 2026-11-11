<?php

namespace App\Filament\Resources\RecurringExpenses\Tables;

use App\Enums\Language;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Support\TableEmptyState;
use App\Models\RecurringExpense;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class RecurringExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->defaultSort('description')
            ->recordUrl(fn (RecurringExpense $record): ?string => RecurringExpenseResource::canView($record)
                ? RecurringExpenseResource::getUrl('view', ['record' => $record])
                : null)
            ->columns([
                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->description(fn (RecurringExpense $record) => $record->category?->name)
                    ->searchable(),
                TextColumn::make('kind')
                    ->label(__('Type'))
                    ->badge(),
                TextColumn::make('frequency')
                    ->label(__('Schedule')),
                TextColumn::make('day')
                    ->label(__('Day'))
                    ->formatStateUsing(fn ($state) => __('Day :day', ['day' => $state])),
                TextColumn::make('total')
                    ->label(__('Amount'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('yearly_cost')
                    ->label(__('Per year'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(Summarizer::make()
                        ->label(__('Annual total'))
                        ->money('EUR', divideBy: 100)
                        ->using(fn ($query) => RecurringExpense::query()->whereIn('id', $query->pluck('id'))->where('is_active', true)->get()->sum(fn (RecurringExpense $row) => $row->yearly_cost))),
                TextColumn::make('next_on')
                    ->label(__('Next expense'))
                    ->date(),
                TextColumn::make('expenses_count')
                    ->label(__('Created expenses'))
                    ->numeric()
                    ->alignCenter(),
                ToggleColumn::make('is_active')
                    ->label(__('Active recurring expense')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->modalWidth('3xl')
                        ->mutateDataUsing(fn (array $data) => ExpenseForm::normalize($data)),
                    DeleteAction::make(),
                ]),
            ])
            // As in the mock: with nothing yet, what the list is for and the way to start it.
            ->emptyStateHeading(fn (HasTable $livewire): string => TableEmptyState::heading($livewire, __('You have no recurring expenses yet'), __('recurring expenses')))
            ->emptyStateDescription(fn (HasTable $livewire): string => TableEmptyState::isNarrowed($livewire)
                ? __('Clear the search or the filters to see them all.')
                : __('Add hosting, software and subscriptions here. They then show up in the cash forecast and the calendar.'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('New recurring expense'))->modalHeading(__('New recurring expense'))
                    ->modalDescription(__('Contiu creates the expense on the right day, unpaid, for you to just confirm.'))
                    ->modalWidth('3xl')
                    ->mutateDataUsing(fn (array $data) => ExpenseForm::normalize($data))
                    ->hidden(fn (HasTable $livewire): bool => TableEmptyState::isNarrowed($livewire)),
                TableEmptyState::clearAction(),
            ]);
    }
}
