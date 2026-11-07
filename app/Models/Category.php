<?php

namespace App\Models;

use App\Enums\CategoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
        ];
    }

    /** @param Builder<Category> $query */
    public function scopeIncome(Builder $query): void
    {
        $query->where('type', CategoryType::Income)->orderBy('sort')->orderBy('name');
    }

    /** @param Builder<Category> $query */
    public function scopeExpense(Builder $query): void
    {
        $query->where('type', CategoryType::Expense)->orderBy('sort')->orderBy('name');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
