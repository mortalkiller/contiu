<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Support\InvoiceXpressEuService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FiscalOperationEvidence extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    protected $fillable = [
        'client_id', 'fiscal_profile_id', 'country_code', 'vat_number', 'operation_on',
        'document_on', 'document_operation_on', 'document_type', 'document_number', 'reason', 'vies_checked_at',
        'historical_vies_on', 'historical_vies_reference', 'net_amount', 'source', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'fiscal_profile_id' => 'integer',
            'operation_on' => 'immutable_date',
            'document_on' => 'immutable_date',
            'document_operation_on' => 'immutable_date',
            'vies_checked_at' => 'immutable_datetime',
            'historical_vies_on' => 'immutable_date',
            'net_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fiscal operation evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Fiscal operation evidence is immutable.'));
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    public function matchesDocument(FiscalDocument $document): bool
    {
        return InvoiceXpressEuService::isDocumentCandidate($document)
            && $document->type->value === $this->document_type
            && $document->number === $this->document_number
            && $document->net_amount === $this->net_amount
            && $document->document_date->toDateString() === $this->document_on?->toDateString()
            && $document->operation_date?->toDateString() === $this->document_operation_on?->toDateString()
            && InvoiceXpressEuService::catalogVat([
                'fiscal_id' => $document->party_tax_id,
                'country' => $this->country_code,
            ]) === $this->vat_number;
    }

    /** @return array{country: string, vat: string, operationOn: string, reason: string, clientId: int, fiscalProfileId: int, viesCheckedAt: string} */
    public function terms(): array
    {
        return [
            'country' => $this->country_code,
            'vat' => $this->vat_number,
            'operationOn' => $this->operation_on->toDateString(),
            'reason' => $this->reason,
            'clientId' => $this->client_id,
            'fiscalProfileId' => $this->fiscal_profile_id,
            'viesCheckedAt' => $this->vies_checked_at->toDateTimeString(),
        ];
    }
}
