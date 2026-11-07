<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Retainer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RetainerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Retainer');
    }

    public function view(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('View:Retainer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Retainer');
    }

    public function update(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('Update:Retainer');
    }

    public function delete(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('Delete:Retainer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Retainer');
    }

    public function restore(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('Restore:Retainer');
    }

    public function forceDelete(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('ForceDelete:Retainer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Retainer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Retainer');
    }

    public function replicate(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('Replicate:Retainer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Retainer');
    }

    public function audit(AuthUser $authUser, Retainer $retainer): bool
    {
        return $authUser->can('Audit:Retainer');
    }
}
