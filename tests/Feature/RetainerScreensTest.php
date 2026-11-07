<?php

use App\Enums\Frequency;
use App\Filament\Resources\Retainers\Pages\CreateRetainer;
use App\Models\Client;
use App\Models\Retainer;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    actingAsAdmin();
});

it('creates the periods due so far when a retainer is created', function () {
    $client = Client::factory()->create();

    Livewire::test(CreateRetainer::class)
        ->fillForm([
            'code' => 'MANUT',
            'client_id' => $client->id,
            'title' => 'Manutenção',
            'net_amount' => 50,
            'vat_rate' => '0.0000',
            'frequency' => Frequency::Monthly,
            'due_day' => 1,
            'starts_on' => '2026-02-01',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Retainer::firstWhere('code', 'MANUT')->receivables()->pluck('description')->all())
        ->toBe(['Fev 2026', 'Mar 2026', 'Abr 2026']);
});
