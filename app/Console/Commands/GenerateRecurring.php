<?php

namespace App\Console\Commands;

use App\Actions\GenerateRecurringExpenses;
use App\Actions\GenerateRetainerPeriods;
use App\Models\Account;
use App\Support\CurrentAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:generate-recurring')]
#[Description('Cria os valores a receber das avenças (próximos 30 dias) e as despesas recorrentes até hoje')]
class GenerateRecurring extends Command
{
    public function handle(GenerateRetainerPeriods $periods, GenerateRecurringExpenses $expenses): int
    {
        $receivables = 0;
        $created = 0;

        Account::query()->each(function (Account $account) use ($periods, $expenses, &$receivables, &$created) {
            CurrentAccount::run($account, function () use ($periods, $expenses, &$receivables, &$created) {
                $receivables += $periods->forAll();
                $created += $expenses->forAll();
            });
        });

        $this->info('Valores a receber criados: '.$receivables);
        $this->info('Despesas criadas: '.$created);

        return self::SUCCESS;
    }
}
