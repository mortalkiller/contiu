<?php

namespace Database\Factories;

use App\Enums\ExpenseKind;
use App\Models\Expense;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'kind' => ExpenseKind::Overhead,
            'description' => 'Despesa',
            'spent_on' => today(),
            'net_amount' => 1000, // cents
            'vat_rate' => 0,
            'is_paid' => true,
            'paid_on' => today(),
        ];
    }

    public function unpaid(): static
    {
        return $this->state(['is_paid' => false, 'paid_on' => null]);
    }
}
