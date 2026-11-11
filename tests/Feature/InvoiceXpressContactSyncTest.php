<?php

use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Account;
use App\Models\Client;
use App\Models\InvoiceXpressConfig;
use App\Support\InvoiceXpressClient;
use App\Support\InvoiceXpressContactSync;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    InvoiceXpressConfig::store('contacts-test', 'fake-key');
    Http::preventStrayRequests();
});

it('imports and refreshes the same client by normalized NIF through the clients page', function () {
    Http::fake([
        '*/clients.json?*' => Http::response(['clients' => [
            ['id' => 12, 'fiscal_id' => 'PT508025338', 'country' => 'Portugal'],
        ], 'pagination' => ['current_page' => 1, 'total_pages' => 1]]),
        '*/clients/12.json?*' => Http::response(['client' => [
            'id' => 12, 'fiscal_id' => 'PT508025338', 'country' => 'Portugal', 'name' => 'Cliente InvoiceXpress',
            'email' => 'contacto@example.com', 'address' => 'Rua de Teste', 'language' => 'en',
        ]]),
    ]);

    $page = Livewire::test(ListClients::class)
        ->callAction('importInvoiceXpressClient', ['nif' => '508 025 338'])
        ->assertHasNoActionErrors();
    $client = Client::query()->sole();
    $client->update(['name' => 'Nome local', 'notes' => 'Nota privada']);
    $page->callAction('importInvoiceXpressClient', ['nif' => 'PT508025338'])->assertHasNoActionErrors();

    expect(Client::query()->sole()->id)->toBe($client->id)
        ->and($client->fresh()->name)->toBe('Cliente InvoiceXpress')
        ->and($client->fresh()->email)->toBe('contacto@example.com')
        ->and($client->fresh()->vat_number)->toBe('508025338')
        ->and($client->fresh()->language)->toBe('en-US')
        ->and($client->fresh()->notes)->toBe('Nota privada');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('ui');

it('creates then updates one remote client without opening document writes', function () {
    $local = Client::factory()->create([
        'vat_number' => 'PT508025338', 'fiscal_country_code' => 'PT', 'notes' => 'Nota privada', 'email' => 'existente@example.com',
    ]);
    $remote = null;
    Http::fake(function (Request $request) use (&$remote) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($request->method() === 'PUT' && $path === '/clients/12.json') {
            $remote = [...$request['client'], 'id' => 12];

            return Http::response(' ', 200);
        }

        return match ([$request->method(), $path]) {
            ['GET', '/clients.json'] => Http::response([
                'clients' => $remote === null ? [] : [$remote],
                'pagination' => ['current_page' => 1, 'total_pages' => 1],
            ]),
            ['GET', '/clients/12.json'] => Http::response(['client' => $remote]),
            ['POST', '/clients.json'] => Http::response([
                'client' => $remote = [...$request['client'], 'id' => 12],
            ]),
            default => throw new LogicException('Unexpected provider request'),
        };
    });

    Livewire::test(EditClient::class, ['record' => $local->getRouteKey()])
        ->callAction('syncInvoiceXpressClient', ['direction' => 'push'])->assertHasNoActionErrors();
    $local->update(['name' => 'Novo nome', 'email' => null, 'language' => 'en-US']);
    $sync = app(InvoiceXpressContactSync::class);
    expect($sync->push($local->id))->toBe(12)
        ->and($sync->push($local->id))->toBe(12)
        ->and($remote['name'])->toBe('Novo nome')
        ->and($remote['email'])->toBe('existente@example.com')
        ->and($remote['language'])->toBe('en')
        ->and($remote)->not->toHaveKeys(['notes', 'observations']);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount(1);
    expect(fn () => app(InvoiceXpressClient::class)->request('POST', '/invoices.json'))->toThrow(InvoiceXpressException::class);
})->group('ui');

it('refuses ambiguous NIFs across pages and excludes the generic final consumer', function () {
    $local = Client::factory()->create(['vat_number' => '508025338']);
    Http::fake([
        '*/clients.json?*' => Http::sequence()
            ->push(['clients' => [['id' => 12, 'fiscal_id' => '508025338']], 'pagination' => ['current_page' => 1, 'total_pages' => 2]])
            ->push(['clients' => [['id' => 13, 'fiscal_id' => 'PT508025338']], 'pagination' => ['current_page' => 2, 'total_pages' => 2]]),
    ]);

    $sync = app(InvoiceXpressContactSync::class);
    expect(fn () => $sync->push($local->id))->toThrow(ValidationException::class, 'mais do que um cliente')
        ->and(fn () => $sync->pull('999999990'))->toThrow(ValidationException::class);
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('rejects local duplicates and scopes client identifiers to the current account', function () {
    $local = Client::factory()->create(['vat_number' => '508025338', 'fiscal_country_code' => 'PT']);
    Client::factory()->create(['vat_number' => 'PT508 025 338', 'fiscal_country_code' => 'PT']);
    $foreign = Client::factory()->for(Account::factory())->create(['vat_number' => '508025338']);

    $sync = app(InvoiceXpressContactSync::class);
    expect(fn () => $sync->push($local->id))->toThrow(ValidationException::class, 'mais do que um cliente')
        ->and(fn () => $sync->push($foreign->id))->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
})->group('integration');
