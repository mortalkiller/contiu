<?php

use App\Actions\GenerateRetainerPeriods;
use App\Filament\Resources\Retainers\Pages\ViewRetainer;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Retainers\Widgets\RetainerSummary;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();

    $this->retainer = Retainer::factory()->create([
        'title' => 'Manutenção do site', 'business_line' => 'Web', 'net_amount' => 18000, 'vat_rate' => 0,
        'due_day' => 30, 'starts_on' => '2026-07-01',
    ]);

    foreach (['2026-07-30' => true, '2026-08-30' => true, '2026-09-30' => true, '2026-10-30' => false, '2026-11-30' => false] as $dueOn => $paid) {
        $receivable = Receivable::factory()->for($this->retainer, 'billable')->create(['description' => Carbon::parse($dueOn)->translatedFormat('F Y'), 'amount' => 18000, 'due_on' => $dueOn]);

        if ($paid) {
            Payment::factory()->for($receivable)->create(['amount' => 18000, 'paid_on' => $dueOn]);
        }
    }

    $this->retainer = RetainerResource::getEloquentQuery()->findOrFail($this->retainer->id);
});

it('sums up a retainer: what it bills, what came in, what is open and when the next amount is due', function () {
    Livewire::test(RetainerSummary::class, ['record' => $this->retainer])
        ->assertSeeInOrder(['Por mês', Money::format(18000), Money::format(216000).' por ano · sem IVA'], false)
        ->assertSeeInOrder(['Recebido', Money::format(54000)], false)
        ->assertSeeInOrder(['Em aberto', Money::format(36000), Money::format(18000).' vencidos'], false)
        ->assertSeeInOrder(['Próximo vencimento', '30/11', 'daqui a 20 dias'], false);
})->group('ui');

it('lists the next periods the retainer will bill, after the ones already created', function () {
    $upcoming = app(GenerateRetainerPeriods::class)->upcoming($this->retainer);

    expect(array_map(fn (array $period): string => $period['due_on']->toDateString(), $upcoming))
        ->toBe(['2026-12-30', '2027-01-30', '2027-02-28'])
        ->and($upcoming[0]['amount'])->toBe(18000)
        ->and(app(GenerateRetainerPeriods::class)->upcoming(Retainer::factory()->create(['is_active' => false])))->toBe([]);
})->group('integration');

it('shows the title, one line about the retainer and the upcoming periods beside the details', function () {
    Livewire::test(ViewRetainer::class, ['record' => $this->retainer->getRouteKey()])
        ->assertSee('Manutenção do site')
        ->assertSeeInOrder(['Ativa', 'Mensal', $this->retainer->client->name, 'Web', 'Desde 01/07/2026'])
        ->assertDontSee('Visualizar')
        ->assertSee('Próximos períodos')
        ->assertSee('Vence a 30/12/2026')
        ->assertSee('Detalhes')
        ->assertSeeHtml('fap-key-value')
        ->assertSeeLivewire(RetainerSummary::class)
        ->assertActionExists('recordPayment');
})->group('ui');
