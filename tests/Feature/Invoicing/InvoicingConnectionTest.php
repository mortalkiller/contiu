<?php

use App\Actions\ConnectInvoicingProvider;
use App\Actions\DisconnectInvoicingProvider;
use App\Enums\IntegrationCategory;
use App\Exceptions\InvoicingProviderException;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicing', 'tenancy');

/*
 * Connecting and disconnecting the invoicing provider: one per account, only by its owner, and
 * never losing documents on the way.
 */

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
});

it('connects after proving the credentials work and stores the key encrypted', function () {
    fakeInvoiceXpress();

    $connection = app(ConnectInvoicingProvider::class)->handle('invoicexpress', ['subdomain' => 'Contiu-Test', 'api_key' => 'secret-key-123']);

    expect($connection->provider)->toBe('invoicexpress')
        ->and($connection->credentials)->toBe(['subdomain' => 'contiu-test', 'api_key' => 'secret-key-123'])
        ->and($connection->getRawOriginal('credentials'))->not->toContain('secret-key-123')
        ->and($connection->setting('issue_from_app'))->toBeTrue()
        ->and(Invoicing::connected())->toBeTrue()
        ->and(Invoicing::providerLabel())->toBe('InvoiceXpress');
});

it('does not connect when the provider refuses the credentials', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([], 401)]);

    expect(fn () => app(ConnectInvoicingProvider::class)->handle('invoicexpress', ['subdomain' => 'contiu-test', 'api_key' => 'wrong']))
        ->toThrow(InvoicingProviderException::class);

    expect(IntegrationConnection::query()->count())->toBe(0);
});

it('never holds two invoicing providers for the same account', function () {
    invoicingConnection();

    expect(fn () => IntegrationConnection::factory()->create(['category' => IntegrationCategory::Invoicing, 'provider' => 'another']))
        ->toThrow(QueryException::class);
});

it('keeps each account to its own connection', function () {
    invoicingConnection();
    $other = Account::factory()->create();

    CurrentAccount::run($other, function (): void {
        expect(Invoicing::connected())->toBeFalse()
            ->and(IntegrationConnection::query()->count())->toBe(0);
    });
});

it('lets only the owner connect or disconnect', function () {
    invoicingConnection();
    $accountant = User::factory()->create(['email_verified_at' => now()]);
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
    $this->actingAs($accountant);

    expect(fn () => app(DisconnectInvoicingProvider::class)->handle())->toThrow(HttpException::class)
        ->and(fn () => app(ConnectInvoicingProvider::class)->handle('invoicexpress', ['subdomain' => 'x', 'api_key' => 'y']))->toThrow(HttpException::class);

    expect(IntegrationConnection::query()->count())->toBe(1);
});

it('refuses to disconnect while a draft waits at the provider, and keeps issued documents when it does', function () {
    invoicingConnection();
    $issued = FiscalDocument::factory()->create();
    $draft = FiscalDocument::factory()->draft()->create();

    expect(fn () => app(DisconnectInvoicingProvider::class)->handle())->toThrow(ValidationException::class);
    expect(Invoicing::connected())->toBeTrue();

    $draft->delete();
    app(DisconnectInvoicingProvider::class)->handle();

    expect(Invoicing::connected())->toBeFalse()->and($issued->fresh())->not->toBeNull();
});
