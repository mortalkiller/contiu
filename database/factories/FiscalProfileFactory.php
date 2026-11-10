<?php

namespace Database\Factories;

use App\Models\FiscalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalProfile> */
class FiscalProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'legal_name' => fake()->company(),
            'country_code' => 'PT',
            'vat_number' => 'PT'.fake()->numerify('5########'),
            'vat_regime' => 'Regime normal',
            'effective_from' => today(),
            'confirmed_at' => now(),
        ];
    }
}
