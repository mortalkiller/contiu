<?php

namespace App\Models\Scopes;

use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fail closed: with no current account CurrentAccount::id() throws instead of leaking rows.
 */
class AccountScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('account_id'), CurrentAccount::id());
    }
}
