<?php

namespace App\Models\Concerns;

use App\Enums\BankTransactionStatus;
use App\Models\BankTransactionLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Payments and expenses can explain a bank statement line. Deleting the record drops its link, and a
 * line left with nothing linked goes back to "Por tratar".
 */
trait ExplainsBankLine
{
    protected static function bootExplainsBankLine(): void
    {
        static::deleted(function (Model $record): void {
            $link = BankTransactionLink::query()
                ->where('linkable_type', $record->getMorphClass())
                ->where('linkable_id', $record->getKey())
                ->with('bankTransaction')
                ->first();

            if ($link === null) {
                return;
            }

            $link->delete();
            $line = $link->bankTransaction;

            if ($line !== null && $line->status === BankTransactionStatus::Matched && ! $line->links()->exists()) {
                $line->update(['status' => BankTransactionStatus::Pending]);
            }
        });
    }

    /** The bank statement line this explains, if any. @return MorphOne<BankTransactionLink, $this> */
    public function bankLink(): MorphOne
    {
        return $this->morphOne(BankTransactionLink::class, 'linkable');
    }
}
