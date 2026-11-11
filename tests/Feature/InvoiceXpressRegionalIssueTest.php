<?php

use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Setting;
use App\Support\InvoiceXpressDraftDelivery;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueTerms;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

it('distinguishes reduced VAT in the Azores from reduced VAT in Madeira', function () {
    $taxes = [
        ['id' => 14, 'name' => 'IVA Açores 4', 'region' => 'PT-AC', 'code' => 'RED', 'value' => 4],
        ['id' => 24, 'name' => 'IVA Madeira 4', 'region' => 'PT-MA', 'code' => 'RED', 'value' => 4],
    ];

    expect(InvoiceXpressIssueTerms::tax($taxes, 'IVA Açores 4', 'PT-AC', 400)['id'])->toBe(14)
        ->and(InvoiceXpressIssueTerms::tax($taxes, 'IVA Madeira 4', 'PT-MA', 400)['id'])->toBe(24)
        ->and(fn () => InvoiceXpressIssueTerms::tax($taxes, 'IVA Madeira 4', 'PT-AC', 400))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressIssueTerms::tax([...$taxes, ['id' => 34, 'name' => 'IVA Açores 4', 'region' => 'PT-MA', 'code' => 'RED', 'value' => 4]], 'IVA Açores 4', 'PT-AC', 400))
        ->toThrow(ValidationException::class);
});

it('issues with the reviewed regional VAT and keeps the choice in the attempt', function (string $region, string $category, array $tax, int $rate, int $vat, int $gross, bool $recoverImported = false) {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->billable->update(['vat_rate' => $rate / 10000]);
    $receivable->update(['amount' => $gross]);
    $setting = Setting::query()->where('key', InvoiceXpressIssue::SettingsKey)->firstOrFail();
    $setting->update(['value' => [...$setting->value, 'regional_tax_names' => [$region => [$category => $tax['name']]]]]);
    $domesticVat = ['region' => $region, 'category' => $category];
    $status = 'draft';
    $official = null;
    fakeRegionalInvoiceXpress($tax, function (Request $request) use (&$status, &$official, $tax, $vat, $gross, $recoverImported) {
        if ($request->method() === 'POST') {
            return Http::response(['invoice' => ['id' => 65]], 201);
        }

        if ($request->method() === 'PUT') {
            $status = 'final';

            return Http::response(['invoice' => ['id' => 65, 'status' => 'final']]);
        }

        $response = invoiceXpressIssueResponse($status);
        $response['invoice']['items'][0]['tax'] = array_diff_key($tax, ['region' => true]);
        $response['invoice']['items'][0]['tax_amount'] = $response['invoice']['taxes'] = $vat / 100;
        $response['invoice']['items'][0]['total'] = $response['invoice']['total'] = $gross / 100;

        if ($status === 'final' && $recoverImported && $official === null) {
            $official = $response;
            if ($request->method() === 'GET') {
                return Http::response([], 503);
            }
        }

        return Http::response($response);
    });

    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3, domesticVat: $domesticVat);

    expect($preview['totals'])->toBe(['net' => 30015, 'tax' => $vat, 'total' => $gross])
        ->and($preview['domesticVat'])->toBe($domesticVat)
        ->and($preview['tax'])->toMatchArray(['id' => $tax['id'], 'region' => $region, 'basisPoints' => $rate]);
    $attempt = $issue->confirm($receivable->id, 42, 8, 3, $preview['revision'], domesticVat: $domesticVat);
    if ($recoverImported) {
        expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);
        $imported = app(InvoiceXpressImport::class)->confirmInvoice($official);
        $attempt = $issue->recover($attempt->id);
        expect($attempt->fiscal_document_id)->toBe($imported->id);
    }

    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($attempt->fresh()->snapshot['domesticVat'])->toBe($domesticVat)
        ->and(FiscalDocument::findOrFail($attempt->fiscal_document_id)->invoiceXpressSnapshot->source['tax_lines'][0]['tax']['region'])->toBe($region);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['invoice']['items'][0]['tax'] === ['name' => $tax['name']]);
})->with([
    'mainland standard' => ['PT', 'standard', ['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => 23], 2300, 6903, 36918],
    'Azores reduced' => ['PT-AC', 'reduced', ['id' => 14, 'name' => 'IVA Açores 4', 'region' => 'PT-AC', 'code' => 'RED', 'value' => 4], 400, 1201, 31216],
    'Madeira reduced with recovery after a manual import' => ['PT-MA', 'reduced', ['id' => 24, 'name' => 'IVA Madeira 4', 'region' => 'PT-MA', 'code' => 'RED', 'value' => 4], 400, 1201, 31216, true],
]);

it('rejects another returned tax region even if the id, name, rate and amounts match', function () {
    $expected = ['name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'quantity' => 3];
    $tax = ['id' => 14, 'name' => 'IVA Açores 4', 'region' => 'PT-AC', 'basisPoints' => 400];
    $line = [...$expected, 'unit_price' => '100.05', 'subtotal' => '300.15', 'tax_amount' => '12.01', 'total' => '312.16',
        'tax' => ['id' => 14, 'name' => 'IVA Açores 4', 'value' => 4, 'region' => 'PT-MA']];

    expect(InvoiceXpressIssueTerms::matchesLine($line, $expected, ['unitCents' => 10005], $tax, ['net' => 30015, 'tax' => 1201, 'total' => 31216]))
        ->toBeFalse();
});

it('checks a returned VAT code against legacy tax snapshots without requiring a code in the response', function (array $code, bool $matches) {
    $expected = ['name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'quantity' => 3];
    $snapshot = ['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'basisPoints' => 2300];
    $tax = ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23, ...$code];
    $line = [...$expected, 'unit_price' => '100.05', 'subtotal' => '300.15', 'tax_amount' => '69.03', 'total' => '369.18',
        'tax' => $tax];

    expect(InvoiceXpressIssueTerms::matchesItemTax($tax, $snapshot))->toBe($matches)
        ->and(InvoiceXpressIssueTerms::matchesLine($line, $expected, ['unitCents' => 10005], $snapshot, ['net' => 30015, 'tax' => 6903, 'total' => 36918]))
        ->toBe($matches);
})->with([
    'contradictory code' => [['code' => 'RED'], false],
    'matching code' => [['code' => 'NOR'], true],
    'code omitted' => [[], true],
]);

it('uses the legacy mainland tax name for an explicit choice only when the catalog verifies the tax', function (array $change, bool $valid) {
    $receivable = invoiceXpressIssueReceivable();
    $tax = array_replace(['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => 23], $change);
    fakeRegionalInvoiceXpress($tax, fn () => throw new LogicException('Unexpected write.'));
    $preview = fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3, domesticVat: ['region' => 'PT', 'category' => 'standard']);

    if ($valid) {
        expect($preview()['tax'])->toMatchArray(['id' => 9, 'region' => 'PT', 'basisPoints' => 2300]);
    } else {
        expect($preview)->toThrow(fn (ValidationException $error) => expect($error->errors())->toHaveKey('tax'));
    }

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with([
    'matching tax' => [[], true],
    'wrong region' => [['region' => 'PT-AC'], false],
    'wrong rate' => [['value' => 22], false],
    'wrong code' => [['code' => 'RED'], false],
    'missing code' => [['code' => null], false],
]);

it('rejects a regional receivable when its VAT rate disagrees with the selection', function () {
    $receivable = invoiceXpressIssueReceivable();
    Http::preventStrayRequests();

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3,
        domesticVat: ['region' => 'PT-MA', 'category' => 'standard']))
        ->toThrow(fn (ValidationException $error) => expect($error->errors())->toHaveKey('vatCategory'));
    Http::assertNothingSent();
});

it('requires an island tax mapping and a matching catalog item', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->billable->update(['vat_rate' => '0.0400']);
    $setting = Setting::query()->where('key', InvoiceXpressIssue::SettingsKey)->sole();
    $tax = ['id' => 14, 'name' => 'IVA Açores 4', 'region' => 'PT-AC', 'code' => 'RED', 'value' => 4];
    $input = ['receivableId' => $receivable->id, 'customerId' => 42, 'itemId' => 8, 'quantity' => 3,
        'domesticVat' => ['region' => 'PT-AC', 'category' => 'reduced']];
    $issue = app(InvoiceXpressIssue::class);
    fakeRegionalInvoiceXpress($tax, fn () => throw new LogicException('Unexpected write.'), itemTax: [...$tax, 'region' => 'PT-MA']);

    expect(fn () => $issue->preview(...$input))
        ->toThrow(fn (ValidationException $error) => expect($error->errors())->toHaveKey('tax'));
    Http::assertNothingSent();

    $setting->update(['value' => [...$setting->value, 'regional_tax_names' => ['PT-AC' => ['reduced' => $tax['name']]]]]);

    expect(fn () => $issue->preview(...$input))
        ->toThrow(fn (ValidationException $error) => expect($error->errors())->toHaveKey('item'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('requires a region after clearing a reliable suggestion even with legacy settings', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->client->update(['vat_region' => 'PT-MA']);
    Http::preventStrayRequests();

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $receivable->id)->set('customerId', '42')->set('itemId', '8')->set('quantity', '3')
        ->assertSet('vatRegion', 'PT-MA')
        ->set('vatRegion', '')
        ->call('preview')->assertHasErrors('vatRegion');

    Http::assertNothingSent();
});

it('reopens a saved draft with its VAT choice and verifies the region on delivery', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->billable->update(['vat_rate' => '0.0400']);
    $receivable->update(['amount' => 31216]);
    $receivable->client->update(['vat_region' => 'PT-AC']);
    $tax = ['id' => 14, 'name' => 'IVA Açores 4', 'region' => 'PT-AC', 'code' => null, 'value' => 4];
    $setting = Setting::query()->where('key', InvoiceXpressIssue::SettingsKey)->sole();
    $setting->update(['value' => [...$setting->value, 'draft_only' => true, 'regional_tax_names' => ['PT-AC' => ['reduced' => $tax['name']]]]]);
    config(['services.invoicexpress.sandbox_draft_account' => 'pilot']);
    $remote = null;
    $itemTax = $tax;
    fakeRegionalInvoiceXpress($tax, function (Request $request) use ($tax, &$remote) {
        if ($request->method() === 'POST') {
            $remote = invoiceXpressIssueResponse(overrides: ['reference' => $request['invoice']['reference'], 'taxes' => 12.01, 'total' => 312.16]);
            $remote['invoice']['items'][0]['tax'] = $tax;
            $remote['invoice']['items'][0]['tax_amount'] = 12.01;
            $remote['invoice']['items'][0]['total'] = 312.16;

            return Http::response($remote, 201);
        }

        $wrongRegion = $remote;
        $wrongRegion['invoice']['items'][0]['tax']['region'] = 'PT-MA';

        return Http::response($wrongRegion);
    }, itemTax: function () use (&$itemTax): array {
        return $itemTax;
    }, series: 'INVOICEXPRESSDEMO');

    $page = Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $receivable->id)->set('customerId', '42')->set('itemId', '8')->set('quantity', '3')
        ->assertSet('vatRegion', 'PT-AC')->assertSet('vatCategory', 'reduced')
        ->call('preview')->assertHasNoErrors()->assertSet('previewData.domesticVat.region', 'PT-AC')
        ->set('vatRegion', 'PT-MA')->assertSet('previewData', null)
        ->set('vatRegion', 'PT-AC')->call('preview')->assertHasNoErrors();
    $page->call('saveDraft')->assertHasNoErrors();
    $draft = FiscalDocumentDraft::query()->sole();

    expect($draft->payload['inputs']['domesticVat'])->toBe(['region' => 'PT-AC', 'category' => 'reduced']);
    Livewire::withQueryParams(['draft' => $draft->id])->test(InvoiceXpressIssuePage::class)
        ->assertSet('vatRegion', 'PT-AC')->assertSet('vatCategory', 'reduced');

    expect(fn () => app(InvoiceXpressDraftDelivery::class)->send($draft->id))->toThrow(InvoiceXpressException::class)
        ->and($draft->fresh()->state)->toBe(FiscalDocumentDraft::Sending);

    $itemTax['region'] = 'PT-MA';
    expect(fn () => app(InvoiceXpressDraftDelivery::class)->send($draft->id))->toThrow(ValidationException::class);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

function fakeRegionalInvoiceXpress(array $tax, Closure $invoice, array|Closure|null $itemTax = null, string $series = 'PILOTO'): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($tax, $invoice, $itemTax, $series) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/clients/42.json' => Http::response(['client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338']]),
            '/items/8.json' => Http::response(['item' => ['id' => 8, 'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service',
                'unit_price' => '100.0500', 'tax' => $itemTax instanceof Closure ? $itemTax() : ($itemTax ?? $tax)]]),
            '/taxes.json' => Http::response(['taxes' => [$tax]]),
            '/sequences/19.json' => Http::response(['sequence' => ['id' => 19, 'serie' => $series, 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234']]),
            '/invoices.json', '/invoices/65.json', '/invoices/65/change-state.json' => $invoice($request),
        };
    });
}
