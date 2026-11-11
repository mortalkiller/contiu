<?php

namespace App\Support\Invoicing;

use App\Models\IntegrationConnection;
use App\Support\Invoicing\InvoiceXpress\InvoiceXpressProvider;
use InvalidArgumentException;

/** The billing providers the app knows. Adding one is a driver class and a line here. */
final class InvoicingProviders
{
    /** @var list<class-string<InvoicingProvider>> */
    private const array DRIVERS = [InvoiceXpressProvider::class];

    /** @return array<string, class-string<InvoicingProvider>> */
    public static function all(): array
    {
        $drivers = [];

        foreach (self::DRIVERS as $driver) {
            $drivers[$driver::key()] = $driver;
        }

        return $drivers;
    }

    /** @return class-string<InvoicingProvider> */
    public static function driver(string $key): string
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown billing provider [{$key}].");
    }

    public static function label(string $key): string
    {
        return isset(self::all()[$key]) ? self::driver($key)::label() : $key;
    }

    public static function for(IntegrationConnection $connection): InvoicingProvider
    {
        return new (self::driver($connection->provider))($connection);
    }
}
