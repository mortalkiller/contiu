<?php

use App\Models\RecurringExpense;
use App\Models\Retainer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

pest()->group('integration');

it('generates retainer periods and recurring expenses in one run', function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    Retainer::factory()->create(['starts_on' => '2026-03-01', 'due_day' => 1]);
    RecurringExpense::factory()->create(['starts_on' => '2026-03-01', 'day' => 1]);

    $this->artisan('finance:generate-recurring')
        ->expectsOutputToContain('Valores a receber criados: 2')
        ->expectsOutputToContain('Despesas criadas: 1')
        ->assertSuccessful();
});

it('is scheduled to run every day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'finance:generate-recurring'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 6 * * *');
});
