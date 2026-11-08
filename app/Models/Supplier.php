<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToAccount, HasFactory;

    protected $guarded = ['id'];

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<RecurringExpense, $this> */
    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(RecurringExpense::class);
    }
}
