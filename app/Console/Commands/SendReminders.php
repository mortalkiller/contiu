<?php

namespace App\Console\Commands;

use App\Actions\SendFinanceReminders;
use App\Models\Account;
use App\Support\CurrentAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:send-reminders')]
#[Description('Avisa (no sino) os valores a receber que vencem esta semana ou ficaram vencidos')]
class SendReminders extends Command
{
    public function handle(SendFinanceReminders $reminders): int
    {
        $sent = 0;

        Account::query()->each(function (Account $account) use ($reminders, &$sent) {
            $sent += CurrentAccount::run($account, fn () => $reminders->run());
        });

        $this->info('Avisos enviados: '.$sent);

        return self::SUCCESS;
    }
}
