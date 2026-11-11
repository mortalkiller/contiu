<?php

use App\Enums\FiscalProfilePartyType;
use App\Enums\PortugueseVatRegion;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Support\CurrentAccount;
use App\Support\PortugueseVatSuggestion;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    actingAsAdmin();
});

it('saves a Portuguese client address and uses the verified district ahead of the fallback', function () {
    Livewire::test(ListClients::class)
        ->callAction('create', [
            'name' => 'Cliente Açores',
            'fiscal_country_code' => 'PT',
            'fiscal_party_type' => 'business',
            'vat_region' => 'PT-MA',
            'structuredAddress' => [
                'country_code' => 'PT',
                'street' => 'Rua das Flores 12',
                'postal_code' => '9500-000',
                'locality' => 'Localidade postal',
                'district_code' => '42',
                'municipality_code' => '4201',
                'parish_code' => '420101',
            ],
        ])
        ->assertHasNoActionErrors();

    $client = Client::query()->where('name', 'Cliente Açores')->sole();

    expect($client->address)->toBeNull()
        ->and($client->structuredAddress->street)->toBe('Rua das Flores 12')
        ->and($client->structuredAddress->postal_code)->toBe('9500-000')
        ->and($client->structuredAddress->locality)->toBe('Localidade postal')
        ->and($client->structuredAddress->municipality_code)->toBe('4201')
        ->and($client->structuredAddress->parish_code)->toBe('420101')
        ->and(PortugueseVatSuggestion::forClient($client))->toBe(PortugueseVatRegion::Azores)
        ->and($client->vat_region)->toBe(PortugueseVatRegion::Madeira);
})->group('ui');

it('rejects Portuguese administrative codes outside the selected parent', function (array $areas, string $field) {
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['structuredAddress' => ['country_code' => 'PT', ...$areas]])
        ->call('save', false)
        ->assertHasFormErrors(['structuredAddress.'.$field]);

    expect($client->structuredAddress()->exists())->toBeFalse();
})->with([
    'wrong district' => [['district_code' => '42', 'municipality_code' => '1106'], 'municipality_code'],
    'wrong municipality' => [['district_code' => '42', 'municipality_code' => '4201', 'parish_code' => '420201'], 'parish_code'],
    'missing district' => [['municipality_code' => '4201'], 'municipality_code'],
])->group('ui');

it('keeps postal and fiscal countries separate and switches Portuguese codes to foreign free text', function () {
    $client = Client::factory()->create([
        'fiscal_country_code' => 'PT',
        'fiscal_party_type' => FiscalProfilePartyType::Business,
        'vat_region' => PortugueseVatRegion::Madeira,
    ]);
    $client->structuredAddress()->create([
        'country_code' => 'PT',
        'district_code' => '42',
        'municipality_code' => '4201',
        'parish_code' => '420101',
    ]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertSchemaStateSet(['structuredAddress.country_code' => 'PT', 'structuredAddress.parish_code' => '420101'], 'form')
        ->fillForm(['structuredAddress' => [
            'country_code' => 'ES',
            'street' => 'Calle Mayor',
            'postal_code' => '28013',
            'locality' => 'Madrid',
            'district' => 'Madrid',
            'parish' => 'Centro',
        ]])
        ->call('save', false)
        ->assertHasNoFormErrors();

    $client = $client->fresh();

    expect($client->fiscal_country_code)->toBe('PT')
        ->and($client->structuredAddress->country_code)->toBe('ES')
        ->and($client->structuredAddress->district)->toBe('Madrid')
        ->and($client->structuredAddress->parish)->toBe('Centro')
        ->and($client->structuredAddress->district_code)->toBeNull()
        ->and($client->structuredAddress->municipality_code)->toBeNull()
        ->and($client->structuredAddress->parish_code)->toBeNull()
        ->and(PortugueseVatSuggestion::forClient($client))->toBe(PortugueseVatRegion::Madeira);
})->group('ui');

it('persists the nested address from a quick create and leaves fiscal country blank', function () {
    Livewire::test(CreateProject::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('client_id', 'form'), [
            'name' => 'Cliente rápido',
            'structuredAddress' => ['country_code' => 'FR', 'street' => 'Rue de Rivoli', 'locality' => 'Paris'],
        ])
        ->assertHasNoActionErrors();

    $client = Client::query()->where('name', 'Cliente rápido')->sole();

    expect($client->address)->toBeNull()
        ->and($client->fiscal_country_code)->toBeNull()
        ->and($client->structuredAddress->street)->toBe('Rue de Rivoli');
})->group('ui');

it('leaves a legacy address intact and scopes structured addresses through their client', function () {
    $client = Client::factory()->create([
        'address' => 'Funchal, 9000-000',
        'fiscal_country_code' => 'ZZ',
        'fiscal_party_type' => FiscalProfilePartyType::Business,
    ]);
    $other = Account::factory()->create();
    $otherAddress = CurrentAccount::run($other, fn () => Client::factory()->create()
        ->structuredAddress()->create(['country_code' => 'PT', 'district_code' => '31']));

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertFormFieldVisible('address')
        ->assertSchemaStateSet(['fiscal_country_code' => 'ZZ'], 'form')
        ->fillForm(['phone' => '123456789'])
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($client->fresh()->address)->toBe('Funchal, 9000-000')
        ->and($client->fresh()->fiscal_country_code)->toBe('ZZ')
        ->and($client->structuredAddress()->exists())->toBeFalse()
        ->and(PortugueseVatSuggestion::forClient($client))->toBeNull()
        ->and(ClientAddress::query()->find($otherAddress->getKey()))->toBeNull();
})->group('integration');

it('clears child selections when the district changes and removes the structured address when its country is cleared', function () {
    $client = Client::factory()->create(['address' => 'Morada livre']);
    $client->structuredAddress()->create([
        'country_code' => 'PT',
        'district_code' => '42',
        'municipality_code' => '4201',
        'parish_code' => '420101',
    ]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->set('data.structuredAddress.district_code', '31')
        ->assertSchemaStateSet(['structuredAddress.municipality_code' => null, 'structuredAddress.parish_code' => null], 'form')
        ->set('data.structuredAddress.country_code', null)
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($client->structuredAddress()->exists())->toBeFalse()
        ->and($client->fresh()->address)->toBe('Morada livre');
})->group('ui');

it('hides the legacy field on a new client and rejects an unknown fiscal country', function () {
    Livewire::test(ListClients::class)
        ->mountAction('create')
        ->assertFormFieldHidden('address')
        ->fillForm(['name' => 'Cliente novo', 'fiscal_country_code' => 'ZZ'])
        ->callMountedAction()
        ->assertHasFormErrors(['fiscal_country_code']);
})->group('ui');
