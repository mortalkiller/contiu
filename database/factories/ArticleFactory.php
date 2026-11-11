<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Article> */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('ART-####'),
            'name' => fake()->words(3, true),
            'unit' => 'un',
            'unit_price' => 4500,
        ];
    }
}
