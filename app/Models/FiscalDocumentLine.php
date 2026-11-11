<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\FiscalDocumentLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a fiscal document. Amounts are cents, rates are basis points and the quantity is in
 * thousandths, so nothing here is ever a float.
 */
class FiscalDocumentLine extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<FiscalDocumentLineFactory> */
    use HasFactory;

    /** How many thousandths make one unit. */
    public const int QUANTITY_SCALE = 1000;

    protected $fillable = [
        'sort', 'article_id', 'description', 'quantity', 'unit', 'unit_price', 'discount_basis_points',
        'vat_basis_points', 'net_amount', 'vat_amount', 'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_basis_points' => 'integer',
            'vat_basis_points' => 'integer',
            'net_amount' => 'integer',
            'vat_amount' => 'integer',
            'total_amount' => 'integer',
            'account_id' => 'integer',
        ];
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
