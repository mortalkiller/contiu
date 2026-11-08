<?php

namespace App\Models\Concerns;

use App\Exceptions\AccountIsReadOnly;
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

        // The real enforcement of read-only accounts: every business write passes here, whatever
        // screen, action or job started it. (The gate only hides the buttons.)
        static::saving(fn () => self::refuseWritesWhenReadOnly());
        static::deleting(fn () => self::refuseWritesWhenReadOnly());
    }

    private static function refuseWritesWhenReadOnly(): void
    {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
