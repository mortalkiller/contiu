<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\DetailPage;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Payment;
use App\Support\Money;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;

/**
 * Page header of a payment received (discovered by convention), as in the mock: how much came in
 * with its state right after it, then one muted line saying who paid, when and how.
 */
class PaymentHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (Payment $record): string => static::titleLine($record), html: true)
                ->description(fn (Payment $record): string => static::identityLine($record), html: true),
        ]);
    }

    /** "Recebimento de 400,00 €": what the page is called, in its title, its breadcrumb and the browser tab. */
    public static function title(Payment $payment): string
    {
        return __('Payment of :amount', ['amount' => Money::format((int) $payment->amount)]);
    }

    /** "12/11/2026 · Estúdio Brisa": the payment as the breadcrumb names it. */
    public static function breadcrumb(Payment $payment): string
    {
        return implode(' · ', array_filter([DetailPage::date($payment->paid_on), $payment->client?->name]));
    }

    /** The title and its badges, as HTML. Every value is escaped. */
    public static function titleLine(Payment $payment): string
    {
        return DetailPage::titleWithBadges(static::title($payment), static::badges($payment));
    }

    /**
     * "Recebido", whether the bank statement already explains it (only with a bank account for
     * the activity, as on the list) and, for an advance, whether it still waits for a charge.
     *
     * @return list<array{label: string, color: string}>
     */
    public static function badges(Payment $payment): array
    {
        $reconciliation = ReconciliationColumns::enabled() ? ReconciliationColumns::for($payment) : null;

        return array_values(array_filter([
            ['label' => __('Received'), 'color' => 'success'],
            $reconciliation === null ? null : ['label' => $reconciliation->label(), 'color' => $reconciliation->status->color()],
            match (true) {
                ! $payment->isAdvance() => null,
                $payment->allocations->isEmpty() => ['label' => __('Advance to allocate'), 'color' => 'warning'],
                default => ['label' => __('Advance payment'), 'color' => 'gray'],
            },
        ]));
    }

    /** "Estúdio Brisa · recebido a 12/11/2026 · MB Way", as HTML, with the client as a link to its page. Every value is escaped. */
    public static function identityLine(Payment $payment): string
    {
        $client = $payment->client;
        $name = match (true) {
            $client === null => null,
            ClientResource::canView($client) => '<a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="'.e(ClientResource::getUrl('view', ['record' => $client])).'">'.e($client->name).'</a>',
            default => e($client->name),
        };

        return implode(' · ', array_filter([
            $name,
            e(__('received on :date', ['date' => DetailPage::date($payment->paid_on)])),
            e($payment->method?->getLabel()),
        ]));
    }
}
