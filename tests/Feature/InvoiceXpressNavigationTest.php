<?php

use App\Filament\Pages\InvoiceXpressImport;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Pages\InvoiceXpressNoteIssuePage;
use App\Filament\Pages\InvoiceXpressReceiptPage;
use App\Filament\Pages\InvoiceXpressSettingsPage;
use App\Models\Account;
use App\Models\InvoiceXpressConfig;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceXpressIssueSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

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
