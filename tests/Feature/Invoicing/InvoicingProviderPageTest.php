<?php

use App\Enums\FiscalDocumentType;
use App\Filament\Pages\InvoicingProviderPage;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

pest()->group('invoicing', 'ui');

/*
 * The invoicing provider's own page: connecting with credentials that never come back to the
 * browser, and, once connected, what was read from the provider and the settings it keeps.
 */

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
});

function invoicingProviderPage(string $provider = 'invoicexpress'): Testable
{
    return Livewire::test(InvoicingProviderPage::class, ['provider' => $provider]);
}

/** Types the credentials and submits the form in one request, as the browser does. */
function submitInvoicingCredentials(Testable $page, string $subdomain, string $apiKey): Testable
{
    return $page->update(
        calls: [['method' => 'connect', 'params' => [], 'path' => '']],
        updates: ['credentials.subdomain' => $subdomain, 'credentials.api_key' => $apiKey],
    );
}

it('connects from the page and shows the connected state without the API key ever coming back', function () {
    fakeInvoiceXpress();

    $page = invoicingProviderPage()
        ->assertSee(__('Not connected'))
        ->assertSee(__('Connect to :provider', ['provider' => 'InvoiceXpress']))
        ->assertDontSee(__('Series and numbering'));

    submitInvoicingCredentials($page, 'contiu-test', 'secret-key-123')
        ->assertHasNoErrors()
        ->assertNotified(__('Connected to :provider', ['provider' => 'InvoiceXpress']))
        ->assertSee(__('Series and numbering'))
        ->assertSee('contiu-test.app.invoicexpress.com')
        ->assertDontSee(__('Connect to :provider', ['provider' => 'InvoiceXpress']));

    $connection = Invoicing::connection();

    expect($connection?->provider)->toBe('invoicexpress')
        ->and($connection->credentials['api_key'])->toBe('secret-key-123')
        ->and($page->html())->not->toContain('secret-key-123')
        ->and(json_encode($page->getData()))->not->toContain('secret-key-123');
});

it('never renders or keeps a stored API key', function () {
    invoicingConnection();
    fakeInvoiceXpress();

    $page = invoicingProviderPage()->assertSee(__('Connected'));

    expect($page->html())->not->toContain('secret-key-123')
        ->and(json_encode($page->getData()))->not->toContain('secret-key-123');
});

it('shows the provider\'s refusal and stores nothing when the credentials are wrong', function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([], 401)]);

    $page = submitInvoicingCredentials(invoicingProviderPage(), 'contiu-test', 'wrong-key-456')
        ->assertNotified(__('InvoiceXpress refused the connection. Check the account and API key.'))
        ->assertSee(__('Not connected'));

    expect(IntegrationConnection::query()->count())->toBe(0)
        ->and($page->html())->not->toContain('wrong-key-456')
        ->and(json_encode($page->getData()))->not->toContain('wrong-key-456');
});

it('drops a typed API key before answering any request', function () {
    $page = invoicingProviderPage()->set(['credentials.subdomain' => 'contiu-test', 'credentials.api_key' => 'typed-key-789']);

    expect(json_encode($page->getData()))->toContain('contiu-test')->not->toContain('typed-key-789')
        ->and($page->html())->not->toContain('typed-key-789');
});

it('stops answering once the user no longer owns the account', function () {
    $connection = invoicingConnection();
    fakeInvoiceXpress();
    $page = invoicingProviderPage();

    $this->account->users()->updateExistingPivot(auth()->id(), ['role' => AccountantAccess::ROLE]);

    $page->set('settings.auto_sync', false)->assertForbidden();

    expect($connection->fresh()->settings)->toBe([]);
});

it('puts a credential the provider cannot accept on its own field', function () {
    Http::preventStrayRequests();

    submitInvoicingCredentials(invoicingProviderPage(), 'https://contiu-test.app.invoicexpress.com', 'secret-key-123')
        ->assertHasErrors('credentials.subdomain');

    expect(IntegrationConnection::query()->count())->toBe(0);
});

it('gives 404 for a provider the app does not know', function () {
    invoicingProviderPage('unknown')->assertNotFound();
});

it('is only for the account\'s owner', function () {
    $accountant = User::factory()->create(['email_verified_at' => now()]);
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
    $this->actingAs($accountant);

    invoicingProviderPage()->assertForbidden();
});

it('saves a toggle as soon as it changes and ignores settings it does not know', function () {
    $connection = invoicingConnection();
    fakeInvoiceXpress();

    $page = invoicingProviderPage()
        ->assertSet('settings.auto_sync', true)
        ->set('settings.auto_sync', false)
        ->set('settings.is_admin', true);

    expect($connection->fresh()->settings)->toBe(['auto_sync' => false])
        ->and($page->get('settings'))->not->toHaveKey('is_admin');

    $page->set('settings.issue_from_app', false);

    expect($connection->fresh()->settings)->toBe(['auto_sync' => false, 'issue_from_app' => false]);
});

it('only keeps a series the provider has', function () {
    $connection = invoicingConnection();
    fakeInvoiceXpress();

    $page = invoicingProviderPage()->set('settings.series', '999');

    expect($connection->fresh()->setting('series'))->toBeNull();

    $page->set('settings.series', '5');

    expect($connection->fresh()->setting('series'))->toBe('5');
});

it('refuses a settings change on a read-only account', function () {
    $connection = invoicingConnection();
    fakeInvoiceXpress();
    $page = invoicingProviderPage();

    $this->account->forceFill(['trial_ends_at' => now()->subDay(), 'billing_exempt' => false])->save();

    $page->set('settings.auto_sync', false)->assertForbidden();

    expect($connection->fresh()->settings)->toBe([]);
});

it('refuses to disconnect while a draft waits and disconnects once it is gone', function () {
    invoicingConnection();
    fakeInvoiceXpress();
    $draft = FiscalDocument::factory()->draft()->create();
    $disconnect = TestAction::make('disconnect')->schemaComponent('connectionActions', 'content');

    $page = invoicingProviderPage()
        ->callAction($disconnect)
        ->assertNotified(trans_choice('{1} Issue or delete the draft waiting at the provider first.|[2,*] Issue or delete the :count drafts waiting at the provider first.', 1));

    expect(Invoicing::connected())->toBeTrue();

    $draft->delete();

    $page->callAction($disconnect)
        ->assertNotified(__('Disconnected from :provider', ['provider' => 'InvoiceXpress']))
        ->assertSee(__('Not connected'));

    Invoicing::forget();

    expect(Invoicing::connected())->toBeFalse();
});

it('shows the next number of each document type in the series', function () {
    invoicingConnection();
    fakeInvoiceXpress();

    invoicingProviderPage()
        ->assertSee(FiscalDocumentType::Invoice->getLabel())
        ->assertSee('FT 2026/15')
        ->assertSee('NC 2026/3')
        ->assertSee('RC 2026/5')
        ->assertSee('GT 2026/1')
        ->assertSee('IVA23');
});

it('still renders, with the provider\'s message, when the catalogue cannot be read', function () {
    invoicingConnection();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([], 500)]);

    invoicingProviderPage()
        ->assertOk()
        ->assertSee(__('InvoiceXpress refused the request or could not complete it.'))
        ->assertSee(__('Connected'))
        ->assertSee(__('Automations'))
        ->assertDontSee('FT 2026/15');
});

it('tells the owner to disconnect another provider first, without a form', function () {
    IntegrationConnection::factory()->create(['provider' => 'another']);
    Invoicing::forget();
    Http::preventStrayRequests();

    invoicingProviderPage()
        ->assertSee(__(':other is connected. Disconnect it before connecting :provider.', ['other' => 'another', 'provider' => 'InvoiceXpress']))
        ->assertDontSee(__('Connect to :provider', ['provider' => 'InvoiceXpress']))
        ->assertDontSee(__('Series and numbering'));
});
