<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecurringExpense;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RecurringExpensePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RecurringExpense');
    }

    public function view(AuthUser $authUser, RecurringExpense $recurringExpense): bool
    {
        return $authUser->can('View:RecurringExpense');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RecurringExpense');
    }

    public function update(AuthUser $authUser, RecurringExpense $recurringExpense): bool
    {
        return $authUser->can('Update:RecurringExpense');
    }

    public function delete(AuthUser $authUser, RecurringExpense $recurringExpense): bool
    {
        return $authUser->can('Delete:RecurringExpense');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:RecurringExpense');
    }

    public function restore(AuthUser $authUser, RecurringExpense $recurringExpense): bool
    {
        return $authUser->can('Restore:RecurringExpense');
    }

    public function forceDelete(AuthUser $authUser, RecurringExpense $recurringExpense): bool
    {
        return $authUser->can('ForceDelete:RecurringExpense');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:RecurringExpense');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:RecurringExpense');
    }

    public function replicate(AuthUser $authUser, RecurringExpense $recurringExpense): bool
    {
        return $authUser->can('Replicate:RecurringExpense');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:RecurringExpense');
    }
}
