<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'OC-'.fake()->unique()->numerify('######'),
            'client_id' => Client::factory(),
            'title' => fake()->sentence(3),
            'quoted_on' => today(),
            'net_amount' => 100000, // cents
            'vat_rate' => 0,
            'status' => ProjectStatus::Approved,
        ];
    }
}
