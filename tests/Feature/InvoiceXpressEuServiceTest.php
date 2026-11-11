<?php

use App\Enums\FiscalProfileViesStatus;
use App\Filament\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressEuService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
    $this->account->forceFill(['billing_exempt' => true])->save();
});

function euServiceClient(array $attributes = [], FiscalProfileViesStatus $status = FiscalProfileViesStatus::Valid): Client
{
    $client = Client::factory()->create($attributes + [
        'fiscal_country_code' => 'GR',
        'fiscal_party_type' => 'business',
        'vat_number' => ' el 123456789 ',
    ]);
    $client->forceFill(['vat_validation_status' => $status, 'vat_validated_at' => now()])->save();

    return $client;
}

function euServiceDocument(array $attributes = []): FiscalDocument
{
    return FiscalDocument::factory()->create($attributes + [
        'operation_date' => '2026-11-10',
        'party_tax_id' => 'EL123456789',
        'vat_amount' => 0,
        'total_amount' => 10000,
    ]);
}

it('returns the verified identity and checks the same remote EU customer', function () {
    $profile = FiscalProfile::factory()->create();
    $client = euServiceClient();
    $terms = InvoiceXpressEuService::terms($client, '2026-11-10');
    $remote = ['id' => '42', 'name' => ' Test EU ', 'fiscal_id' => '123456789', 'country' => 'Greece'];

    expect($terms)->toBe([
        'country' => 'GR',
        'vat' => 'EL123456789',
        'operationOn' => '2026-11-10',
        'reason' => 'M40',
        'clientId' => $client->id,
        'fiscalProfileId' => $profile->id,
        'viesCheckedAt' => '2026-11-10 12:00:00',
    ])->and(InvoiceXpressEuService::customer($remote, 42, $terms))
        ->toBe(['id' => 42, 'name' => 'Test EU', 'nif' => 'EL123456789', 'country' => 'GR']);

    foreach (['id' => '43', 'country' => 'Germany', 'fiscal_id' => 'DE123456789', 'name' => ' '] as $key => $value) {
        expect(fn () => InvoiceXpressEuService::customer(array_replace($remote, [$key => $value]), 42, $terms))
            ->toThrow(ValidationException::class);
    }
})->group('integration');

it('rejects fiscal identities outside the verified EU business scope', function (array $attributes, FiscalProfileViesStatus $status) {
    FiscalProfile::factory()->create();

    expect(fn () => InvoiceXpressEuService::terms(euServiceClient($attributes, $status), '2026-11-10'))
        ->toThrow(ValidationException::class);
})->with([
    'individual' => [['fiscal_party_type' => 'individual'], FiscalProfileViesStatus::Valid],
    'Portuguese' => [['fiscal_country_code' => 'PT'], FiscalProfileViesStatus::Valid],
    'outside the EU' => [['fiscal_country_code' => 'GB'], FiscalProfileViesStatus::Valid],
    'invalid' => [[], FiscalProfileViesStatus::Invalid],
    'pending' => [[], FiscalProfileViesStatus::Pending],
    'unavailable' => [[], FiscalProfileViesStatus::Unavailable],
    'foreign prefix' => [['vat_number' => 'DE123456789'], FiscalProfileViesStatus::Valid],
])->group('integration');

it('checks the confirmed normal Portuguese profile effective on the actual operation date', function () {
    $client = euServiceClient();
    FiscalProfile::factory()->create(['effective_from' => '2026-10-01']);
    FiscalProfile::factory()->create(['effective_from' => '2026-11-01', 'vat_exempt' => true]);

    expect(fn () => InvoiceXpressEuService::terms($client, '2026-09-30'))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressEuService::terms($client, '2026-11-10'))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressEuService::terms($client, '2026-02-30'))->toThrow(ValidationException::class)
        ->and(InvoiceXpressEuService::terms($client, '2026-10-31')['operationOn'])->toBe('2026-10-31');
})->group('integration');

it('records the immutable operation once and rejects conflicting evidence', function () {
    FiscalProfile::factory()->create();
    $client = euServiceClient();
    $terms = InvoiceXpressEuService::terms($client, '2026-11-10');
    $document = euServiceDocument();

    InvoiceXpressEuService::record($document, $terms);
    $client->update(['vat_number' => 'EL987654321']);
    InvoiceXpressEuService::record($document, $terms);

    $evidence = $document->fiscalOperationEvidence()->sole();
    expect($evidence->operation_on->toDateString())->toBe('2026-11-10')
        ->and($evidence->vat_number)->toBe('EL123456789')
        ->and($evidence->net_amount)->toBe(10000)
        ->and(fn () => InvoiceXpressEuService::record($document, array_replace($terms, ['reason' => 'M44'])))
        ->toThrow(ValidationException::class)
        ->and(fn () => $evidence->update(['net_amount' => 1]))->toThrow(LogicException::class);

    $incompleteEvidence = $document->fiscalOperationEvidence()->sole();
    $incompleteEvidence->document_on = null;
    expect($incompleteEvidence->matchesDocument($document))->toBeFalse();
})->group('integration');

it('records an already-issued document from its approved terms after the current VIES and fiscal profile change', function () {
    $profile = FiscalProfile::factory()->create(['effective_from' => '2026-10-01']);
    $client = euServiceClient();
    $terms = InvoiceXpressEuService::terms($client, '2026-11-09');
    $document = euServiceDocument(['operation_date' => '2026-11-09']);

    $client->forceFill(['vat_validation_status' => FiscalProfileViesStatus::Unavailable, 'vat_validated_at' => now()])->save();
    FiscalProfile::factory()->create(['effective_from' => '2026-10-01', 'vat_exempt' => true]);

    expect(fn () => InvoiceXpressEuService::terms($client, '2026-11-09'))->toThrow(ValidationException::class)
        ->and(fn () => InvoiceXpressEuService::recordDocumentary($document, $client, '2026-11-09'))
        ->toThrow(ValidationException::class);

    InvoiceXpressEuService::record($document, $terms);
    $evidence = $document->fiscalOperationEvidence()->sole();

    expect($evidence->fiscal_profile_id)->toBe($profile->id)
        ->and($evidence->vies_checked_at->toDateString())->toBe('2026-11-10')
        ->and($evidence->operation_on->toDateString())->toBe('2026-11-09');
})->group('integration');

it('rejects evidence that conflicts with the saved fiscal document', function (array $attributes) {
    FiscalProfile::factory()->create();
    $terms = InvoiceXpressEuService::terms(euServiceClient(), '2026-11-10');
    $document = euServiceDocument($attributes);

    expect(fn () => InvoiceXpressEuService::record($document, $terms))->toThrow(ValidationException::class);
    expect($document->fiscalOperationEvidence()->exists())->toBeFalse();
})->with([
    'received' => [['direction' => 'received']],
    'receipt only' => [['type' => 'receipt']],
    'voided' => [['status' => 'voided']],
    'non-EUR' => [['currency' => 'USD']],
    'VAT charged' => [['vat_amount' => 2300, 'total_amount' => 12300]],
    'other VAT number' => [['party_tax_id' => 'EL987654321']],
    'conflicting service date' => [['operation_date' => '2026-11-09']],
])->group('integration');

it('allows explicit documentary recording with an evidenced date even if the imported document lacks an operation date', function () {
    FiscalProfile::factory()->create(['effective_from' => '2026-09-01']);
    $client = euServiceClient();
    $document = euServiceDocument(['operation_date' => null, 'document_date' => '2026-11-10']);

    expect(fn () => InvoiceXpressEuService::recordDocumentary($document, $client, '2026-11-09'))
        ->toThrow(ValidationException::class);

    InvoiceXpressEuService::recordDocumentary($document, $client, '2026-11-10');

    expect($document->fiscalOperationEvidence()->sole()->source)->toBe('documentary')
        ->and($document->fresh()->operation_date)->toBeNull();
})->group('integration');

it('cannot use a client or a document from another account', function () {
    FiscalProfile::factory()->create();
    $client = euServiceClient();
    $document = euServiceDocument();
    $terms = InvoiceXpressEuService::terms($client, '2026-11-10');
    $other = Account::factory()->create(['billing_exempt' => true]);

    CurrentAccount::run($other, function () use ($client, $document, $terms): void {
        expect(fn () => InvoiceXpressEuService::terms($client, '2026-11-10'))->toThrow(ValidationException::class)
            ->and(fn () => InvoiceXpressEuService::record($document, $terms))->toThrow(ValidationException::class)
            ->and(fn () => InvoiceXpressEuService::record(euServiceDocument(), $terms))->toThrow(ValidationException::class);
    });
})->group('integration');

it('lets an operator classify an imported invoice from its fiscal document with dated evidence', function () {
    actingAsAdmin();
    FiscalProfile::factory()->create(['effective_from' => '2026-09-01']);
    $client = euServiceClient();
    $document = euServiceDocument([
        'origin' => 'InvoiceXpress',
        'operation_date' => null,
        'document_date' => '2026-10-15',
    ]);

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->assertActionVisible('recordEuService')
        ->callAction('recordEuService', data: [
            'clientId' => $client->id,
            'operationOn' => '2026-10-14',
            'confirmed' => false,
        ])
        ->assertHasActionErrors(['confirmed']);

    expect($document->fiscalOperationEvidence()->exists())->toBeFalse();

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->callAction('recordEuService', data: [
            'clientId' => $client->id,
            'operationOn' => '2026-10-14',
            'historicalViesOn' => '2026-10-13',
            'historicalViesReference' => 'VIES-EXEMPLO',
            'confirmed' => true,
        ])
        ->assertHasNoActionErrors();

    expect($document->fiscalOperationEvidence()->sole()->historical_vies_reference)->toBe('VIES-EXEMPLO')
        ->and($document->fresh()->operation_date)->toBeNull();

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->assertActionHidden('recordEuService')
        ->assertSee('Serviços a empresas da UE')
        ->assertSee('EL123456789')
        ->assertSee('VIES-EXEMPLO');

    $document->update(['party_tax_id' => 'EL987654321']);

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->assertActionHidden('recordEuService')
        ->assertSee('Evidência por rever');
})->group('ui');

it('normalizes catalog VAT identities from other EU countries', function () {
    expect(InvoiceXpressEuService::catalogVat(['country' => 'Greece', 'fiscal_id' => '123456789']))->toBe('EL123456789')
        ->and(InvoiceXpressEuService::catalogVat(['country' => 'DE', 'fiscal_id' => 'EL123456789']))->toBeNull()
        ->and(InvoiceXpressEuService::catalogVat(['country' => 'Portugal', 'fiscal_id' => 'PT508025338']))->toBeNull()
        ->and(InvoiceXpressEuService::catalogVat(['country' => 'United Kingdom', 'fiscal_id' => 'XI123456789']))->toBeNull();
})->group('integration');
