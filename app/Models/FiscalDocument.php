<?php

namespace App\Models;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class FiscalDocument extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<FiscalDocumentFactory> */
    use HasFactory;

    protected $attributes = ['status' => FiscalDocumentStatus::Active->value];

    protected $fillable = [
        'type', 'direction', 'status', 'number', 'atcud', 'origin', 'document_date', 'operation_date',
        'currency', 'net_amount', 'vat_amount', 'total_amount', 'party_name', 'party_tax_id',
        'client_id', 'series', 'due_date', 'discount_amount', 'withholding_basis_points', 'withholding_amount',
        'vat_exemption_reason', 'client_reference', 'notes', 'reason', 'receivable_effect', 'payment', 'transport', 'issued_at', 'accepted_at',
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
            'due_date' => 'date',
            'discount_amount' => 'integer',
            'withholding_basis_points' => 'integer',
            'withholding_amount' => 'integer',
            'payment' => 'array',
            'transport' => 'array',
            'issued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'client_id' => 'integer',
            'account_id' => 'integer',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === FiscalDocumentStatus::Draft;
    }

    /** What the client actually pays: the total less what they withhold for the tax authority. */
    public function payableAmount(): int
    {
        return $this->total_amount - $this->withholding_amount;
    }

    /** @return HasMany<FiscalDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FiscalDocumentLine::class)->orderBy('sort')->orderBy('id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The document's counterpart at the billing provider, when it has one.
     *
     * @return MorphOne<IntegrationRecord, $this>
     */
    public function integrationRecord(): MorphOne
    {
        return $this->morphOne(IntegrationRecord::class, 'record');
    }

    /** @return HasOne<FiscalOperationEvidence, $this> */
    public function fiscalOperationEvidence(): HasOne
    {
        return $this->hasOne(FiscalOperationEvidence::class);
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
