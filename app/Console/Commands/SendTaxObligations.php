<?php

namespace App\Console\Commands;

use App\Filament\Pages\Dashboard;
use App\Mail\TaxObligationsDigest;
use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;
use ToneGabes\Filament\Icons\Enums\Phosphor;

#[Signature('finance:send-tax-obligations')]
#[Description('Email the monthly tax obligations on the first and notify users three days before each deadline')]
class SendTaxObligations extends Command
{
    use Localizable;

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

        $this->info(__('Alerts sent: :count', ['count' => $sent]));

        return self::SUCCESS;
    }

    private function alertAccount(CarbonImmutable $today): int
    {
        $recipientGroups = CurrentAccount::get()->users->groupBy(fn (User $user): string => $user->preferredLocale());
        $sent = 0;

        foreach ($recipientGroups as $locale => $recipients) {
            $sent = max($sent, $this->withLocale($locale, fn (): int => $this->alertRecipients($today, $recipients)));
        }

        return $sent;
    }

    /** @param  Collection<int, User>  $recipients */
    private function alertRecipients(CarbonImmutable $today, Collection $recipients): int
    {
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
                ->title(__(':title: :count days left', ['title' => $obligation->title, 'count' => self::DAYS_BEFORE]))
                // A user can be in several accounts: say which one.
                ->body(CurrentAccount::get()->name.' · '.$obligation->detail)
                ->warning()
                ->icon(Phosphor::HourglassMedium)
                ->actions([Action::make('open')->label(__('Open dashboard'))->url($url)])
                ->sendToDatabase($recipients);
            $sent++;
        }

        return $sent;
    }
}
