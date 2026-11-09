<?php

namespace App\Console\Commands;

use App\Filament\Pages\Dashboard;
use App\Mail\TaxObligationsDigest;
use App\Models\Account;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use ToneGabes\Filament\Icons\Enums\Phosphor;

#[Signature('finance:send-tax-obligations')]
#[Description('Envia por email as obrigações fiscais do mês (dia 1) e avisa no sino 3 dias antes de cada prazo')]
class SendTaxObligations extends Command
{
    public const DAYS_BEFORE = 3;

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;

        Account::query()->each(function (Account $account) use ($today, &$sent) {
            if (! $account->hasAccess()) {
                return;
            }

            $sent += CurrentAccount::run($account, fn () => $this->alertAccount($today));
        });

        $this->info('Alertas enviados: '.$sent);

        return self::SUCCESS;
    }

    private function alertAccount(CarbonImmutable $today): int
    {
        $recipients = CurrentAccount::get()->users;

        if ($recipients->isEmpty()) {
            return 0;
        }

        $pending = (new TaxObligations(new FinanceSettings, $today))->pending();
        $url = Dashboard::getUrl(panel: 'admin', tenant: CurrentAccount::get());
        $sent = 0;

        if ($today->day === 1 && $pending !== []) {
            foreach ($recipients as $user) {
                Mail::to($user)->queue(new TaxObligationsDigest($pending, $url));
            }
            $sent++;
        }

        foreach ($pending as $obligation) {
            if ($obligation->daysLeft($today) !== self::DAYS_BEFORE) {
                continue;
            }

            Notification::make()
                ->title($obligation->title.': faltam '.self::DAYS_BEFORE.' dias')
                ->body($obligation->detail)
                ->warning()
                ->icon(Phosphor::HourglassMedium)
                ->actions([Action::make('open')->label('Abrir painel')->url($url)])
                ->sendToDatabase($recipients);
            $sent++;
        }

        return $sent;
    }
}
