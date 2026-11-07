<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['type' => CategoryType::Expense, 'name' => fake()->unique()->word()];
    }

    public function income(): static
    {
        return $this->state(['type' => CategoryType::Income]);
    }
}
