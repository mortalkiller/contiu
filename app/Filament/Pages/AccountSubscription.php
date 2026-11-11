<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Filament\Pages\Tenancy\EditAccountProfile;
use App\Models\Account;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Assinatura": choose monthly or yearly (Stripe Checkout) and, once subscribed, manage the card,
 * receipts and cancellation in Stripe's billing portal. Cashier's webhooks create and update the
 * subscription; nothing here writes it.
 *
 * Open to the account's owners (and super admins) whatever its state — a read-only account must
 * reach it to subscribe — so it is never behind a write ability. Exempt accounts never pay. Only
 * the account's own members pay or manage it: a super admin giving support sees the state only.
 */
class AccountSubscription extends Page
{
    protected static ?string $slug = 'assinatura';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CreditCard;

    protected static ?int $navigationSort = 1;

    /** Amounts as charged by the Stripe prices in config('contiu.prices'): cents, no VAT. */
    public const PLANS = [
        'monthly' => ['label' => 'Monthly', 'amount' => 500, 'per' => 'per month'],
        'yearly' => ['label' => 'Yearly', 'amount' => 5400, 'per' => 'per year'],
    ];

    /**
     * Stripe Checkout refuses a trial_end less than 48 hours away. A trial ending sooner is moved
     * to that minimum (as Cashier's own trialUntil() does), so the last days can still subscribe.
     */
    private const MINIMUM_TRIAL_MINUTES = 48 * 60 + 5;

    private const SUBSCRIBE_FAILURE = 'Unable to subscribe right now';

    private ?bool $isMember = null;

    public static function canAccess(): bool
    {
        $account = Filament::getTenant();

        return $account instanceof Account
            && ! $account->billing_exempt
            && EditAccountProfile::canView($account);
    }

    /** The Stripe price of a plan; null while it is not configured (STRIPE_PRICE_* unset). */
    public static function priceFor(string $plan): ?string
    {
        if (! isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unknown plan [{$plan}].");
        }

        return config("contiu.prices.{$plan}") ?: null;
    }

    /** "monthly" for the monthly price; null for a price that is not one of ours. */
    public static function planFor(?string $price): ?string
    {
        foreach (array_keys(self::PLANS) as $plan) {
            if ($price !== null && $price === self::priceFor($plan)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Stripe Checkout options. The billing address is required (the legal invoice needs it) and
     * saved on the Stripe customer, so every invoice carries it. Subscribing during the trial
     * keeps the days left: the first charge is on the day the trial ends.
     *
     * @return array<string, mixed>
     */
    public static function checkoutOptions(Account $account, string $plan): array
    {
        if (! isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unknown plan [{$plan}].");
        }

        $options = [
            'success_url' => static::getUrl(['checkout' => 'success'], panel: 'admin', tenant: $account),
            'cancel_url' => static::getUrl(panel: 'admin', tenant: $account),
            'billing_address_collection' => 'required',
            'locale' => Language::resolve(app()->getLocale()) === Language::EnglishUnitedStates ? 'en' : 'pt',
            'customer_update' => ['address' => 'auto'],
        ];

        if ($account->trial_ends_at?->isFuture()) {
            $options['subscription_data'] = [
                'trial_end' => max($account->trial_ends_at->timestamp, now()->addMinutes(self::MINIMUM_TRIAL_MINUTES)->timestamp),
            ];
        }

        return $options;
    }

    public function mount(): void
    {
        // Back from Checkout: the subscription shows up once Stripe's webhook arrives.
        if (request()->query('checkout') === 'success') {
            Notification::make()
                ->title(__('Thank you!'))
                ->body(__('Your subscription becomes active as soon as Stripe confirms payment, usually within seconds.'))
                ->success()
                ->send();
        }
    }

    public function getSubheading(): string
    {
        return __('Pay by card through Stripe. VAT exempt under Article 53 of the Portuguese VAT Code.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Status'))
                ->icon(Phosphor::Info)
                ->schema([
                    Text::make(fn (): string => $this->statusText()),
                    Text::make(fn (): HtmlString => new HtmlString(
                        '<span class="fap-progress" role="img" aria-label="'.e($this->trialProgress()['label']).'"><i style="width: '.$this->trialProgress()['percent'].'%"></i></span>'
                        .'<span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">'.e($this->trialProgress()['label']).'</span>',
                    ))->visible(fn (): bool => $this->trialProgress() !== null),
                ]),
            Grid::make(['md' => 2])
                ->visible(fn (): bool => $this->isMember() && ! $this->account()->subscribed('default'))
                ->schema([
                    $this->planSection('monthly', $this->subscribeMonthlyAction()),
                    $this->planSection('yearly', $this->subscribeYearlyAction()),
                ]),
            Section::make(__('Manage subscription'))
                ->description(__('Change your card, view receipts or cancel in the Stripe billing portal.'))
                ->icon(Phosphor::Wrench)
                ->visible(fn (): bool => $this->isMember() && $this->account()->subscription('default') !== null)
                ->schema([Actions::make([$this->manageAction()])]),
        ]);
    }

    /**
     * How much of the free trial is used, counted in days from the account's creation to the end
     * of the trial. Null once subscribed, after the trial, or for an account without one.
     *
     * @return array{percent: int, label: string}|null
     */
    public function trialProgress(): ?array
    {
        $account = $this->account();

        if ($account->trial_ends_at === null || $account->trial_ends_at->isPast() || $account->created_at === null || $account->subscription('default') !== null) {
            return null;
        }

        $total = max(1, (int) ceil($account->created_at->diffInDays($account->trial_ends_at)));
        $left = min($total, (int) ceil(now()->diffInDays($account->trial_ends_at)));

        return [
            'percent' => (int) round(($total - $left) * 100 / $total),
            'label' => trans_choice('{1} 1 trial day left|[2,*] :count trial days left', max(1, $left)).' · '.__(':used of :total used', ['used' => $total - $left, 'total' => $total]),
        ];
    }

    public function subscribeMonthlyAction(): Action
    {
        return $this->subscribeAction('monthly');
    }

    public function subscribeYearlyAction(): Action
    {
        return $this->subscribeAction('yearly');
    }

    public function manageAction(): Action
    {
        return Action::make('manage')
            ->label(__('Open the Stripe portal'))
            ->icon(Phosphor::ArrowSquareOut)
            ->color('gray')
            ->visible(fn (): bool => $this->isMember() && $this->account()->subscription('default') !== null)
            ->action(fn () => $this->callStripe(
                fn () => $this->redirect($this->account()->billingPortalUrl(static::getUrl())),
                failure: __('Unable to open the Stripe portal right now'),
            ));
    }

    public function statusText(): string
    {
        $account = $this->account();
        $subscription = $account->subscription('default');

        if ($subscription?->valid()) {
            $plan = ($key = self::planFor($subscription->stripe_price)) ? ' '.mb_strtolower(__(self::PLANS[$key]['label'])) : '';

            return match (true) {
                $subscription->onGracePeriod() => __('The subscription was canceled. Your account stays active until :date. You can resume it in the Stripe portal.', ['date' => $subscription->ends_at->format(Language::resolve(app()->getLocale())->dateFormat())]),
                $subscription->pastDue() => __('The last payment failed and Stripe will retry. Check your card in the Stripe portal.'),
                $subscription->onTrial() => __('Subscription:plan: your first charge is on :date.', ['plan' => $plan, 'date' => $subscription->trial_ends_at->format(Language::resolve(app()->getLocale())->dateFormat())]),
                default => __('Subscription:plan active.', ['plan' => $plan]),
            };
        }

        if ($account->onGenericTrial()) {
            return __('Your trial ends on :date. If you subscribe now, your first charge will be on that day.', ['date' => $account->trial_ends_at->format(Language::resolve(app()->getLocale())->dateFormat())]);
        }

        return __('Your trial ended and you have no active subscription. The account is read-only. Subscribe to start recording again.');
    }

    private function subscribeAction(string $plan): Action
    {
        return Action::make('subscribe'.ucfirst($plan))
            ->label(__('Subscribe :plan', ['plan' => mb_strtolower(__(self::PLANS[$plan]['label']))]))
            ->icon(Phosphor::CreditCard)
            ->color($plan === 'yearly' ? 'primary' : 'gray')
            ->visible(fn (): bool => $this->isMember() && ! $this->account()->subscribed('default'))
            ->action(fn () => $this->checkout($plan));
    }

    private function planSection(string $plan, Action $action): Section
    {
        $details = self::PLANS[$plan];
        $saving = 12 * self::PLANS['monthly']['amount'] - self::PLANS['yearly']['amount'];

        return Section::make(__($details['label']))
            ->description(Money::format($details['amount'], locale: app()->getLocale()).' '.__($details['per']))
            ->schema([
                Text::make($plan === 'yearly'
                    ? __('Save :amount per year compared with monthly billing.', ['amount' => Money::format($saving, locale: app()->getLocale())])
                    : __('Cancel any time. Your account stays active until the end of the month you paid for.')),
                Actions::make([$action]),
            ]);
    }

    private function checkout(string $plan): void
    {
        $price = self::priceFor($plan);

        if ($price === null) {
            report(new RuntimeException("Stripe price for the {$plan} plan is not configured (contiu.prices.{$plan})."));

            $this->notifyFailure(__(self::SUBSCRIBE_FAILURE), __('This plan is unavailable. Try again later, and contact us if the problem continues.'));

            return;
        }

        $account = $this->account();

        $this->callStripe(fn () => $this->redirect(
            $account->newSubscription('default', $price)->checkout(self::checkoutOptions($account, $plan))->url,
        ), failure: self::SUBSCRIBE_FAILURE);
    }

    /** A Stripe failure reaches the user as a notification, not an error page. */
    private function callStripe(Closure $callback, string $failure): void
    {
        try {
            $callback();
        } catch (ApiErrorException $exception) {
            report($exception);

            $this->notifyFailure($failure, __('We couldn’t reach Stripe. Try again shortly, and contact us if the problem continues.'));
        }
    }

    private function notifyFailure(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }

    /** The signed-in user belongs to the account (super admins can open any without being in it). */
    private function isMember(): bool
    {
        return $this->isMember ??= $this->account()->users()->whereKey(auth()->id())->exists();
    }

    private function account(): Account
    {
        /** @var Account */
        return Filament::getTenant();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Subscription');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Subscription');
    }
}
