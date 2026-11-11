<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\ImportWorkbook;
use App\Filament\Pages\Tenancy\EditAccountProfile;
use App\Filament\Resources\Audits\AuditResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Account;
use App\Models\Audit;
use App\Models\Client;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups;
use Spatie\Permission\Models\Role;

pest()->group('tenancy');

/** Owners get no Shield permissions: being a member of the account is what opens it. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');

    $this->owner = User::factory()->create();
    $this->account->users()->attach($this->owner, ['role' => 'owner']);
});

it('opens the account\'s own pages to an owner without permissions', function (string $page) {
    expect($this->owner->getAllPermissions())->toBeEmpty();

    $this->actingAs($this->owner);
    asAWebRequest();

    $url = match ($page) {
        'clients' => ClientResource::getUrl(tenant: $this->account),
        'payments' => PaymentResource::getUrl(tenant: $this->account),
        'bank statement' => BankStatementImport::getUrl(tenant: $this->account),
        'finance settings' => FinanceSettingsPage::getUrl(tenant: $this->account),
        'account profile' => EditAccountProfile::getUrl(tenant: $this->account),
    };

    $this->get($url)->assertOk();
})->with(['clients', 'payments', 'bank statement', 'finance settings', 'account profile'])->group('ui');

it('still keeps the system pages shut to an owner', function (string $page) {
    $this->actingAs($this->owner);
    asAWebRequest();

    $url = match ($page) {
        'users' => UserResource::getUrl(tenant: $this->account),
        'roles' => RoleResource::getUrl(tenant: $this->account),
        'audits' => AuditResource::getUrl(tenant: $this->account),
        'backups' => Backups::getUrl(tenant: $this->account),
        'import' => ImportWorkbook::getUrl(tenant: $this->account),
    };

    $this->get($url)->assertForbidden();
})->with(['users', 'roles', 'audits', 'backups', 'import'])->group('ui');

it('grants the account\'s abilities to its members, never the system ones', function () {
    $client = Client::factory()->create();

    expect($this->owner->can('ViewAny:Client'))->toBeTrue()
        ->and($this->owner->can('Delete:Payment'))->toBeTrue()
        ->and($this->owner->can('View:BankStatementImport'))->toBeTrue()
        ->and($this->owner->can('viewAny', Client::class))->toBeTrue()
        ->and($this->owner->can('update', $client))->toBeTrue()
        // System subjects, as Shield permissions and as policy abilities.
        ->and($this->owner->can('ViewAny:User'))->toBeFalse()
        ->and($this->owner->can('ViewAny:Role'))->toBeFalse()
        ->and($this->owner->can('ViewAny:Audit'))->toBeFalse()
        ->and($this->owner->can('View:Backups'))->toBeFalse()
        ->and($this->owner->can('View:ImportWorkbook'))->toBeFalse()
        ->and($this->owner->can('ViewAny:Account'))->toBeFalse()
        ->and($this->owner->can('ViewAny:SubscriptionPayment'))->toBeFalse()
        ->and($this->owner->can('viewAny', User::class))->toBeFalse()
        ->and($this->owner->can('update', $this->owner))->toBeFalse()
        ->and($this->owner->can('viewAny', Role::class))->toBeFalse()
        ->and($this->owner->can('viewAny', Audit::class))->toBeFalse()
        ->and($this->owner->can('update', $this->account))->toBeFalse()
        // No subject to judge: left to the rest of the gate.
        ->and($this->owner->can('create-backup'))->toBeFalse()
        ->and($this->owner->can('viewPulse'))->toBeFalse()
        ->and($this->owner->can('frobnicate', 'not-a-class'))->toBeFalse();
})->group('integration');

it('gives nothing to a user who is not a member of the current account', function () {
    $stranger = User::factory()->create();
    Account::factory()->create()->users()->attach($stranger, ['role' => 'owner']);
    $client = Client::factory()->create();

    expect($stranger->can('ViewAny:Client'))->toBeFalse()
        ->and($stranger->can('viewAny', Client::class))->toBeFalse()
        ->and($stranger->can('update', $client))->toBeFalse();
})->group('integration');

it('gives nothing outside an account', function () {
    CurrentAccount::set(null);

    expect($this->owner->can('ViewAny:Client'))->toBeFalse();
})->group('integration');

it('opens nothing of another account to an owner', function () {
    $other = Account::factory()->create();
    $this->actingAs($this->owner);
    asAWebRequest();

    $this->get(ClientResource::getUrl(tenant: $other))->assertNotFound();
})->group('ui');
