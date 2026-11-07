<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receivable_id' => Receivable::factory(),
            'paid_on' => today(),
            'amount' => 10000, // cents
            'method' => PaymentMethod::Transfer,
            'tax_exempt' => false, // explicit, so tests not about exemptions don't depend on the default
        ];
    }
}
