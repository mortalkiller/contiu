<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Schemas\PaymentHeader;
use App\Filament\Support\ReconciliationColumns;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

/**
 * A payment received on its own page: how much came in, what it pays and, beside it, who paid,
 * the fiscal document issued for it, the bank statement line that explains it and what happened
 * to it. What can be done to it is what its row on the list already does.
 */
class ViewPayment extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = PaymentResource::class;

    /**
     * Everything the page shows, loaded with the payment: each receivable comes with what is
     * still owed on it worked out in SQL, so no row of the table asks the database on its own.
     */
    protected function resolveRecord(int|string $key): Model
    {
        $payment = parent::resolveRecord($key);
        $payment->allocations->load([
            'receivable' => fn (Relation $receivable) => $receivable->withOutstandingBalance(),
            'receivable.billable.client',
        ]);

        return $payment;
    }

    public function getTitle(): string
    {
        return PaymentHeader::title($this->getRecord());
    }

    /** "Recebimentos › 12/11/2026 · Estúdio Brisa", as in the mock: the page being the payment's own is said by its title. */
    public function getBreadcrumb(): string
    {
        return PaymentHeader::breadcrumb($this->getRecord());
    }

    /**
     * As in the mock: the menu of everything else, the changes the list's row already offers and,
     * while the bank statement does not explain the payment, the way to the statement.
     */
    protected function getHeaderActions(): array
    {
        $page = fn (): string => PaymentResource::getUrl('view', ['record' => $this->getRecord()]);

        return [
            ActionGroup::make([
                PaymentResource::historyAction(),
                DeleteAction::make(),
            ])->label(__('More actions')),
            PaymentResource::applyAdvanceAction()->color('gray')->after(fn () => $this->redirect($page())),
            PaymentResource::editAction()->icon(null)->color('gray')->successRedirectUrl($page),
            Action::make('reconcile')
                ->label(__('Reconcile with the bank'))
                ->visible(fn (): bool => BankStatementImport::canAccess() && ! ReconciliationColumns::for($this->getRecord())->isReconciled())
                ->url(fn (): string => BankStatementImport::getUrl()),
        ];
    }
}
