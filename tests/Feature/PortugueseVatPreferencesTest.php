<?php

use App\Enums\FiscalProfilePartyType;
use App\Enums\PortugueseVatRegion;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Account;
use App\Models\Client;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\PortugueseVatSuggestion;
use Livewire\Livewire;

it('saves and clears the recipient establishment region on a client', function () {
    actingAsAdmin();
    $client = Client::factory()->create([
        'fiscal_country_code' => 'PT',
        'fiscal_party_type' => FiscalProfilePartyType::Business,
    ]);

    $form = Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertFormFieldExists('vat_region')
        ->assertSchemaStateSet(['vat_region' => null], 'form')
        ->fillForm(['vat_region' => 'PT-AC'])
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($client->fresh()->vat_region)->toBe(PortugueseVatRegion::Azores);

    $form->fillForm(['vat_region' => 'PT-XX'])
        ->call('save', false)
        ->assertHasFormErrors(['vat_region']);

    expect($client->fresh()->vat_region)->toBe(PortugueseVatRegion::Azores);

    $form->fillForm(['vat_region' => null])
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($client->fresh()->vat_region)->toBeNull();
})->group('ui');

it('saves, reloads and clears the activity region in fiscal settings', function () {
    actingAsAdmin();
    $settings = app(FinanceSettings::class);

    $form = Livewire::test(FinanceSettingsPage::class)
        ->assertFormFieldExists('vat_region')
        ->assertSchemaStateSet(['vat_region' => null], 'form')
        ->fillForm(['vat_region' => 'PT-MA'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($settings->vatRegion())->toBe(PortugueseVatRegion::Madeira);

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['vat_region' => 'PT-MA'], 'form');

    $form->fillForm(['vat_region' => 'PT-XX'])
        ->call('save')
        ->assertHasFormErrors(['vat_region']);

    expect($settings->vatRegion())->toBe(PortugueseVatRegion::Madeira);

    $form->fillForm(['vat_region' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($settings->vatRegion())->toBeNull();
})->group('ui');

it('suggests the establishment region for businesses and the activity region for individuals', function (
    ?FiscalProfilePartyType $party,
    ?PortugueseVatRegion $clientRegion,
    ?string $activityRegion,
    ?PortugueseVatRegion $expected,
    string $country = 'PT',
) {
    app(FinanceSettings::class)->set(['vat_region' => $activityRegion]);
    $client = Client::factory()->create([
        'fiscal_country_code' => $country,
        'fiscal_party_type' => $party,
        'vat_region' => $clientRegion,
    ]);

    expect(PortugueseVatSuggestion::forClient($client->fresh()))->toBe($expected);
})->with([
    'business' => [FiscalProfilePartyType::Business, PortugueseVatRegion::Azores, 'PT-MA', PortugueseVatRegion::Azores],
    'business without region' => [FiscalProfilePartyType::Business, null, 'PT-MA', null],
    'individual' => [FiscalProfilePartyType::Individual, PortugueseVatRegion::Azores, 'PT-MA', PortugueseVatRegion::Madeira],
    'individual without activity region' => [FiscalProfilePartyType::Individual, PortugueseVatRegion::Azores, null, null],
    'individual with an unrecognised setting' => [FiscalProfilePartyType::Individual, null, 'PT-XX', null],
    'unclassified' => [null, PortugueseVatRegion::Azores, 'PT-MA', null],
    'foreign fiscal country' => [FiscalProfilePartyType::Business, PortugueseVatRegion::Azores, 'PT-MA', null, 'ES'],
])->group('integration');

it('reads suggestions only for clients and settings in the active account', function () {
    $settings = app(FinanceSettings::class);
    $settings->set(['vat_region' => 'PT-AC']);
    $client = Client::factory()->create(['fiscal_party_type' => FiscalProfilePartyType::Individual]);
    $otherAccount = Account::factory()->create();

    expect(PortugueseVatSuggestion::forClient($client))->toBe(PortugueseVatRegion::Azores);

    CurrentAccount::run($otherAccount, function () use ($client, $settings): void {
        $otherClient = Client::factory()->create(['fiscal_party_type' => FiscalProfilePartyType::Individual]);

        expect(PortugueseVatSuggestion::forClient($otherClient))->toBeNull();

        $settings->set(['vat_region' => 'PT-MA']);

        expect(PortugueseVatSuggestion::forClient($otherClient))->toBe(PortugueseVatRegion::Madeira)
            ->and(PortugueseVatSuggestion::forClient($client))->toBeNull();
    });

    expect(PortugueseVatSuggestion::forClient($client))->toBe(PortugueseVatRegion::Azores);
})->group('integration');
