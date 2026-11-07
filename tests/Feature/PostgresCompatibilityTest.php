<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// These pass on SQLite too; they exist to be run against PostgreSQL (Supabase in production).

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-07')));

it('groups expenses by month with totals', function () {
    actingAsAdmin();
    Expense::factory()->create(['spent_on' => '2026-10-03', 'net_amount' => 1000]);
    Expense::factory()->create(['spent_on' => '2026-11-02', 'net_amount' => 2000]);

    Livewire::test(ListExpenses::class)->loadTable()
        ->set('tableGrouping', 'spent_on:desc')
        ->assertSee('Novembro 2026')
        ->assertSee('Outubro 2026');
});

it('lists unread database notifications', function () {
    $user = actingAsAdmin();
    Notification::make()->title('Mês por fechar')->sendToDatabase($user);

    expect($user->unreadNotifications()->where('data->format', 'filament')->count())->toBe(1);
});
