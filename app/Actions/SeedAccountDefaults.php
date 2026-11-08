<?php

namespace App\Actions;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Support\CurrentAccount;

/**
 * The base categories every new account starts with. Idempotent, so it is safe to re-run.
 */
class SeedAccountDefaults
{
    private const INCOME = ['Serviços', 'Avenças', 'Outras receitas'];

    private const EXPENSE = [
        'Software e subscrições', 'Equipamento', 'Comunicações e internet', 'Deslocações',
        'Formação', 'Contabilidade', RecordOneOffIncome::COMMISSION_CATEGORY, 'Outras despesas',
    ];

    public function handle(Account $account): void
    {
        CurrentAccount::run($account, function () {
            foreach (self::INCOME as $name) {
                Category::firstOrCreate(['type' => CategoryType::Income, 'name' => $name]);
            }

            foreach (self::EXPENSE as $name) {
                Category::firstOrCreate(['type' => CategoryType::Expense, 'name' => $name]);
            }
        });
    }
}
