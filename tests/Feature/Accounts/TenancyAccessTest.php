<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\HelpCentre;
use App\Filament\Pages\ImportWorkbook;
use App\Filament\Pages\Tenancy\EditAccountProfile;
use App\Filament\Resources\Audits\AuditResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\FinanceStatsOverview;
use App\Models\Account;
use App\Models\Audit;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    Filament::setCurrentPanel('admin');

    $this->mine = $this->account;
    $this->other = Account::factory()->create();
});

it('shows a user only their own accounts', function () {
    $owner = ownerOf($this->mine);
    $both = ownerOf($this->mine);
    $this->other->users()->attach($both, ['role' => 'owner']);
    $panel = Filament::getPanel('admin');

    expect($owner->getTenants($panel)->modelKeys())->toBe([$this->mine->id])
        ->and($owner->canAccessTenant($this->mine))->toBeTrue()
        ->and($owner->canAccessTenant($this->other))->toBeFalse()
        ->and($both->getTenants($panel)->modelKeys())->toEqualCanonicalizing([$this->mine->id, $this->other->id])
        ->and($owner->isSuperAdmin())->toBeFalse();
});

it('returns 404 for a record of another account', function () {
    $theirs = CurrentAccount::run($this->other, fn () => Expense::factory()->create());
    $theirProject = CurrentAccount::run($this->other, fn () => Project::factory()->create());
    $this->actingAs(ownerOf($this->mine));
    asAWebRequest();

    $this->get(ExpenseResource::getUrl('edit', ['record' => $theirs], tenant: $this->mine))->assertNotFound();
    $this->get(ProjectResource::getUrl('view', ['record' => $theirProject], tenant: $this->mine))->assertNotFound();
    $this->get('/'.$this->other->slug)->assertNotFound();
    $this->get(ExpenseResource::getUrl(tenant: $this->other))->assertNotFound();

    $this->get(ExpenseResource::getUrl(tenant: $this->mine))->assertOk();
});

it('sends a logged-in user from the root to their account', function () {
    $this->actingAs(ownerOf($this->mine));
    asAWebRequest();

    $this->get('/')->assertRedirect(Dashboard::getUrl(tenant: $this->mine));
    $this->get(Dashboard::getUrl(tenant: $this->mine))->assertOk();
});

it('lets a super admin open any account and see only that account', function () {
    CurrentAccount::run($this->mine, function () {
        app(FinanceSettings::class)->set(['account_names' => 'TITULAR DA CONTA MINHA']);
        BankTransaction::create(['hash' => 'a1', 'booked_on' => '2026-10-01', 'description' => 'TRF MINHA', 'amount' => 1000, 'balance' => 1000, 'import_batch' => 'a']);
        Payment::factory()->create(['paid_on' => '2026-10-01', 'amount' => 11111]);
    });
    CurrentAccount::run($this->other, function () {
        app(FinanceSettings::class)->set(['account_names' => 'TITULAR DA OUTRA CONTA']);
        BankTransaction::create(['hash' => 'b1', 'booked_on' => '2026-10-01', 'description' => 'TRF OUTRA 1', 'amount' => 1000, 'balance' => 1000, 'import_batch' => 'b']);
        BankTransaction::create(['hash' => 'b2', 'booked_on' => '2026-10-02', 'description' => 'TRF OUTRA 2', 'amount' => 2000, 'balance' => 3000, 'import_batch' => 'b']);
        Payment::factory()->create(['paid_on' => '2026-10-01', 'amount' => 22222]);
    });
    $admin = superAdminOf($this->mine);
    $this->actingAs($admin);
    asAWebRequest();

    expect($admin->getTenants(Filament::getPanel('admin'))->modelKeys())->toEqualCanonicalizing([$this->mine->id, $this->other->id])
        ->and($admin->canAccessTenant($this->other))->toBeTrue();

    $this->get(Dashboard::getUrl(tenant: $this->other))->assertOk();
    $this->get(FinanceSettingsPage::getUrl(tenant: $this->other))->assertOk()
        ->assertSee('TITULAR DA OUTRA CONTA')
        ->assertDontSee('TITULAR DA CONTA MINHA');
    $this->get(BankStatementImport::getUrl(tenant: $this->other))->assertOk()
        ->assertSee('2 linhas por tratar');

    // The same, component by component, as the tenant middleware leaves it.
    asAWebRequest();
    Filament::setTenant($this->other);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->assertSee('TRF OUTRA 1')
        ->assertDontSee('TRF MINHA');
    Livewire::test(FinanceStatsOverview::class, ['pageFilters' => ['year' => 2026]])
        ->assertSee(Money::format(22222))
        ->assertDontSee(Money::format(11111));
});

it('keeps the tenant on Livewire updates', function () {
    $own = CurrentAccount::run($this->mine, fn () => Receivable::factory()
        ->for(Project::factory()->for(Client::factory()->create(['name' => 'Cliente Meu'])), 'billable')
        ->create(['amount' => 50000]));
    CurrentAccount::run($this->other, fn () => Receivable::factory()
        ->for(Project::factory()->for(Client::factory()->create(['name' => 'Cliente Alheio'])), 'billable')
        ->create(['amount' => 70000]));
    $this->actingAs(ownerOf($this->mine));
    asAWebRequest();
    Filament::setTenant($this->mine);

    Livewire::test(ManagePayments::class)
        ->mountAction('recordPayment')
        ->assertSchemaComponentExists('receivable_id', 'mountedActionSchema0', function (Select $field) use ($own): bool {
            // Grouped by client: only this account's client and receivable are offered.
            expect($field->getOptions())->toHaveKeys(['Cliente Meu'])->not->toHaveKey('Cliente Alheio')
                ->and(array_keys($field->getOptions()['Cliente Meu']))->toBe([$own->id]);

            return true;
        });
});

it('re-identifies the tenant from the URL on a real Livewire update request', function () {
    CurrentAccount::run($this->mine, fn () => Payment::factory()->create(['paid_on' => '2026-10-01', 'invoice_number' => 'FT MINHA/1']));
    CurrentAccount::run($this->other, fn () => Payment::factory()->create(['paid_on' => '2026-10-01', 'invoice_number' => 'FT ALHEIA/1']));
    $this->actingAs(ownerOf($this->mine));
    asAWebRequest();

    $page = $this->get(PaymentResource::getUrl(tenant: $this->mine))->assertOk();

    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
        ->first(fn (string $json) => json_decode($json, true)['memo']['name'] === ManagePayments::class);

    expect($snapshot)->not->toBeNull();

    // Leave the process in the WRONG account: only the persistent tenant middleware can fix it.
    asAWebRequest();
    Filament::setTenant($this->other, isQuiet: true);

    $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'loadTable', 'params' => []]],
        ]],
    ])->assertOk();

    $html = $response->json('components.0.effects.html');

    expect($html)->toContain('FT MINHA/1')->not->toContain('FT ALHEIA/1')
        ->and(Filament::getTenant()?->is($this->mine))->toBeTrue();
});

it('hides system pages from normal users', function (string $page) {
    $url = fn (): string => match ($page) {
        'users' => UserResource::getUrl(tenant: $this->mine),
        'roles' => RoleResource::getUrl(tenant: $this->mine),
        'backups' => Backups::getUrl(tenant: $this->mine),
        'import' => ImportWorkbook::getUrl(tenant: $this->mine),
        'audits' => AuditResource::getUrl(tenant: $this->mine),
    };

    $this->actingAs(ownerOf($this->mine));
    asAWebRequest();
    $this->get($url())->assertForbidden();

    $this->actingAs(superAdminOf($this->mine));
    asAWebRequest();
    $this->get($url())->assertOk();
})->with(['users', 'roles', 'backups', 'import', 'audits']);

it('keeps system pages out of a normal user\'s menu', function () {
    $this->actingAs(ownerOf($this->mine));
    Filament::setTenant($this->mine);

    $labels = collect(Filament::getNavigation())
        ->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => (string) $item->getLabel()))
        ->all();

    expect($labels)->toContain('Recebimentos')
        ->not->toContain('Utilizadores')
        ->not->toContain('Roles')
        ->not->toContain('Audits')
        ->not->toContain('Cópias de Segurança')
        ->not->toContain('Importar Excel');
});

it('does not scope the global system resources to the account', function () {
    expect(UserResource::isScopedToTenant())->toBeFalse()
        ->and(RoleResource::isScopedToTenant())->toBeFalse()
        ->and(AuditResource::isScopedToTenant())->toBeFalse()
        ->and(PaymentResource::isScopedToTenant())->toBeTrue();
});

it('keeps the account audit trail stamped (filament-auditing tenancy stays off)', function () {
    expect(config('filament-auditing.tenancy.enabled'))->toBeFalse()
        ->and(config('audit.implementation'))->toBe(Audit::class);
});

it('never offers super_admin in the Users screen', function () {
    $admin = superAdminOf($this->mine);
    $this->actingAs($admin);
    Filament::setTenant($this->mine);
    $superAdmin = Role::findByName('super_admin');
    $editor = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);

    Livewire::test(ManageUsers::class)
        ->mountAction('create')
        ->assertSchemaComponentExists('roles', 'mountedActionSchema0', function (Select $field) use ($superAdmin, $editor): bool {
            expect($field->getOptions())->toHaveKey($editor->id)->not->toHaveKey($superAdmin->id);

            return true;
        });

    // Not even by posting the id by hand.
    Livewire::test(ManageUsers::class)
        ->callAction('create', data: [
            'name' => 'Intrusa',
            'email' => 'intrusa@example.test',
            'password' => 'segredo-forte-123',
            'password_confirmation' => 'segredo-forte-123',
            'roles' => [$superAdmin->id],
        ])
        ->assertHasActionErrors(['roles']);

    expect(User::firstWhere('email', 'intrusa@example.test'))->toBeNull();
});

it('lets the owner edit the account name and NIF', function () {
    $this->actingAs(ownerOf($this->mine));
    Filament::setTenant($this->mine);

    $this->other->update(['nif' => '501964843']);

    foreach (['12345', '123456780', '501964843'] as $invalid) { // too short, wrong check digit, another account's
        Livewire::test(EditAccountProfile::class)
            ->fillForm(['name' => 'Estúdio Novo', 'nif' => $invalid])
            ->call('save')
            ->assertHasFormErrors(['nif']);
    }

    expect($this->mine->fresh()->nif)->toBeNull();

    Livewire::test(EditAccountProfile::class)
        ->fillForm(['name' => 'Estúdio Novo', 'nif' => '123456789'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->mine->fresh())->name->toBe('Estúdio Novo')->nif->toBe('123456789');
});

it('lets a super admin change the account address and opens it at the new one', function () {
    $this->actingAs(superAdminOf($this->mine));
    Filament::setTenant($this->mine);

    Livewire::test(EditAccountProfile::class)
        ->assertFormFieldVisible('slug')
        ->fillForm(['nif' => '123456789', 'slug' => 'estudio-rita'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/estudio-rita/perfil-da-conta'));

    expect($this->mine->fresh()->slug)->toBe('estudio-rita');
});

it('refuses an account address that is taken, reserved or not a plain lowercase slug', function (string $slug) {
    $this->other->update(['slug' => 'ja-existe']);
    $before = $this->mine->slug;

    $this->actingAs(superAdminOf($this->mine));
    Filament::setTenant($this->mine);

    Livewire::test(EditAccountProfile::class)
        ->fillForm(['nif' => '123456789', 'slug' => $slug])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($this->mine->fresh()->slug)->toBe($before);
})->with([
    'another account\'s' => 'ja-existe',
    'reserved' => 'login',
    'a public page' => 'termos',
    'uppercase' => 'Estudio',
    'spaces' => 'estudio rita',
    'empty' => '',
]);

it('keeps the account address out of an owner\'s reach', function () {
    $this->actingAs(ownerOf($this->mine));
    Filament::setTenant($this->mine);
    $before = $this->mine->slug;

    Livewire::test(EditAccountProfile::class)
        ->assertFormFieldHidden('slug')
        ->fillForm(['name' => 'Estúdio Novo', 'nif' => '123456789', 'slug' => 'roubado'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNoRedirect();

    expect($this->mine->fresh())->slug->toBe($before)->name->toBe('Estúdio Novo');
});

it('opens the account profile only to its owners and super admins', function () {
    $owner = ownerOf($this->mine);
    $stranger = ownerOf($this->other);

    $this->actingAs($owner);
    expect(EditAccountProfile::canView($this->mine))->toBeTrue()
        ->and(EditAccountProfile::canView($this->other))->toBeFalse();

    $this->actingAs($stranger);
    expect(EditAccountProfile::canView($this->mine))->toBeFalse();

    $this->actingAs(superAdminOf($this->other));
    expect(EditAccountProfile::canView($this->mine))->toBeTrue();
});

it('opens the user profile, which lives outside any account, with the menu of their own account', function () {
    $this->actingAs(superAdminOf($this->mine));
    asAWebRequest();

    $this->get('/profile')->assertOk()
        ->assertSee(PaymentResource::getUrl(tenant: $this->mine), escape: false);

    $this->actingAs(ownerOf($this->mine));
    asAWebRequest();

    $this->get('/profile')->assertOk();
});

it('links the help centre, the "Novo" menu and the account profile into the account being viewed', function () {
    $this->actingAs(superAdminOf($this->mine));
    asAWebRequest();

    $page = $this->get(HelpCentre::getUrl(tenant: $this->other))->assertOk();

    foreach ([
        ProjectResource::getUrl('create', tenant: $this->other), // help centre and "Novo"
        ExpenseResource::getUrl('create', tenant: $this->other),
        HelpCentre::getUrl(tenant: $this->other), // user menu
        EditAccountProfile::getUrl(tenant: $this->other), // user menu
    ] as $url) {
        $page->assertSee($url, escape: false);
    }

    $page->assertDontSee(ProjectResource::getUrl('create', tenant: $this->mine), escape: false);
});

it('keeps the system resources out of a normal user\'s global search', function () {
    $this->actingAs(ownerOf($this->mine));
    Filament::setTenant($this->mine);

    expect(UserResource::canGloballySearch())->toBeFalse()
        ->and(RoleResource::canGloballySearch())->toBeFalse()
        ->and(AuditResource::canGloballySearch())->toBeFalse();

    $this->actingAs(superAdminOf($this->mine));

    expect(UserResource::canGloballySearch())->toBeTrue();
});

it('shows the account switcher only to who can open more than one account', function () {
    $panel = Filament::getPanel('admin');
    Filament::setTenant($this->mine, isQuiet: true);

    $this->actingAs(ownerOf($this->mine));
    expect($panel->hasTenantMenu())->toBeFalse();

    $this->actingAs(superAdminOf($this->mine));
    expect($panel->hasTenantMenu())->toBeTrue();

    Filament::setTenant(null, isQuiet: true); // e.g. /profile: Filament's menu needs an account
    expect($panel->hasTenantMenu())->toBeFalse();
});
