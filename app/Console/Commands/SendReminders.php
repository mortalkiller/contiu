<?php

namespace App\Console\Commands;

use App\Actions\SendFinanceReminders;
use App\Models\Account;
use App\Support\CurrentAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:send-reminders')]
#[Description('Send notifications for receivables due this week or overdue')]
class SendReminders extends Command
{
    public function handle(SendFinanceReminders $reminders): int
    {
        $sent = 0;

        Account::query()->each(function (Account $account) use ($reminders, &$sent) {
            // Read-only accounts are skipped: reminders also record that they were sent.
            if (! $account->hasAccess()) {
                return;
            }

            $sent += CurrentAccount::run($account, fn () => $reminders->run());
        });

        $this->info(__('Reminders sent: :count', ['count' => $sent]));

        return self::SUCCESS;
    }
}
