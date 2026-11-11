<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\InvoiceEmailAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceEmailAttachment extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<InvoiceEmailAttachmentFactory> */
    use HasFactory;

    public const DISK = 'local';

    protected $fillable = ['message_hash', 'pdf_hash', 'file_id', 'filename', 'qr_text'];

    protected $hidden = ['message_hash', 'pdf_hash', 'file_id', 'qr_text'];

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function storagePath(): string
    {
        return "invoice-email/{$this->account_id}/{$this->file_id}.pdf";
    }
}
