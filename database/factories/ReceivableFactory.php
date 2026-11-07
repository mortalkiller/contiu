<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Receivable> */
class ReceivableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billable_type' => Project::class,
            'billable_id' => Project::factory(),
            'description' => 'Pagamento único',
            'amount' => 100000, // cents
            'due_on' => today()->addDays(30),
        ];
    }
}
