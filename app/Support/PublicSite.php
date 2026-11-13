<?php

namespace App\Support;

use App\Filament\Pages\AccountSubscription;
use Filament\Facades\Filament;

/**
 * What the public site (resources/views/site) shows that comes from the app: the sign-in and
 * sign-up links into the panel and the prices, taken from the plans that are charged.
 */
final class PublicSite
{
    /** The panel's sign-in page (a signed-in user is sent on to their account from there). */
    public static function signInUrl(): string
    {
        return (string) Filament::getPanel('admin')->getLoginUrl();
    }

    /** The sign-up wizard, or the sign-in page while public sign-up is closed. */
    public static function signUpUrl(): string
    {
        return Filament::getPanel('admin')->getRegistrationUrl() ?? self::signInUrl();
    }

    /**
     * The plan box's two billing options: the price charged (without VAT) and the price with VAT.
     *
     * @return array{yearly: array{price: string, with_vat: string}, monthly: array{price: string, with_vat: string}}
     */
    public static function planPrices(): array
    {
        return collect(['yearly', 'monthly'])
            ->mapWithKeys(fn (string $plan): array => [$plan => [
                'price' => self::euros(AccountSubscription::PLANS[$plan]['amount']),
                'with_vat' => self::euros(AccountSubscription::priceWithVat($plan)),
            ]])
            ->all();
    }

    /**
     * The home page's price box (VAT included, a month): the plan alone or with the certified
     * accountant in each regime, paid monthly or yearly. The yearly plan is charged once a year,
     * so its monthly figure is the yearly price spread over twelve months.
     *
     * @return array{monthly: array{contiu: string, simplified: string, organised: string}, yearly: array{contiu: string, simplified: string, organised: string}, yearly_total: string}
     */
    public static function pricesWithVat(): array
    {
        $plan = [
            'monthly' => AccountSubscription::priceWithVat('monthly'),
            'yearly' => Money::round(AccountSubscription::priceWithVat('yearly') / 12),
        ];

        $row = fn (int $planCents): array => [
            'contiu' => self::euros($planCents),
            'simplified' => self::euros($planCents + AccountantService::priceWithVat('simplified')),
            'organised' => self::euros($planCents + AccountantService::priceWithVat('organised')),
        ];

        return [
            'monthly' => $row($plan['monthly']),
            'yearly' => $row($plan['yearly']),
            'yearly_total' => self::euros(AccountSubscription::priceWithVat('yearly')),
        ];
    }

    /** What the yearly plan costs a month, VAT included ("6,30 €"), for the headline: the yearly price spread over twelve months. */
    public static function lowestMonthlyPrice(): string
    {
        return self::euros(Money::round(AccountSubscription::priceWithVat('yearly') / 12));
    }

    /** The certified-accountant service a month, VAT included ("30 €"). */
    public static function accountantPrice(string $plan): string
    {
        return self::euros(AccountantService::priceWithVat($plan));
    }

    /** How much cheaper the yearly plan is than twelve monthly payments, in whole percent. */
    public static function yearlyDiscount(): int
    {
        $twelveMonths = AccountSubscription::PLANS['monthly']['amount'] * 12;

        return (int) round(100 * ($twelveMonths - AccountSubscription::PLANS['yearly']['amount']) / $twelveMonths);
    }

    /**
     * Euros as the site writes them, with a plain space as in the mocks: "54 €" for whole
     * amounts, "66,42 €" otherwise.
     */
    public static function euros(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;

        return number_format($cents / 100, $decimals, ',', ' ').' €';
    }
}
