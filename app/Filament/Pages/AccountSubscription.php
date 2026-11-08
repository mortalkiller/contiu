<?php

namespace App\Filament\Pages;

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
use InvalidArgumentException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Assinatura": choose monthly or yearly (Stripe Checkout) and, once subscribed, manage the card,
 * receipts and cancellation in Stripe's billing portal. Cashier's webhooks create and update the
 * subscription; nothing here writes it.
 *
 * Open to the account's owners (and super admins) whatever its state — a read-only account must
 * reach it to subscribe — so it is never behind a write ability. Exempt accounts never pay.
 */
class AccountSubscription extends Page
{
    protected static ?string $title = 'Assinatura';

    protected static ?string $slug = 'assinatura';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static ?int $navigationSort = 1;

    /** Amounts as charged by the Stripe prices in config('contiu.prices'): cents, no VAT. */
    public const PLANS = [
        'monthly' => ['label' => 'Mensal', 'amount' => 500, 'per' => 'por mês'],
        'yearly' => ['label' => 'Anual', 'amount' => 5400, 'per' => 'por ano'],
    ];

    /**
     * Stripe Checkout refuses a trial_end less than 48 hours away. A trial ending sooner is moved
     * to that minimum (as Cashier's own trialUntil() does), so the last days can still subscribe.
     */
    private const MINIMUM_TRIAL_MINUTES = 48 * 60 + 5;

    private const SUBSCRIBE_FAILURE = 'Não é possível subscrever agora';

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
                ->title('Obrigado!')
                ->body('A assinatura fica ativa assim que a Stripe confirmar o pagamento, normalmente em segundos.')
                ->success()
                ->send();
        }
    }

    public function getSubheading(): string
    {
        return 'Pagamento com cartão, através da Stripe. Sem IVA (isento ao abrigo do art. 53.º do CIVA).';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Estado')
                ->icon(Phosphor::Info)
                ->schema([Text::make(fn (): string => $this->statusText())]),
            Grid::make(['md' => 2])
                ->visible(fn (): bool => ! $this->account()->subscribed('default'))
                ->schema([
                    $this->planSection('monthly', $this->subscribeMonthlyAction()),
                    $this->planSection('yearly', $this->subscribeYearlyAction()),
                ]),
            Section::make('Gerir a assinatura')
                ->description('Mudar o cartão, ver os recibos ou cancelar, no portal de faturação da Stripe.')
                ->icon(Phosphor::Wrench)
                ->visible(fn (): bool => $this->account()->subscription('default') !== null)
                ->schema([Actions::make([$this->manageAction()])]),
        ]);
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
            ->label('Abrir o portal da Stripe')
            ->icon(Phosphor::ArrowSquareOut)
            ->color('gray')
            ->visible(fn (): bool => $this->account()->subscription('default') !== null)
            ->action(fn () => $this->callStripe(
                fn () => $this->redirect($this->account()->billingPortalUrl(static::getUrl())),
                failure: 'Não é possível abrir o portal da Stripe agora',
            ));
    }

    public function statusText(): string
    {
        $account = $this->account();
        $subscription = $account->subscription('default');

        if ($subscription?->valid()) {
            $plan = ($key = self::planFor($subscription->stripe_price)) ? ' '.mb_strtolower(self::PLANS[$key]['label']) : '';

            return match (true) {
                $subscription->onGracePeriod() => "A assinatura foi cancelada: a conta continua ativa até {$subscription->ends_at->format('d/m/Y')}. Podes retomá-la no portal da Stripe.",
                $subscription->pastDue() => 'O último pagamento falhou e a Stripe vai tentar de novo. Confirma o cartão no portal da Stripe.',
                $subscription->onTrial() => "Assinatura{$plan}: a primeira cobrança é a {$subscription->trial_ends_at->format('d/m/Y')}.",
                default => "Assinatura{$plan} ativa.",
            };
        }

        if ($account->onGenericTrial()) {
            return "Estás na experiência até {$account->trial_ends_at->format('d/m/Y')}. Se subscreveres agora, a primeira cobrança é só nesse dia.";
        }

        return 'A experiência terminou e não há uma assinatura ativa: a conta está só de leitura. Subscreve para voltar a registar.';
    }

    private function subscribeAction(string $plan): Action
    {
        return Action::make('subscribe'.ucfirst($plan))
            ->label('Subscrever '.mb_strtolower(self::PLANS[$plan]['label']))
            ->icon(Phosphor::CreditCard)
            ->color($plan === 'yearly' ? 'primary' : 'gray')
            ->visible(fn (): bool => ! $this->account()->subscribed('default'))
            ->action(fn () => $this->checkout($plan));
    }

    private function planSection(string $plan, Action $action): Section
    {
        $details = self::PLANS[$plan];
        $saving = 12 * self::PLANS['monthly']['amount'] - self::PLANS['yearly']['amount'];

        return Section::make($details['label'])
            ->description(Money::format($details['amount']).' '.$details['per'])
            ->schema([
                Text::make($plan === 'yearly'
                    ? 'Poupas '.Money::format($saving).' por ano em relação ao mensal.'
                    : 'Cancelas quando quiseres; a conta fica ativa até ao fim do mês pago.'),
                Actions::make([$action]),
            ]);
    }

    private function checkout(string $plan): void
    {
        $price = self::priceFor($plan);

        if ($price === null) {
            report(new RuntimeException("Stripe price for the {$plan} plan is not configured (contiu.prices.{$plan})."));

            $this->notifyFailure(self::SUBSCRIBE_FAILURE, 'Este plano ainda não está disponível. Tenta mais tarde; se continuar, contacta-nos.');

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

            $this->notifyFailure($failure, 'Não conseguimos falar com a Stripe. Tenta daqui a pouco; se continuar, contacta-nos.');
        }
    }

    private function notifyFailure(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }

    private function account(): Account
    {
        /** @var Account */
        return Filament::getTenant();
    }
}
