<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'vat_number' => (string) fake()->numberBetween(500000000, 599999999),
            'email' => fake()->companyEmail(),
        ];
    }
}
