<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class FiscalDocumentPdf extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    protected $fillable = ['invoice_xpress_snapshot_id', 'user_id', 'storage_key', 'sha256', 'size'];

    public function path(): ?string
    {
        if (! is_string($this->storage_key) || ! Str::isUuid($this->storage_key)) {
            return null;
        }

        return "fiscal-document-pdfs/{$this->account_id}/{$this->fiscal_document_id}/{$this->storage_key}.pdf";
    }
}
