<?php

namespace App\Console\Commands;

use App\Actions\SendFinanceReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:send-reminders')]
#[Description('Avisa (no sino) os valores a receber que vencem esta semana ou ficaram vencidos')]
class SendReminders extends Command
{
    public function handle(SendFinanceReminders $reminders): int
    {
        $this->info('Avisos enviados: '.$reminders->run());

        return self::SUCCESS;
    }
}
