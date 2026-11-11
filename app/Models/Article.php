<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something billed often, with its usual unit and price. Optional: a document line can always be
 * written freely, and a line taken from an article can change its text and price for that document.
 */
class Article extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'unit', 'unit_price', 'vat_basis_points', 'is_active'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'vat_basis_points' => 'integer',
            'is_active' => 'boolean',
            'account_id' => 'integer',
        ];
    }

    /** @return HasMany<FiscalDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FiscalDocumentLine::class);
    }
}
