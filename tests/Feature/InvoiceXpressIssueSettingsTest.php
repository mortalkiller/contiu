<?php

use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Setting;
use App\Models\User;
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
        '*/items.json?*' => Http::response(['items' => [['id' => 8, 'name' => 'Consultoria da API']]]),
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

it('lets an owner configure the pilot in the page without making provider calls', function () {
    invoiceXpressIssueReceivable();
    Setting::where('key', InvoiceXpressIssue::SettingsKey)->delete();
    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(InvoiceXpressIssuePage::class)
        ->assertSee('Configuração da emissão')
        ->assertDontSee('Pré-visualizar fatura')
        ->set('approvedItems', ['8', '12'])
        ->set('taxName', 'IVA Contiu')
        ->set('sequenceId', '19')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('Pré-visualizar fatura');

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->value('value'))->toBe([
        'provider' => 'invoicexpress', 'user_id' => auth()->id(), 'subdomain' => 'pilot',
        'item_ids' => [8, 12], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
    ]);
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
