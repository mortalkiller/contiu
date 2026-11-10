<?php

namespace App\Models;

use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Exceptions\AccountIsReadOnly;
use App\Models\Concerns\BelongsToAccount;
use App\Support\CurrentAccount;
use Database\Factories\FinanceReviewItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceReviewItem extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<FinanceReviewItemFactory> */
    use HasFactory;

    protected $fillable = [
        'expense_id', 'fiscal_document_id', 'target_type', 'target_label',
        'author_id', 'kind', 'body', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => FinanceReviewTarget::class,
            'kind' => FinanceReviewKind::class,
            'resolved_at' => 'datetime',
        ];
    }

    // The review workflow authorizes these writes separately from finance writes.
    private static function refuseWritesWhenReadOnly(): void
    {
        if (CurrentAccount::get()?->hasAccess() === false) {
            throw new AccountIsReadOnly;
        }
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isPending(): bool
    {
        return $this->kind === FinanceReviewKind::EvidenceRequest && $this->resolved_at === null;
    }
}
