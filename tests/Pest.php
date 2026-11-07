<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/Support/finance-workbook.php';
require_once __DIR__.'/Support/bank-statement-workbook.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function actingAsAdmin(): User
{
    $role = Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin'), 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);
    Filament\Facades\Filament::setCurrentPanel('admin');

    return $user;
}
