<?php

namespace App\Support\MailPreviews;

use App\Filament\Pages\AccountSubscription;
use App\Mail\AccountantServiceRequested;
use App\Mail\ClientStatementEmail;
use App\Mail\FiscalDocumentEmail;
use App\Mail\PaymentReminder;
use App\Mail\SubscriptionPaid;
use App\Mail\TaxObligationsDigest;
use App\Mail\TrialEndingSoon;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\ClientStatement;
use App\Support\CurrentAccount;
use App\Support\TaxObligation;
use Carbon\CarbonImmutable;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;

/**
 * Every email Contiu sends, for the local preview page (/dev/mail-preview, routes/web.php). Each is
 * built with example data and rendered inside an account: the local database's first one (with a
 * client that has amounts open, when there is one), else an unsaved example account. Sending one
 * goes through the configured mailer, as the app would.
 */
final class MailPreviewRegistry
{
    /** @return array<string, string> */
    public function templateOptions(): array
    {
        return [
            'verify-email' => 'Confirm email (sign-up)',
            'password-reset' => 'Reset password',
            'trial-ending-soon' => 'Free trial ending',
            'tax-obligations-digest' => 'Tax obligations of the month',
            'payment-reminder' => 'Payment reminder (to a client)',
            'client-statement' => 'Account statement (to a client)',
            'subscription-paid' => 'Subscription paid (to super admins)',
            'accountant-service-requested' => 'Certified accountant requested (to super admins)',
            'fiscal-document' => 'Fiscal document (to a client)',
        ];
    }

    /** @return array<string, string> */
    public function localeOptions(): array
    {
        return ['pt_PT' => 'Português', 'en_US' => 'English'];
    }

    /** @return array<string, string> */
    public function themeOptions(): array
    {
        return ['light' => 'Light', 'dark' => 'Dark'];
    }

    public function defaultTemplate(): string
    {
        return 'trial-ending-soon';
    }

    public function defaultLocale(): string
    {
        return 'pt_PT';
    }

    public function normalizeTemplate(?string $template): string
    {
        return array_key_exists((string) $template, $this->templateOptions()) ? (string) $template : $this->defaultTemplate();
    }

    public function normalizeLocale(?string $locale): string
    {
        return array_key_exists((string) $locale, $this->localeOptions()) ? (string) $locale : $this->defaultLocale();
    }

    public function preview(string $template, ?string $locale = null): MailPreview
    {
        $template = $this->normalizeTemplate($template);
        $locale = $this->normalizeLocale($locale);
        $account = $this->account();
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return CurrentAccount::run($account, fn (): MailPreview => match ($template) {
                'verify-email' => $this->notification($template, $locale, $account, tap(new VerifyEmail, fn (VerifyEmail $notification) => $notification->url = url('/email-verification/verify/1/preview?signature=preview'))),
                'password-reset' => $this->notification($template, $locale, $account, tap(new ResetPassword('preview-token'), fn (ResetPassword $notification) => $notification->url = url('/password-reset/reset?token=preview-token&email=ana%40exemplo.pt'))),
                'trial-ending-soon' => $this->mailable($template, $locale, $account, new TrialEndingSoon($account, CarbonImmutable::today()->addDays(3), 'yearly', AccountSubscription::priceWithVat('yearly'))),
                'tax-obligations-digest' => $this->mailable($template, $locale, $account, new TaxObligationsDigest($this->obligations(), url('/'))),
                'payment-reminder' => $this->mailable($template, $locale, $account, new PaymentReminder($client = $this->client(), __('Payment reminder — :name', ['name' => $client->name]), __('Hello :name,', ['name' => $client->name])."\n\n".__('Please find below the amounts still open.'))),
                'client-statement' => $this->mailable($template, $locale, $account, new ClientStatementEmail(new ClientStatement($client = $this->client()), __('Account statement — :name', ['name' => $client->name]), __('Hello :name,', ['name' => $client->name])."\n\n".__('Your account statement is attached.'))),
                'subscription-paid' => $this->mailable($template, $locale, $account, new SubscriptionPaid($this->subscriptionPayment($account))),
                'accountant-service-requested' => $this->mailable($template, $locale, $account, new AccountantServiceRequested($account->forceFill(['accountant_service' => $account->accountant_service ?? 'simplified']))),
                // An unsaved example invoice without a PDF: it shows the text; a test send needs a real document (its attachment).
                'fiscal-document' => $this->mailable($template, $locale, $account, new FiscalDocumentEmail(FiscalDocument::factory()->make(['number' => 'FT 2026/15']), 'fiscal-documents/exemplo.pdf')),
                default => throw new InvalidArgumentException("Unknown mail preview [{$template}]."),
            });
        } finally {
            app()->setLocale($previous);
        }
    }

    private function notification(string $key, string $locale, Account $account, Notification $notification): MailPreview
    {
        $notification->locale($locale);
        /** @var MailMessage $message */
        $message = $notification->toMail(new User(['name' => 'Ana Silva', 'email' => 'ana@exemplo.pt']));

        return new MailPreview(
            key: $key,
            label: $this->templateOptions()[$key],
            locale: $locale,
            subject: (string) ($message->subject ?: config('app.name')),
            html: (string) $message->render(),
            sendUsing: fn (string $email) => CurrentAccount::run($account, fn () => NotificationFacade::route('mail', $email)->notifyNow($notification, ['mail'])),
        );
    }

    private function mailable(string $key, string $locale, Account $account, Mailable $mailable): MailPreview
    {
        $mailable->locale($locale);

        return new MailPreview(
            key: $key,
            label: $this->templateOptions()[$key],
            locale: $locale,
            subject: (string) ($mailable->envelope()->subject ?: config('app.name')),
            html: $mailable->render(),
            sendUsing: fn (string $email) => CurrentAccount::run($account, fn () => Mail::to($email)->sendNow($mailable)),
        );
    }

    private function account(): Account
    {
        return Account::query()->orderBy('id')->first()
            ?? tap(new Account(['name' => 'Ana Silva Design', 'nif' => '123456789']), fn (Account $account) => $account->forceFill(['id' => 0, 'slug' => 'exemplo']));
    }

    /** A client with amounts open when the local data has one, else an example without any. */
    private function client(): Client
    {
        $clients = Client::query()->orderBy('id')->limit(30)->get();

        return $clients->first(fn (Client $client): bool => $client->openReceivables()->isNotEmpty())
            ?? $clients->first()
            ?? tap(new Client(['name' => 'Estúdio Atlântico', 'email' => 'geral@atlantico.pt']), fn (Client $client) => $client->forceFill(['id' => 0]));
    }

    /** @return list<TaxObligation> */
    private function obligations(): array
    {
        $month = CarbonImmutable::today()->startOfMonth();

        return [
            new TaxObligation('ss:'.$month->format('Y-m'), 'ss', __('Social Security'), 4993, $month->setDay(20), $month->setDay(10), '', null, 'due'),
            new TaxObligation('vat-return:'.$month->subMonths(2)->format('Y-m'), 'vat-return', __('Periodic VAT return'), 31250, $month->setDay(20), null, '', null, 'due'),
            new TaxObligation('ss-declaration:'.$month->subMonths(3)->year.'-Q'.$month->subMonths(3)->quarter, 'ss-declaration', __('Quarterly Social Security return'), null, $month->subMonth()->endOfMonth()->startOfDay(), null, '', null, 'overdue'),
        ];
    }

    private function subscriptionPayment(Account $account): SubscriptionPayment
    {
        return tap(new SubscriptionPayment, fn (SubscriptionPayment $payment) => $payment->forceFill([
            'amount' => AccountSubscription::priceWithVat('yearly'),
            'paid_at' => now(),
            'period_start' => now(),
            'period_end' => now()->addYear(),
            'billing_address' => ['line1' => 'Rua do Porto 15, 2.º', 'postal_code' => '4050-123', 'city' => 'Porto', 'country' => 'PT'],
        ])->setRelation('account', $account));
    }
}
