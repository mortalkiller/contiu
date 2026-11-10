<?php

namespace App\Models;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FiscalDocument extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<FiscalDocumentFactory> */
    use HasFactory;

    protected $attributes = ['status' => FiscalDocumentStatus::Active->value];

    protected $fillable = [
        'type', 'direction', 'status', 'number', 'atcud', 'origin', 'document_date', 'operation_date',
        'currency', 'net_amount', 'vat_amount', 'total_amount', 'party_name', 'party_tax_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => FiscalDocumentType::class,
            'direction' => FiscalDocumentDirection::class,
            'status' => FiscalDocumentStatus::class,
            'document_date' => 'date',
            'operation_date' => 'date',
            'net_amount' => 'integer',
            'vat_amount' => 'integer',
            'total_amount' => 'integer',
            'account_id' => 'integer',
        ];
    }

    /** @return HasOne<FiscalDocumentLink, $this> */
    public function links(): HasOne
    {
        return $this->hasOne(FiscalDocumentLink::class);
    }

    /** @return HasMany<FiscalDocumentPdf, $this> */
    public function pdfVersions(): HasMany
    {
        return $this->hasMany(FiscalDocumentPdf::class);
    }

    /** @return HasOne<FiscalDocumentPdf, $this> */
    public function currentPdf(): HasOne
    {
        return $this->hasOne(FiscalDocumentPdf::class)->latestOfMany();
    }

    /** @return HasOne<InvoiceXpressSnapshot, $this> */
    public function invoiceXpressSnapshot(): HasOne
    {
        return $this->hasOne(InvoiceXpressSnapshot::class)->latestOfMany();
    }

    /** @return HasManyThrough<FiscalDocument, FiscalDocumentLink, $this> */
    public function relatedDocuments(): HasManyThrough
    {
        return $this->hasManyThrough(
            FiscalDocument::class,
            FiscalDocumentLink::class,
            'related_fiscal_document_id',
            'id',
            'id',
            'fiscal_document_id',
        );
    }
}
