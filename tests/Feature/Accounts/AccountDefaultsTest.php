<?php

use App\Actions\RecordOneOffIncome;
use App\Actions\SeedAccountDefaults;
use App\Enums\CategoryType;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\HelpCentre;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\FinanceFields;
use App\Mail\PaymentReminder;
use App\Models\Account;
use App\Models\Category;
use App\Models\Client;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;

it('creates the base categories once', function () {
    $account = Account::factory()->create();

    CurrentAccount::run($account, function () use ($account) {
        app(SeedAccountDefaults::class)->handle($account);
        app(SeedAccountDefaults::class)->handle($account);

        expect(Category::count())->toBe(11)
            ->and(Category::where('type', CategoryType::Income)->pluck('name')->sort()->values()->all())->toBe(['Avenças', 'Outras receitas', 'Serviços'])
            ->and(Category::where('type', CategoryType::Expense)->where('name', RecordOneOffIncome::COMMISSION_CATEGORY)->count())->toBe(1);
    });
});

it('starts new accounts without tax exemptions', function () {
    $settings = FinanceSettings::DEFAULTS;

    expect($settings['irs_exempt'])->toBeFalse()
        ->and($settings['vat_exempt'])->toBeFalse()
        ->and($settings['ss_contribution_exempt'])->toBeFalse()
        ->and($settings['payments_exempt_by_default'])->toBeFalse()
        ->and($settings)->not->toHaveKey('ss_first_year_exempt');
});

it('suggests the business lines already used in the account', function () {
    expect(FinanceFields::businessLines())->toBe([]);

    Project::factory()->create(['business_line' => 'Design']);
    Project::factory()->create(['business_line' => 'Design']);
    Project::factory()->create(['business_line' => '']);
    Retainer::factory()->create(['business_line' => 'Consultoria']);
    Retainer::factory()->create(['business_line' => null]);

    $other = Account::factory()->create();
    CurrentAccount::run($other, fn () => Project::factory()->create(['business_line' => 'Secreta']));

    expect(FinanceFields::businessLines())->toBe(['Consultoria', 'Design']);
});

it('signs client emails with the account name and replies to the owner', function () {
    $this->account->update(['name' => 'Estúdio Ana']);
    $owner = User::factory()->create(['email' => 'ana@example.pt']);
    $this->account->users()->attach($owner, ['role' => 'owner']);
    $client = Client::factory()->create(['email' => 'c@example.pt']);

    $mail = new PaymentReminder($client, subjectLine: 'Lembrete', body: 'Olá');

    expect($mail->hasReplyTo('ana@example.pt'))->toBeTrue();
    $mail->assertSeeInHtml('Estúdio Ana');
});

it('skips the reply-to when the account has no owner', function () {
    $client = Client::factory()->create();

    $mail = new PaymentReminder($client, subjectLine: 'Lembrete', body: 'Olá');

    expect($mail->envelope()->replyTo)->toBe([]);
});

it('does not show Pedro data in settings or help', function () {
    actingAsAdmin();

    $this->get(FinanceSettingsPage::getUrl())->assertOk()->assertDontSee('PEDRO')->assertDontSee('Ordenado');
    $this->get(HelpCentre::getUrl())->assertOk()->assertDontSee('PEDRO')->assertDontSee('Ordenado');
    $this->get(ProjectResource::getUrl('create'))->assertOk()->assertDontSee('PRINTEE')->assertSee('ORC-2026-001');
});

it('refuses to import without a known account', function () {
    $this->artisan('finance:import-excel', ['path' => financeWorkbook(), '--account' => 'nao-existe'])
        ->expectsOutputToContain('nao-existe')
        ->assertFailed();
});
