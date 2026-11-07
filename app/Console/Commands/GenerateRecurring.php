<?php

namespace App\Console\Commands;

use App\Actions\GenerateRecurringExpenses;
use App\Actions\GenerateRetainerPeriods;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:generate-recurring')]
#[Description('Cria os valores a receber das avenças (próximos 30 dias) e as despesas recorrentes até hoje')]
class GenerateRecurring extends Command
{
    public function handle(GenerateRetainerPeriods $periods, GenerateRecurringExpenses $expenses): int
    {
        $this->info('Valores a receber criados: '.$periods->forAll());
        $this->info('Despesas criadas: '.$expenses->forAll());

        return self::SUCCESS;
    }
}
