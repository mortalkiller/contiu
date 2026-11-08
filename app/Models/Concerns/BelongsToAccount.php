<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Models\Scopes\AccountScope;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function ($model) {
            if (empty($model->account_id)) {
                $model->account_id = CurrentAccount::id();
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
