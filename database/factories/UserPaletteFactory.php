<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPalette;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPalette>
 */
class UserPaletteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->colorName(),
            'primary_color' => '#9f1239',
            'navigation_color' => '#3b0d1a',
            'background_color' => '#faf5f5',
        ];
    }
}
