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
        return __('Costs that repeat. Contiu creates the expense on the right date.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New recurring expense'))->modalHeading(__('New recurring expense'))
                ->modalDescription(__('Contiu creates the expense on the right day, unpaid, for you to just confirm.'))
                ->modalWidth('3xl')
                ->mutateDataUsing(fn (array $data) => ExpenseForm::normalize($data)),
        ];
    }
}
