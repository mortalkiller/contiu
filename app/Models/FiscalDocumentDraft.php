<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\FiscalDocumentDraftFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FiscalDocumentDraft extends Model
{
    /** @use HasFactory<FiscalDocumentDraftFactory> */
    use BelongsToAccount, HasFactory;

    public const string Local = 'local';

    public const string Sending = 'sending';

    public const string Remote = 'remote';

    public const string Blocked = 'blocked';

    public const string Promoted = 'promoted';

    protected $attributes = ['state' => self::Local];

    protected $fillable = [
        'provider',
        'provider_account',
        'document_type',
        'payload',
        'preview',
        'remote_id',
        'fiscal_document_id',
        'revision',
        'state',
    ];

    protected $hidden = ['payload', 'preview', 'send_key'];

    protected static function booted(): void
    {
        static::saving(function (self $draft): void {
            if ($draft->provider === 'invoicexpress'
                && ! preg_match(InvoiceXpressConfig::SUBDOMAIN_PATTERN, (string) $draft->provider_account)) {
                throw ValidationException::withMessages([
                    'provider_account' => __('Enter only the subdomain of the InvoiceXpress account.'),
                ]);
            }
        });

        static::creating(function (self $draft): void {
            $draft->send_key ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'preview' => 'encrypted:array',
            'remote_id' => 'integer',
            'fiscal_document_id' => 'integer',
        ];
    }

    /** @return Attribute<string, string> */
    protected function providerAccount(): Attribute
    {
        return Attribute::make(set: fn (string $subdomain): string => strtolower(trim($subdomain)));
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
