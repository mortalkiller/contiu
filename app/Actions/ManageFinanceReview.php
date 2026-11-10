<?php

namespace App\Actions;

use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FinanceReviewItem;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ManageFinanceReview
{
    public const MAX_BODY_LENGTH = 2000;

    public function add(FinanceReviewTarget $target, int $id, FinanceReviewKind $kind, string $body): void
    {
        DB::transaction(function () use ($target, $id, $kind, $body): void {
            $account = Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(AccountantAccess::canReview($account) && $account->hasAccess(), 403);

            $body = trim($body);
            Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:'.self::MAX_BODY_LENGTH]])->validate();

            $record = $target->query()->lockForUpdate()->findOrFail($id);
            $reference = $record instanceof Expense
                ? ($record->invoice_number ?: $record->description)
                : $record->number;

            FinanceReviewItem::create([
                $target->foreignKey() => $id,
                'target_type' => $target,
                'target_label' => Str::limit("#{$id} · {$reference}", 255, ''),
                'author_id' => auth()->id(),
                'kind' => $kind,
                'body' => $body,
            ]);
        });
    }

    public function resolve(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $account = Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(AccountantAccess::canResolve($account) && $account->hasAccess(), 403);

            $item = FinanceReviewItem::query()->lockForUpdate()->findOrFail($id);
            if ($item->isPending()) {
                $item->update(['resolved_at' => now(), 'resolved_by' => auth()->id()]);
            }
        });
    }
}
