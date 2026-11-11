<?php

use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\InvoiceXpressImport;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Pages\InvoiceXpressNoteIssuePage;
use App\Filament\Pages\InvoiceXpressReceiptPage;
use App\Filament\Pages\InvoiceXpressSettingsPage;
use App\Models\Account;
use App\Models\Client;
use App\Models\InvoiceXpressConfig;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceXpressClient;
use App\Support\InvoiceXpressIssueSettings;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

pest()->group('ui', 'invoicexpress');

function invoiceXpressNavigationHtml(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

function invoiceXpressNavigationTabs(DOMXPath $document): array
{
    $tabs = [];

    foreach ($document->query('//a[contains(@class, "fi-tabs-item") and ancestor::*[contains(@class, "fi-page-sub-navigation-tabs")]]') as $tab) {
        $tabs[trim($tab->textContent)] = [$tab->getAttribute('href'), str_contains($tab->getAttribute('class'), 'fi-active')];
    }

    return $tabs;
}

it('renders one InvoiceXpress menu item in Receitas and keeps the legacy tenant routes as tabs', function () {
    $this->actingAs(ownerOf($this->account));
    InvoiceXpressConfig::store('navigation-test', 'fake-key');
    Http::preventStrayRequests();
    asAWebRequest();

    $pages = [
        'Faturas' => InvoiceXpressIssuePage::getUrl(tenant: $this->account),
        'Recibos' => InvoiceXpressReceiptPage::getUrl(tenant: $this->account),
        'Notas de crédito e débito' => InvoiceXpressNoteIssuePage::getUrl(tenant: $this->account),
        'Importar' => InvoiceXpressImport::getUrl(tenant: $this->account),
        'Configuração' => url('/'.$this->account->slug.'/configurar-invoicexpress'),
    ];

    foreach ($pages as $active => $url) {
        $response = $this->get($url)->assertOk();
        $document = invoiceXpressNavigationHtml($response->getContent());
        expect(invoiceXpressNavigationTabs($document))->toBe(
            collect($pages)->map(fn (string $url, string $label): array => [$url, $label === $active])->all(),
        );

        $sidebar = $document->query('//li[contains(@class,"fi-sidebar-group")][.//*[contains(@class,"fi-sidebar-group-label") and normalize-space(.)="Receitas"]]//*[contains(@class,"fi-sidebar-item-label")]');
        $items = array_map(fn (DOMNode $label): string => trim($label->textContent), iterator_to_array($sidebar));
        expect(array_values(array_filter($items, fn (string $label): bool => str_contains($label, 'InvoiceXpress'))))->toBe(['InvoiceXpress']);
        $link = $document->query('//a[ancestor::li[contains(@class,"fi-active")] and .//*[contains(@class,"fi-sidebar-item-label") and normalize-space(.)="InvoiceXpress"]]')->item(0);
        expect($link?->getAttribute('href'))->toBe($pages['Faturas']);

        if ($active !== 'Configuração') {
            $response->assertDontSee('Artigos autorizados')->assertDontSee('Configurar ligação');
        }
    }

    expect(array_map(fn (string $url): string => basename(parse_url($url, PHP_URL_PATH)), $pages))
        ->toBe([
            'Faturas' => 'emitir-fatura-invoicexpress',
            'Recibos' => 'recibos-invoicexpress',
            'Notas de crédito e débito' => 'emitir-nota-invoicexpress',
            'Importar' => 'importar-invoicexpress',
            'Configuração' => 'configurar-invoicexpress',
        ]);
    Http::assertNothingSent();
});

it('configures the account and approved pilot settings from the configuration tab without provider writes', function () {
    $user = ownerOf($this->account);
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    Http::preventStrayRequests()->fake([
        'https://navigation-test.app.invoicexpress.com/clients.json?*' => Http::response(['clients' => []]),
        'https://navigation-test.app.invoicexpress.com/items.json?*' => Http::response(['items' => [['id' => 8, 'name' => 'Consultoria']]]),
        'https://navigation-test.app.invoicexpress.com/taxes.json?*' => Http::response(['taxes' => [['name' => 'IVA Contiu', 'region' => 'PT', 'value' => 23]]]),
        'https://navigation-test.app.invoicexpress.com/sequences.json?*' => Http::response(['sequences' => [
            ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234'],
        ]]),
    ]);

    Livewire::test(InvoiceXpressSettingsPage::class)
        ->callAction('configure', ['subdomain' => 'navigation-test', 'api_key' => 'fake-private-key'])
        ->assertHasNoActionErrors()
        ->assertSee('navigation-test')
        ->assertDontSee('fake-private-key')
        ->call('loadCatalog')
        ->assertSee('Artigos autorizados')
        ->set('approvedItems', ['8'])
        ->set('taxName', 'IVA Contiu')
        ->set('sequenceId', '19')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('A emissão aguarda a validação');

    expect(Setting::where('key', InvoiceXpressIssueSettings::Key)->value('value'))->toBe([
        'provider' => 'invoicexpress', 'user_id' => $user->id, 'subdomain' => 'navigation-test',
        'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
    ]);
    Http::assertSentCount(4);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('tests the saved connection and shows the demo series without requiring a registered series', function () {
    $this->actingAs(ownerOf($this->account));
    InvoiceXpressConfig::store('navigation-test', 'fake-private-key');
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    Http::preventStrayRequests()->fake([
        'https://navigation-test.app.invoicexpress.com/sequences.json?*' => Http::sequence()
            ->push(['sequences' => [
                ['id' => 19, 'serie' => 'INVOICEXPRESSDEMO', 'current_invoice_validation_code' => 'N/D'],
            ]])
            ->push(['errors' => [['message' => 'fake-private-key']]], 401),
    ]);

    $page = Livewire::test(InvoiceXpressSettingsPage::class)->assertSee('Testar ligação');
    Http::assertNothingSent();

    $page->call('testConnection')
        ->assertSee('Ligação confirmada')
        ->assertSee('INVOICEXPRESSDEMO')
        ->assertDontSee('fake-private-key')
        ->call('testConnection')
        ->assertHasErrors('connection')
        ->assertSee('O InvoiceXpress recusou a ligação')
        ->assertDontSee('Ligação confirmada')
        ->assertDontSee('INVOICEXPRESSDEMO')
        ->assertDontSee('fake-private-key');

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    expect(Setting::where('key', InvoiceXpressIssueSettings::Key)->exists())->toBeFalse();
});

it('saves the demo series in settings and creates drafts through the regular invoice form', function (string $type, string $sequenceId) {
    $this->actingAs(ownerOf($this->account));
    InvoiceXpressConfig::store('navigation-test', 'fake-key');
    config(['services.invoicexpress.sandbox_draft_account' => 'navigation-test']);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $item = ['id' => 8, 'name' => 'Artigo teste', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '10.15',
        'tax' => ['name' => 'IVA23', 'value' => '23.0']];
    $localClient = Client::factory()->create();
    $series = ['id' => 19, 'serie' => 'INVOICEXPRESSDEMO', 'current_invoice_sequence_id' => 19,
        'current_invoice_receipt_sequence_id' => 20, 'current_invoice_validation_code' => 'N/D'];

    Http::preventStrayRequests()->fake([
        '*/sequences.json?*' => Http::response(['sequences' => [$series, ['id' => 25, 'serie' => 'OUTRA']]]),
        '*/sequences/19.json?*' => Http::response(['sequence' => $series]),
        '*/clients.json?*' => Http::response(['clients' => [['id' => 7, 'name' => 'Cliente teste']]]),
        '*/clients/7.json?*' => Http::response(['client' => ['id' => 7, 'name' => 'Cliente teste']]),
        '*/taxes.json?*' => Http::response(['taxes' => [['id' => 1, 'name' => 'IVA23', 'region' => 'PT', 'code' => null, 'value' => 23]]]),
        '*/items.json?*' => Http::response(['items' => [$item]]),
        '*/items/8.json?*' => Http::response(['item' => $item]),
        "*/{$type}s.json?*" => Http::response([$type => [
            'id' => 100, 'status' => 'draft', 'before_taxes' => '20.30', 'taxes' => '4.67', 'total' => '24.97',
        ]]),
    ]);

    Livewire::test(InvoiceXpressSettingsPage::class)
        ->call('loadCatalog')
        ->assertSee('INVOICEXPRESSDEMO')
        ->assertDontSee('Criar rascunho')
        ->set('approvedItems', ['8'])
        ->set('taxName', 'IVA23')
        ->set('sequenceId', '19')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(InvoiceXpressIssueSettings::stored())->toMatchArray(['sequence_id' => 19, 'draft_only' => true]);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->call('loadCatalog')
        ->set('billingSource', 'direct')
        ->set('localClientId', (string) $localClient->id)
        ->set('dueOn', today()->toDateString())
        ->set('customerId', '7')
        ->set('itemId', '8')
        ->set('quantity', '2')
        ->set('documentType', $type)
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('Criar rascunho')
        ->assertDontSee('Confirmar e emitir')
        ->set('accepted', true)
        ->call('confirm')
        ->assertHasErrors('confirmation')
        ->call('createDraft')
        ->assertHasNoErrors()
        ->assertSee('Rascunho #100 criado');

    $posts = Http::recorded(fn (Request $request): bool => $request->method() !== 'GET');
    expect($posts)->toHaveCount(1);
    $payload = $posts->sole()[0]->data();
    expect($payload[$type]['sequence_id'])->toBe($sequenceId)
        ->and($payload[$type]['client'])->toBe(['id' => 7])
        ->and($payload[$type]['items'])->toBe([[
            'name' => 'Artigo teste', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => 10.15,
            'quantity' => 2, 'tax' => ['name' => 'IVA23'],
        ]]);
    expect(fn () => app(InvoiceXpressClient::class)->request('PUT', '/invoices/100/change-state.json'))
        ->toThrow(InvoiceXpressException::class);
    $this->assertDatabaseEmpty('fiscal_documents');
    $this->assertDatabaseEmpty('invoice_xpress_issue_attempts');
})->with([
    'invoice' => ['invoice', '19'],
    'invoice-receipt' => ['invoice_receipt', '20'],
]);

it('keeps read-only owners on the accessible tabs and denies access across tenants and in accountant review', function () {
    $this->actingAs(ownerOf($this->account));
    InvoiceXpressConfig::store('navigation-test', 'fake-key');
    $this->account->update(['trial_ends_at' => today()->subDay()]);
    $foreign = Account::factory()->create();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    Http::preventStrayRequests();
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    Livewire::test(InvoiceXpressSettingsPage::class)
        ->assertActionHidden('configure')
        ->call('saveSettings')->assertForbidden();
    asAWebRequest();

    $configUrl = InvoiceXpressSettingsPage::getUrl(tenant: $this->account);
    $importUrl = InvoiceXpressImport::getUrl(tenant: $this->account);
    $response = $this->get($configUrl)->assertOk()->assertDontSee('Configurar ligação')->assertDontSee('Artigos autorizados');
    expect(invoiceXpressNavigationTabs(invoiceXpressNavigationHtml($response->getContent())))->toBe([
        'Importar' => [$importUrl, false], 'Configuração' => [$configUrl, true],
    ]);
    $this->get(InvoiceXpressIssuePage::getUrl(tenant: $this->account))->assertForbidden();
    $this->get(InvoiceXpressSettingsPage::getUrl(tenant: $foreign))->assertNotFound();
    $this->actingAs($accountant)->get($configUrl)->assertForbidden();
    Http::assertNothingSent();
});
