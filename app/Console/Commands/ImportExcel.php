<?php

namespace App\Console\Commands;

use App\Actions\ImportFinanceWorkbook;
use App\Actions\ImportRefusedException;
use App\Actions\ImportResult;
use App\Models\Account;
use App\Support\CurrentAccount;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:import-excel {path : Path to the .xlsx file} {--account= : Account slug to import into} {--replace : Delete current financial data before importing} {--dry-run : Preview the import without saving}')]
#[Description('Import the financial tracking workbook (income, payments, expenses, lists)')]
class ImportExcel extends Command
{
    public function handle(ImportFinanceWorkbook $import): int
    {
        $account = Account::where('slug', (string) $this->option('account'))->first();

        if ($account === null) {
            $this->error(__('Account not found: specify --account=<slug> (received: ":account").', [
                'account' => $this->option('account'),
            ]));

            return self::FAILURE;
        }

        try {
            $result = CurrentAccount::run($account, fn () => $import->run($this->argument('path'), replace: (bool) $this->option('replace'), dryRun: (bool) $this->option('dry-run')));
        } catch (ImportRefusedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result->dryRun ? __('Preview (nothing was saved):') : __('Import complete:'));

        foreach (ImportResult::LABELS as $key => $label) {
            $this->line('  '.__($label).': '.($result->counts[$key] ?? 0));
        }

        $this->line('  '.__('Contracted').': '.Money::format($result->contracted));
        $this->line('  '.__('Received').': '.Money::format($result->received));
        $this->line('  '.__('Expenses').': '.Money::format($result->expenses));

        foreach ($result->info as $info) {
            $this->line('<comment>ℹ '.$info.'</comment>');
        }

        if ($result->warnings !== []) {
            $this->warn(trans_choice(':count warning:|:count warnings:', count($result->warnings)));

            foreach ($result->warnings as $warning) {
                $this->warn('  • '.$warning);
            }
        }

        return self::SUCCESS;
    }
}
