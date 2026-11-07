<?php

namespace App\Console\Commands;

use App\Actions\ImportFinanceWorkbook;
use App\Actions\ImportRefusedException;
use App\Actions\ImportResult;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:import-excel {path : Caminho do ficheiro .xlsx} {--replace : Apaga os dados financeiros atuais antes de importar} {--dry-run : Mostra o que seria importado sem gravar}')]
#[Description('Importa o Excel de controlo financeiro (Receitas, Pagamentos, Despesas, Listas)')]
class ImportExcel extends Command
{
    public function handle(ImportFinanceWorkbook $import): int
    {
        try {
            $result = $import->run($this->argument('path'), replace: (bool) $this->option('replace'), dryRun: (bool) $this->option('dry-run'));
        } catch (ImportRefusedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result->dryRun ? 'Pré-visualização (nada foi gravado):' : 'Importação concluída:');

        foreach (ImportResult::LABELS as $key => $label) {
            $this->line("  {$label}: ".($result->counts[$key] ?? 0));
        }

        $this->line('  Contratado: '.Money::format($result->contracted));
        $this->line('  Recebido: '.Money::format($result->received));
        $this->line('  Despesas: '.Money::format($result->expenses));

        foreach ($result->info as $info) {
            $this->line('<comment>ℹ '.$info.'</comment>');
        }

        if ($result->warnings !== []) {
            $this->warn(count($result->warnings).' aviso(s):');

            foreach ($result->warnings as $warning) {
                $this->warn('  • '.$warning);
            }
        }

        return self::SUCCESS;
    }
}
