<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use ToneGabes\Filament\Icons\Enums\Phosphor;

enum PaymentMethod: string implements HasIcon, HasLabel
{
    case Transfer = 'transfer';
    case MbWay = 'mbway';
    case Card = 'card';
    case Cash = 'cash';
    case Stripe = 'stripe';
    case Other = 'other';

    public function getLabel(?string $locale = null): string
    {
        return match ($this) {
            self::Transfer => __('Bank transfer', [], $locale),
            self::MbWay => 'MB Way',
            self::Card => __('Card', [], $locale),
            self::Cash => __('Cash', [], $locale),
            self::Stripe => 'Stripe',
            self::Other => __('Other', [], $locale),
        };
    }

    public function getIcon(): Phosphor
    {
        return match ($this) {
            self::Transfer => Phosphor::Bank,
            self::MbWay => Phosphor::DeviceMobile,
            self::Card => Phosphor::CreditCard,
            self::Cash => Phosphor::Money,
            self::Stripe => Phosphor::StripeLogo,
            self::Other => Phosphor::DotsThreeCircle,
        };
    }
}
