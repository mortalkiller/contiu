<?php

namespace Database\Factories;

use App\Models\TaxObligationCompletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxObligationCompletion> */
class TaxObligationCompletionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => 'ss:'.fake()->unique()->date('Y-m'),
            'completed_at' => now(),
        ];
    }
}
