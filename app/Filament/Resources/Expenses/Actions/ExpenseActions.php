<?php

namespace App\Filament\Resources\Expenses\Actions;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\CurrentAccount;
use Filament\Actions\Action;
use Filament\Actions\ReplicateAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * What can be done to one expense, the same from its row in the list and from its own page:
 * marking it as paid, downloading its supporting document and duplicating it.
 */
final class ExpenseActions
{
    /**
     * "Marcar como paga": paid today. No policy knows about read-only accounts, so the button is
     * disabled there instead of hidden, and the permission to edit is checked again on the click.
     */
    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark expense as paid'))
            ->disabled(fn (): bool => CurrentAccount::readOnly())
            ->tooltip(fn (): ?string => CurrentAccount::readOnly() ? __('The account is read-only') : null)
            ->visible(fn (Expense $record): bool => ! $record->is_paid)
            ->action(function (Expense $record): void {
                ExpenseResource::authorizeEdit($record);
                $record->update(['is_paid' => true, 'paid_on' => today()]);

                Notification::make()->title(__('Expense marked as paid'))->success()->send();
            });
    }

    public static function downloadProof(): Action
    {
        return Action::make('downloadProof')
            ->label(__('Download supporting document'))
            ->extraAttributes(['download' => true])
            ->visible(fn (Expense $record): bool => $record->hasProof() && ExpenseResource::canView($record))
            ->url(fn (Expense $record): string => ExpenseResource::getUrl('download-proof', ['record' => $record]));
    }

    /** An unpaid copy dated today, without the original's supporting document, opened to be adjusted. */
    public static function duplicate(): ReplicateAction
    {
        return ReplicateAction::make()
            ->label(__('Duplicate'))
            ->modalDescription(__('Create an unpaid copy dated today so you can update what changed.'))
            ->excludeAttributes(['proof_path', 'proof_reference', 'is_reviewed'])
            ->beforeReplicaSaved(fn (Model $replica) => $replica->fill([
                'spent_on' => today(),
                'is_paid' => false,
                'paid_on' => null,
                'invoice_number' => null,
            ]))
            ->successNotificationTitle(__('Expense duplicated'))
            ->successRedirectUrl(fn (Model $replica) => ExpenseResource::getUrl('edit', ['record' => $replica]));
    }
}
