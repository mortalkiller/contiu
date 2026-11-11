<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\Actions\ExpenseActions;
use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

/**
 * An expense's own page: what was bought, how much it cost, how it was paid and its supporting
 * document and, beside it, the supplier, what it is tied to, the bank and its review. It is
 * changed on the edit form; here it is only marked as paid, duplicated or deleted.
 */
class ViewExpense extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = ExpenseResource::class;

    /** Everything the page reads from the expense, loaded with it: no card asks the database for its record. */
    public const array RELATIONS = ['supplier', 'category', 'project.client', 'retainer.client', 'recurringExpense'];

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(self::RELATIONS);
    }

    /** "Expenses › Internet · novembro": the page being the expense's own is said by its title. */
    public function getBreadcrumbs(): array
    {
        return array_slice(parent::getBreadcrumbs(), 0, -1, preserve_keys: true);
    }

    /**
     * As in the mock: the menu of everything else, the way to the edit form, then paying it while
     * it is unpaid. Once paid the page is opened again, so every card reads the expense as it now is.
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                ExpenseActions::downloadProof(),
                ExpenseActions::duplicate(),
                DeleteAction::make(),
            ])->label(__('More actions')),
            Action::make('editRecord')
                ->label(__('Edit'))
                ->color('gray')
                ->visible(fn (): bool => ExpenseResource::canEdit($this->record))
                ->url(fn (): string => ExpenseResource::getUrl('edit', ['record' => $this->record])),
            ExpenseActions::markPaid()
                ->after(fn () => $this->redirect(ExpenseResource::getUrl('view', ['record' => $this->record]))),
        ];
    }
}
