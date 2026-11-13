<?php

namespace App\Models;

use App\Enums\InvoiceImportStatus;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\InvoiceImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice dropped on "Importar faturas": the private file, what was read from it (`data`, in
 * cents), what the person chose for it (`choices`) and what it became (`outcome`).
 */
class InvoiceImport extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<InvoiceImportFactory> */
    use HasFactory;

    public const DISK = 'local';

    /** How many files one person can drop at a time, and wait on at once. */
    public const int BATCH = 20;

    /** How long the inbox keeps an invoice already dealt with, and one never confirmed. */
    public const int KEEP_HANDLED_DAYS = 30;

    public const int KEEP_WAITING_DAYS = 90;

    protected $fillable = ['user_id', 'file_id', 'filename', 'mime', 'bytes', 'sha256', 'dimensions', 'status', 'data', 'choices', 'outcome', 'error', 'handled_at'];

    protected $hidden = ['file_id', 'sha256'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceImportStatus::class,
            'dimensions' => 'array',
            'data' => 'array',
            'choices' => 'array',
            'outcome' => 'array',
            'handled_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @param Builder<InvoiceImport> $query */
    public function scopeWaiting(Builder $query): void
    {
        $query->whereIn('status', [InvoiceImportStatus::Queued, InvoiceImportStatus::Reading, InvoiceImportStatus::Read, InvoiceImportStatus::Unreadable]);
    }

    public function storagePath(): string
    {
        return "invoice-imports/{$this->account_id}/{$this->file_id}";
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
