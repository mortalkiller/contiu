<?php

namespace App\Filament\Resources\RecurringExpenses\Pages;

use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRecurringExpenses extends ManageRecords
{
    protected static string $resource = RecurringExpenseResource::class;

    public function getSubheading(): ?string
    {
        return 'Mock: a geração automática das despesas mensais fica para a implementação.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova despesa recorrente')
                ->modalWidth('5xl')
                ->mutateDataUsing(fn (array $data) => ExpenseForm::normalize($data)),
        ];
    }
}
