<?php

namespace Database\Factories;

use App\Models\OneOffIncome;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OneOffIncome> */
class OneOffIncomeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'RA-'.fake()->unique()->numerify('########'),
            'title' => 'Donativo',
            'received_on' => today(),
            'net_amount' => 5,
        ];
    }
}
