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
#[Description('Create retainer receivables for the next 30 days and recurring expenses through today')]
class GenerateRecurring extends Command
{
    public function handle(GenerateRetainerPeriods $periods, GenerateRecurringExpenses $expenses): int
    {
        $receivables = 0;
        $created = 0;

        Account::query()->each(function (Account $account) use ($periods, $expenses, &$receivables, &$created) {
            // A read-only account (trial over, nothing paying) gets nothing new until it subscribes.
            if (! $account->hasAccess()) {
                return;
            }

            CurrentAccount::run($account, function () use ($periods, $expenses, &$receivables, &$created) {
                $receivables += $periods->forAll();
                $created += $expenses->forAll();
            });
        });

        $this->info(__('Receivables created: :count', ['count' => $receivables]));
        $this->info(__('Expenses created: :count', ['count' => $created]));

        return self::SUCCESS;
    }
}
