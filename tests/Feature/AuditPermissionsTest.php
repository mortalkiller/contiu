<?php

use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\User;
use App\Policies\AuditPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tapp\FilamentAuditing\Models\Audit;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

function userWithPermissions(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    test()->actingAs($user);
    Filament\Facades\Filament::setCurrentPanel('admin');

    return $user;
}

it('registers the audit policy', function () {
    expect(Gate::getPolicyFor(Audit::class))->toBeInstanceOf(AuditPolicy::class);
});

it('shows the history to a user with the audit permission', function (string $model, string $permission) {
    $record = $model::factory()->create();
    userWithPermissions([$permission]);

    expect(AuditsRelationManager::canViewForRecord($record, ViewProject::class))->toBeTrue();
})->with([
    'trabalho' => [Project::class, 'Audit:Project'],
    'avença' => [Retainer::class, 'Audit:Retainer'],
    'despesa' => [Expense::class, 'Audit:Expense'],
    'recebimento' => [Payment::class, 'Audit:Payment'],
    'cliente' => [Client::class, 'Audit:Client'],
]);

it('hides the history from a user without the audit permission', function () {
    $project = Project::factory()->create();
    userWithPermissions(['View:Project']);

    expect(AuditsRelationManager::canViewForRecord($project, ViewProject::class))->toBeFalse();
});

it('shows the history of a payment from the payments list', function () {
    config(['audit.console' => true]); // auditing is off for console runs, tests included
    actingAsAdmin();
    $payment = Payment::factory()->create(['amount' => 12000]);
    $payment->update(['amount' => 15000]);

    Livewire::test(ManagePayments::class)->loadTable()
        ->assertTableActionVisible('history', $payment)
        ->mountTableAction('history', $payment)
        ->assertMountedActionModalSee(['Alterado', 'Criado'])
        ->assertMountedActionModalSeeHtml(e('Montante: '.Money::format(12000).' → '.Money::format(15000)));
});
