<?php

use App\Enums\IntegrationRecordState;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\InvoicingProviderPage;
use App\Filament\Pages\InvoicingSyncPage;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRecord;
use App\Models\User;
use App\Support\CurrentAccount;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

pest()->group('ui', 'invoicing');

it('shows the invoicing provider status and links to its page for the account in the URL', function (bool $connected, string $status, string $action) {
    $owner = ownerOf($this->account);
    $connectedAccount = $connected ? $this->account : Account::factory()->create();
    $this->actingAs($owner);
    CurrentAccount::run($connectedAccount, fn () => IntegrationConnection::factory()->create([
        'credentials' => ['subdomain' => 'connected-account', 'api_key' => 'private-test-key'],
    ]));
    Http::preventStrayRequests()->fake([]);
    asAWebRequest();

    $url = url("/{$this->account->slug}/integracoes");
    $response = $this->get($url)->assertOk();

    $response->assertDontSee('private-test-key')
        ->assertSeeText(__('Only one invoicing provider can be connected at a time.'));

    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $providerUrl = InvoicingProviderPage::getUrl(['provider' => 'invoicexpress'], tenant: $this->account);
    $actionLink = $document->querySelector('main a[href="'.$providerUrl.'"]');

    expect(trim($actionLink?->textContent ?? ''))->toBe(__($action))
        ->and(trim($actionLink?->closest('.fi-section')?->querySelector('.fi-badge')?->textContent ?? ''))->toBe(__($status));

    $navigationLink = $document->querySelector('.fi-sidebar-group a[href="'.$url.'"]');
    $groupLabel = $navigationLink?->closest('.fi-sidebar-group')?->querySelector('.fi-sidebar-group-label');

    expect(trim($navigationLink?->textContent ?? ''))->toBe(__('Integrations'));
    expect(trim($groupLabel?->textContent ?? ''))->toBe(__('Settings'));

    Http::assertNothingSent();
})->with([
    'only another account is connected' => [false, 'Not connected', 'Configure'],
    'the current account is connected' => [true, 'Connected', 'Manage'],
]);

it('says when the connected provider last synchronised and links to what differs', function () {
    $this->actingAs(ownerOf($this->account));
    $connection = IntegrationConnection::factory()->create(['last_synced_at' => now()->subMinutes(5)]);

    foreach ([[IntegrationRecordState::Differs, '9001'], [IntegrationRecordState::Differs, '9002'], [IntegrationRecordState::InSync, '9003']] as [$state, $remoteId]) {
        $record = new IntegrationRecord(['integration_connection_id' => $connection->getKey(), 'remote_type' => 'invoices', 'remote_id' => $remoteId, 'state' => $state]);
        $record->record()->associate(FiscalDocument::factory()->create());
        $record->save();
    }

    Http::preventStrayRequests()->fake([]);
    asAWebRequest();

    $response = $this->get(url("/{$this->account->slug}/integracoes"))->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $differences = $document->querySelector('main a[href="'.InvoicingSyncPage::getUrl(tenant: $this->account).'"]');

    $response->assertSeeText(__('Synchronised :when', ['when' => now()->subMinutes(5)->diffForHumans()]));
    expect(trim($differences?->textContent ?? ''))->toBe(trans_choice('{1} 1 difference|[2,*] :count differences', 2));

    Http::assertNothingSent();
});

it('keeps the integrations navigation active on an invoicing provider\'s page', function () {
    $this->actingAs(ownerOf($this->account));
    Http::preventStrayRequests()->fake([]);
    asAWebRequest();

    $response = $this->get(InvoicingProviderPage::getUrl(['provider' => 'invoicexpress'], tenant: $this->account))->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $integrationsUrl = IntegrationsPage::getUrl(tenant: $this->account);
    $navigationLink = $document->querySelector('.fi-sidebar-item-btn[href="'.$integrationsUrl.'"]');

    expect($navigationLink?->getAttribute('aria-current'))->toBe('page');

    Http::assertNothingSent();
});

it('marks invoice email as coming soon and blocks direct access by default', function () {
    $this->actingAs(ownerOf($this->account));
    asAWebRequest();

    $baseUrl = url("/{$this->account->slug}/integracoes");
    $response = $this->get($baseUrl)->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $card = $document->querySelector('main section[aria-labelledby="expenses-integrations-heading"] .fi-section');

    expect(trim($card?->querySelector('h3')?->textContent ?? ''))->toBe(__('Invoices by email'))
        ->and(trim($card?->querySelector('.fi-badge')?->textContent ?? ''))->toBe(__('Coming soon'))
        ->and($card?->querySelector('a'))->toBeNull();

    $this->get($baseUrl.'/faturas-por-email')->assertForbidden();
});

it('opens the enabled email inbox and a shared calendar subscription from the current account catalog', function () {
    config(['contiu.invoice_email_inbox_enabled' => true]);
    $this->actingAs(ownerOf($this->account));
    Http::preventStrayRequests()->fake([]);
    asAWebRequest();

    $baseUrl = url("/{$this->account->slug}/integracoes");
    $response = $this->get($baseUrl)->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);

    $inbox = $document->querySelector('main a[href="'.$baseUrl.'/faturas-por-email"]');
    $calendar = $document->querySelectorAll('main a[href="'.$baseUrl.'/subscricao-calendario"]');

    expect($inbox)->not->toBeNull()
        ->and($calendar->count())->toBe(2);

    $this->get($inbox->getAttribute('href'))->assertOk();
    $this->get($calendar->item(0)->getAttribute('href'))->assertOk();

    Http::assertNothingSent();
});

it('only offers the simulated email inbox from the catalog in local and testing environments', function () {
    config(['contiu.invoice_email_inbox_enabled' => true]);
    $this->app->instance('env', 'production');
    $this->actingAs(ownerOf($this->account));
    asAWebRequest();

    $baseUrl = url("/{$this->account->slug}/integracoes");
    $response = $this->get($baseUrl)->assertOk();
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);

    expect($document->querySelector('main a[href="'.$baseUrl.'/faturas-por-email"]'))->toBeNull()
        ->and($document->querySelectorAll('main a[href="'.$baseUrl.'/subscricao-calendario"]')->count())->toBe(2);
});

it('forbids access to integrations for an accountant on the account', function () {
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    asAWebRequest();

    $this->actingAs($accountant)->get(url("/{$this->account->slug}/integracoes"))->assertForbidden();
});

it('returns 404 for integrations on an account the owner cannot access', function () {
    $foreignAccount = Account::factory()->create();
    asAWebRequest();

    $this->actingAs(ownerOf($this->account))->get(url("/{$foreignAccount->slug}/integracoes"))->assertNotFound();
});

it('redirects guests from integrations to the panel login', function () {
    asAWebRequest();

    $this->get(url("/{$this->account->slug}/integracoes"))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
});
