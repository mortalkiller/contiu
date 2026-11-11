<?php

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->group('ui');

it('saves client fiscal details from the create form without changing legacy classification', function () {
    actingAsAdmin();

    Livewire::test(ListClients::class)
        ->callAction('create', [
            'name' => 'Cliente europeu',
            'vat_number' => 'ESB12345678',
            'fiscal_country_code' => 'es',
            'fiscal_party_type' => 'business',
            'seat' => 'pt',
            'is_business' => false,
        ])
        ->assertHasNoFormErrors();

    $client = Client::query()->where('name', 'Cliente europeu')->sole();

    expect($client->fiscal_country_code)->toBe('ES')
        ->and($client->fiscal_party_type->value)->toBe('business')
        ->and($client->vat_number)->toBe('ESB12345678')
        ->and($client->vat_validation_status->value)->toBe('pending')
        ->and($client->vat_validated_at)->toBeNull()
        ->and($client->seat->value)->toBe('pt')
        ->and($client->is_business)->toBeFalse();
});

it('records the distinct manual VIES results with the recording time', function (string $status, string $label) {
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    actingAsAdmin();
    $client = Client::factory()->create([
        'fiscal_country_code' => 'ES',
        'fiscal_party_type' => 'business',
        'vat_number' => 'ESB12345678',
    ]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->callAction('recordViesVerification', ['result' => $status])
        ->assertHasNoFormErrors()
        ->assertSee($label)
        ->assertSee('10/11/2026 12:00');

    $client->refresh();

    expect($client->vat_validation_status->value)->toBe($status)
        ->and($client->vat_validated_at->toDateTimeString())->toBe('2026-11-10 12:00:00');
})->with([
    'valid' => ['valid', 'Válido'],
    'invalid' => ['invalid', 'Inválido'],
    'unavailable' => ['unavailable', 'Serviço indisponível'],
]);

it('keeps a result after unrelated edits and resets it when the fiscal identity changes', function (string $field, string $newValue) {
    actingAsAdmin();
    $client = Client::factory()->create([
        'fiscal_country_code' => 'ES',
        'fiscal_party_type' => 'business',
        'vat_number' => 'ESB12345678',
        'vat_validation_status' => 'valid',
        'vat_validated_at' => now(),
    ]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['email' => 'outro@example.test'])
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($client->fresh()->vat_validation_status->value)->toBe('valid');

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm([$field => $newValue])
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($client->fresh()->vat_validation_status->value)->toBe('pending')
        ->and($client->fresh()->vat_validated_at)->toBeNull();
})->with([
    'country' => ['fiscal_country_code', 'FR'],
    'party type' => ['fiscal_party_type', 'individual'],
    'VAT number' => ['vat_number', 'ESB98765432'],
]);

it('does not attach a VIES result after another edit changes the identity while the action is open', function () {
    actingAsAdmin();
    $client = Client::factory()->create([
        'fiscal_country_code' => 'ES',
        'fiscal_party_type' => 'business',
        'vat_number' => 'ESB12345678',
    ]);

    $screen = Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->mountAction('recordViesVerification')
        ->assertMountedActionModalSee('Consultar VIES oficial')
        ->assertMountedActionModalSee('https://ec.europa.eu/taxation_customs/vies/#/vat-validation');

    $client->update(['vat_number' => 'ESB98765432']);

    $screen->fillForm(['result' => 'valid'])
        ->callMountedAction()
        ->assertHasFormErrors(['result']);

    expect($client->fresh()->vat_validation_status->value)->toBe('pending');
});

it('keeps legacy clients pending and blocks verification without a complete saved identity', function () {
    actingAsAdmin();
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertActionHidden('recordViesVerification')
        ->assertSee('Por verificar');

    expect($client->fresh()->vat_validation_status->value)->toBe('pending')
        ->and($client->fresh()->fiscal_country_code)->toBeNull()
        ->and($client->fresh()->fiscal_party_type)->toBeNull();
});

it('cannot open a client in another account to record a VIES result', function () {
    actingAsAdmin();
    $other = Account::factory()->create();
    $client = CurrentAccount::run($other, fn () => Client::factory()->create());

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertNotFound();
});

it('loads the client list balances in one receivables query', function () {
    actingAsAdmin();
    $clients = Client::factory()->count(3)->create();

    foreach ($clients as $index => $client) {
        $project = Project::factory()->for($client)->create();
        Receivable::factory()->for($project, 'billable')->create(['amount' => 10000 + $index]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        Livewire::test(ListClients::class)->assertCanSeeTableRecords($clients);

        $receivableQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select "receivables".*,'));

        expect($receivableQueries)->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
    }
})->group('performance');
