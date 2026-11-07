<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Receivable;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ReceivablePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Receivable');
    }

    public function view(AuthUser $authUser, Receivable $receivable): bool
    {
        return $authUser->can('View:Receivable');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Receivable');
    }

    public function update(AuthUser $authUser, Receivable $receivable): bool
    {
        return $authUser->can('Update:Receivable');
    }

    public function delete(AuthUser $authUser, Receivable $receivable): bool
    {
        return $authUser->can('Delete:Receivable');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Receivable');
    }

    public function restore(AuthUser $authUser, Receivable $receivable): bool
    {
        return $authUser->can('Restore:Receivable');
    }

    public function forceDelete(AuthUser $authUser, Receivable $receivable): bool
    {
        return $authUser->can('ForceDelete:Receivable');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Receivable');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Receivable');
    }

    public function replicate(AuthUser $authUser, Receivable $receivable): bool
    {
        return $authUser->can('Replicate:Receivable');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Receivable');
    }
}
