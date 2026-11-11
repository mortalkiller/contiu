<?php

use App\Enums\PortugueseVatRegion;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Pages\InvoiceXpressSettingsPage;
use App\Models\Account;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressCatalog;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicexpress');

it('loads provider choices for issuance without writing to the catalogue', function () {
    invoiceXpressIssueReceivable();
    Http::preventStrayRequests();
    Http::fake([
        '*/clients.json?*' => Http::response(['clients' => [
            ['id' => 42, 'name' => 'Cliente da API', 'fiscal_id' => '508025338'],
            ['id' => 43, 'name' => 'Cliente espanhol da API', 'country' => 'Spain', 'fiscal_id' => 'A12345678'],
        ],
            'pagination' => ['current_page' => 1, 'total_pages' => 1]]),
        '*/items.json?*' => Http::response(['items' => [['id' => 8, 'name' => 'Consultoria da API', 'unit_price' => '101.25']]]),
        '*/taxes.json?*' => Http::response(['taxes' => [['name' => 'IVA Contiu', 'region' => 'PT', 'value' => 23]]]),
        '*/sequences.json?*' => Http::response(['sequences' => [
            ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234'],
            ['id' => 20, 'serie' => 'Sem registo', 'current_invoice_sequence_id' => 20, 'current_invoice_validation_code' => null],
        ]]),
    ]);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->call('loadCatalog')
        ->assertHasNoErrors()
        ->assertSee('Cliente da API')
        ->assertSee('Cliente espanhol da API · ESA12345678')
        ->assertSee('Consultoria da API')
        ->assertSet('catalog.itemPrices.8', 10125)
        ->assertSee('PILOTO')
        ->assertDontSee('Sem registo');

    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('ui');

it('keeps the pilot page and writes unavailable without explicit selection or an active owner', function () {
    $receivable = invoiceXpressIssueReceivable();
    Http::preventStrayRequests();
    Http::fake();
    Setting::where('key', InvoiceXpressIssue::SettingsKey)->delete();

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3))
        ->toThrow(HttpException::class);
    Http::assertNothingSent();
})->group('integration');

it('directs an owner to integrations when invoice preparation is not configured', function () {
    invoiceXpressIssueReceivable();
    Setting::where('key', InvoiceXpressIssue::SettingsKey)->delete();
    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(InvoiceXpressIssuePage::class)
        ->assertSee(IntegrationsPage::getUrl())
        ->assertDontSee(__('Preview :document', ['document' => mb_strtolower(__('Invoice'))]));

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->exists())->toBeFalse();
    Http::assertNothingSent();
})->group('ui');

it('rejects settings changes while a remote attempt is unresolved and leaves it recoverable', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('taxName', 'Outro IVA')
        ->call('saveSettings')
        ->assertHasErrors(['settings'])
        ->assertSee('Por confirmar no InvoiceXpress');

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->value('value')['tax_name'])->toBe('IVA Contiu')
        ->and(InvoiceXpressIssueAttempt::sole()->proprietary_uid)->toBe($attempt->proprietary_uid);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->call('recover', $attempt->id)
        ->assertHasNoErrors()
        ->assertSee('Por confirmar no InvoiceXpress');

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->map(fn ($pair) => $pair[0]['proprietary_uid'])->values()->all())
        ->toBe([$attempt->proprietary_uid, $attempt->proprietary_uid]);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
})->group('ui');

it('forbids accountants from configuring issuance or opening its page', function () {
    invoiceXpressIssueReceivable();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant);
    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(InvoiceXpressIssuePage::class)->assertForbidden();
    expect(fn () => app(InvoiceXpressIssueSettings::class)->save('12', 'Outro IVA', '22'))
        ->toThrow(HttpException::class);

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->value('value')['item_ids'])->toBe([8]);
    Http::assertNothingSent();
})->group('ui');

it('lets the owner confirm separate four percent taxes for each island region', function () {
    invoiceXpressIssueReceivable();
    $taxes = [
        ['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => 23],
        ['id' => 10, 'name' => 'IVA Açores 4%', 'region' => 'PT-AC', 'code' => 'RED', 'value' => '4.00'],
        ['id' => 11, 'name' => 'IVA Madeira 4%', 'region' => 'PT-MA', 'code' => 'RED', 'value' => 4],
        ['id' => 12, 'name' => 'Código incompatível', 'region' => 'PT-AC', 'code' => 'NOR', 'value' => 4],
        ['id' => 13, 'name' => 'Nome ambíguo', 'region' => 'PT-AC', 'code' => 'RED', 'value' => 4],
        ['id' => 14, 'name' => 'Nome ambíguo', 'region' => 'PT-MA', 'code' => 'RED', 'value' => 4],
    ];
    Http::preventStrayRequests()->fake([
        '*/clients.json?*' => Http::response(['clients' => []]),
        '*/items.json?*' => Http::response(['items' => [['id' => 8, 'name' => 'Consultoria']]]),
        '*/taxes.json?*' => Http::response(['taxes' => $taxes]),
        '*/sequences.json?*' => Http::response(['sequences' => [
            ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234'],
        ]]),
    ]);

    $page = Livewire::test(InvoiceXpressSettingsPage::class)
        ->call('loadCatalog')
        ->assertHasNoErrors()
        ->assertSee('IVA Açores 4%')
        ->assertSee('IVA Madeira 4%')
        ->assertDontSee('Código incompatível')
        ->assertDontSee('Nome ambíguo');

    expect(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Azores, 'reduced'))->toBeNull();

    $page->set('regionalTaxNames.PT-AC.reduced', 'IVA Madeira 4%')
        ->call('saveSettings')
        ->assertHasErrors('regionalTaxNames.PT-AC.reduced');
    expect(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Azores, 'reduced'))->toBeNull();

    $page->set('regionalTaxNames.PT-AC.reduced', 'IVA Açores 4%')
        ->set('regionalTaxNames.PT-MA.reduced', 'IVA Madeira 4%')
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(InvoiceXpressIssueSettings::stored()['tax_name'])->toBe('IVA Contiu')
        ->and(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Azores, 'reduced'))->toBe('IVA Açores 4%')
        ->and(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Madeira, 'reduced'))->toBe('IVA Madeira 4%')
        ->and(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Mainland, 'standard'))->toBeNull();

    Livewire::test(InvoiceXpressSettingsPage::class)
        ->assertSet('regionalTaxNames.PT-AC.reduced', 'IVA Açores 4%')
        ->call('loadCatalog')
        ->set('regionalTaxNames.PT-AC.reduced', '')
        ->call('saveSettings')
        ->assertHasNoErrors();
    expect(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Azores, 'reduced'))->toBeNull()
        ->and(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Madeira, 'reduced'))->toBe('IVA Madeira 4%');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('ui');

it('lists a tax without a code only for the configured sandbox', function () {
    invoiceXpressIssueReceivable();
    Http::preventStrayRequests();
    Http::fake(['*/taxes.json?*' => Http::response(['taxes' => [
        ['id' => 10, 'name' => 'IVA16', 'region' => 'PT-AC', 'code' => null, 'value' => '16.0'],
        ['id' => 11, 'name' => 'Wrong code', 'region' => 'PT-AC', 'code' => 'RED', 'value' => '16.0'],
        ['id' => 12, 'name' => 'Wrong rate', 'region' => 'PT-AC', 'code' => null, 'value' => '23.0'],
    ]])]);

    $catalog = app(InvoiceXpressCatalog::class);
    expect($catalog->regionalTaxes('pilot'))->toBe([]);

    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    expect(array_column($catalog->regionalTaxes('pilot')['PT-AC']['standard'] ?? [], 'name'))->toBe(['IVA16']);
})->group('integration');

it('keeps regional VAT mappings scoped to the tenant and its configured InvoiceXpress account', function () {
    $owner = actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Http::preventStrayRequests()->fake([
        'https://pilot.app.invoicexpress.com/taxes.json?*' => Http::response(['taxes' => [
            ['id' => 11, 'name' => 'IVA Madeira 22%', 'region' => 'PT-MA', 'code' => 'NOR', 'value' => 22],
        ]]),
    ]);
    $settings = app(InvoiceXpressIssueSettings::class);
    $settings->save('8', 'IVA Contiu', '19', regionalTaxNames: ['PT-MA' => ['standard' => 'IVA Madeira 22%']]);
    $settings->save('8', 'IVA Contiu', '19');

    expect(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Madeira, 'standard'))->toBe('IVA Madeira 22%');

    $otherAccount = Account::factory()->create();
    $otherAccount->users()->attach($owner, ['role' => 'owner']);
    CurrentAccount::run($otherAccount, function (): void {
        InvoiceXpressConfig::store('pilot', 'other-test-credential');

        expect(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Madeira, 'standard'))->toBeNull();
    });

    InvoiceXpressConfig::store('other-provider-account', 'fake-test-credential');

    expect(InvoiceXpressIssueSettings::regionalTaxName(PortugueseVatRegion::Madeira, 'standard'))->toBeNull();
    Http::assertSentCount(1);
})->group('integration');

it('requires the owner to look up the new InvoiceXpress account before confirming its settings', function () {
    invoiceXpressIssueReceivable();
    Http::preventStrayRequests()->fake([
        '*/clients.json?*' => Http::response(['clients' => []]),
        '*/items.json?*' => Http::response(['items' => [['id' => 8, 'name' => 'Consultoria']]]),
        '*/taxes.json?*' => Http::response(['taxes' => [
            ['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => 23],
        ]]),
        '*/sequences.json?*' => Http::response(['sequences' => [
            ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234'],
        ]]),
    ]);

    $page = Livewire::test(InvoiceXpressSettingsPage::class)->call('loadCatalog');
    InvoiceXpressConfig::store('other-provider-account', 'other-test-credential');

    $page->call('saveSettings')->assertHasErrors('settings');
    expect(InvoiceXpressIssueSettings::stored()['subdomain'])->toBe('pilot');

    $page->call('loadCatalog')
        ->assertHasNoErrors()
        ->assertSet('approvedItems', [])
        ->assertSet('taxName', '')
        ->assertSet('sequenceId', '');
    Http::assertSentCount(8);
})->group('ui');
