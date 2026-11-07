<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Models\Client;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Retainer> */
class RetainerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'AV-'.fake()->unique()->numerify('####'),
            'client_id' => Client::factory(),
            'title' => 'Manutenção',
            'net_amount' => 2000, // cents
            'vat_rate' => 0,
            'frequency' => Frequency::Monthly,
            'due_day' => 1,
            'starts_on' => '2026-01-01',
            'is_active' => true,
        ];
    }
}
