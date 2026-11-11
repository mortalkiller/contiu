<?php

use App\Models\Account;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/Support/finance-workbook.php';
require_once __DIR__.'/Support/bank-statement-workbook.php';
require_once __DIR__.'/Support/bank-reconciliation.php';
require_once __DIR__.'/Support/import-screen.php';
require_once __DIR__.'/Support/invoice-advance.php';
require_once __DIR__.'/Support/invoicing.php';

pest()->group('unit')->in('Unit');

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

/**
 * A normal account owner who holds every Shield permission the app defines, so that only the
 * super-admin check (not a missing permission) can keep the system pages shut.
 */
function ownerOf(Account $account): User
{
    $subjects = ['User', 'Role', 'Audit', 'Expense', 'Project', 'Retainer', 'Payment', 'Receivable', 'Client', 'Supplier', 'Category', 'RecurringExpense'];
    $permissions = collect($subjects)
        ->crossJoin(['ViewAny', 'View', 'Create', 'Update', 'Delete'])
        ->map(fn (array $pair) => "{$pair[1]}:{$pair[0]}")
        ->push('create-backup', 'download-backup', 'delete-backup')
        ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));

    $role = Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);
    $account->users()->attach($user, ['role' => 'owner']);

    return $user;
}

function superAdminOf(Account $account): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $account->users()->attach($user, ['role' => 'owner']);

    return $user;
}

/**
 * A real web request starts with no explicit account: the tenant from the URL is what scopes it.
 * (Feature tests set one in Pest.php; it would otherwise win over the tenant.)
 */
function asAWebRequest(): void
{
    CurrentAccount::set(null);
    Filament::setTenant(null, isQuiet: true);
}

/** Heroicons are 24×24 SVGs; Filament's own loading spinners are the only 24×24 ones allowed. */
function heroiconsOn(string $html): int
{
    preg_match_all('/<svg\b[^>]*viewBox="0 0 24 24"[^>]*>/', $html, $matches);

    return collect($matches[0])->reject(fn (string $tag) => str_contains($tag, 'fi-loading-indicator'))->count();
}
