<?php

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/Support/finance-workbook.php';
require_once __DIR__.'/Support/bank-statement-workbook.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Business models are scoped to the current account and refuse to run without one.
        $this->account = Account::factory()->create();
        CurrentAccount::set($this->account);
    })
    ->in('Feature');

function actingAsAdmin(): User
{
    $role = Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->account->users()->attach($user, ['role' => 'owner']);

    test()->actingAs($user);
    Filament::setCurrentPanel('admin');

    // Only once the panel is multi-tenant: before that a tenant would leak "?tenant=" into URLs.
    if (Filament::getCurrentPanel()->hasTenancy()) {
        Filament::setTenant(test()->account);
    }

    return $user;
}
