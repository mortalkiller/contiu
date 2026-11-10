<?php

namespace Database\Factories;

use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Models\Expense;
use App\Models\FinanceReviewItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceReviewItem> */
class FinanceReviewItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'target_type' => FinanceReviewTarget::Expense,
            'target_label' => 'Despesa',
            'kind' => FinanceReviewKind::Comment,
            'author_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
