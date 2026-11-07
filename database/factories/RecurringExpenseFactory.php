<?php

namespace Database\Factories;

use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringExpense> */
class RecurringExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'kind' => ExpenseKind::Overhead,
            'description' => 'Subscrição',
            'net_amount' => 1000, // cents
            'vat_rate' => 0,
            'frequency' => Frequency::Monthly,
            'day' => 1,
            'method' => PaymentMethod::Card,
            'starts_on' => '2026-01-01',
            'is_active' => true,
        ];
    }
}
