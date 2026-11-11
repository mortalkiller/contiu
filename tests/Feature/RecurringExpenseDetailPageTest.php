<?php

use App\Actions\GenerateRecurringExpenses;
use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Filament\Resources\RecurringExpenses\Pages\ViewRecurringExpense;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\RecurringExpenses\RelationManagers\GeneratedExpensesRelationManager;
use App\Filament\Resources\RecurringExpenses\Widgets\RecurringExpenseSummary;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->group('ui');

/*
 * A recurring expense's own page: what each state (active, paused, ended) shows and offers, the
 * four figures, the expenses it created and who may open it. The generator has its own tests.
 */

/** "Internet": every month on day 10 since May 2025, 50,00 € plus 23% VAT, paid by card to Loja Demo. */
function internetTemplate(array $attributes = []): RecurringExpense
{
    return RecurringExpense::factory()
        ->for(Supplier::factory()->create(['name' => 'Loja Demo', 'vat_number' => '509999966']))
        ->for(Category::query()->firstWhere('name', 'Comunicações') ?? Category::factory()->create(['name' => 'Comunicações']))
        ->create([
            'description' => 'Internet',
            'net_amount' => 5000,
            'vat_rate' => 0.23,
            'frequency' => Frequency::Monthly,
            'day' => 10,
            'starts_on' => '2025-05-10',
            'method' => PaymentMethod::Card,
            ...$attributes,
        ]);
}

/** An expense the template created on a date, as the generator leaves it (paid unless told otherwise). */
function generatedExpense(RecurringExpense $template, string $spentOn, array $attributes = []): Expense
{
    return Expense::factory()->create([
        'recurring_expense_id' => $template->id,
        'supplier_id' => $template->supplier_id,
        'description' => $template->description,
        'spent_on' => $spentOn,
        'net_amount' => $template->net_amount,
        'vat_rate' => $template->vat_rate,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-11 09:00'));
    actingAsAdmin();
});

it('opens a recurring expense\'s own page from its row in the list', function () {
    $template = internetTemplate();
    $url = RecurringExpenseResource::getUrl('view', ['record' => $template]);

    Livewire::test(ManageRecurringExpenses::class)
        ->loadTable()
        ->assertSeeHtml($url)
        ->assertTableActionExists('edit')
        ->assertTableActionExists('delete');

    $this->get($url)->assertOk();
});

it('shows an active one with its state, the line under it, its rule and what it offers', function () {
    $template = internetTemplate();
    generatedExpense($template, '2026-11-10');

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(['Internet', 'Ativa', 'Mensal', 'Loja Demo', 'todos os meses no dia 10', 'desde 10/05/2025'])
        ->assertSeeHtml(SupplierResource::getUrl('view', ['record' => $template->supplier]))
        ->assertSeeInOrder(['Regra', 'Periodicidade', 'Mensal', 'Dia', '10 de cada mês', 'Começou em', '10/05/2025', 'Termina', 'Sem fim', 'Valor sem IVA', '50,00', 'IVA', '23', 'Método', 'Cartão'])
        ->assertSee('A despesa de cada mês é criada sozinha no dia 10, por pagar.')
        ->assertSeeInOrder(['Classificação', 'Categoria', 'Comunicações', 'Tipo', 'Estrutura'])
        ->assertDontSee('Imputada a')
        ->assertSeeInOrder(['Fornecedor', 'Loja Demo', 'NIF 509999966 · Portugal'])
        ->assertSeeInOrder(['Histórico', 'Despesa de novembro criada', 'Recorrente criada'])
        ->assertSeeLivewire(RecurringExpenseSummary::class)
        ->assertSeeLivewire(GeneratedExpensesRelationManager::class)
        ->assertActionVisible('pause')
        ->assertActionHidden('resume')
        ->assertActionExists('edit')
        ->assertActionExists('delete')
        ->assertActionVisible('generateNext')
        ->assertActionHasLabel('generateNext', 'Gerar a de dezembro');
});

it('shows a paused one as paused and offers only to resume it', function () {
    $template = internetTemplate(['is_active' => false]);

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertSeeInOrder(['Internet', 'Em pausa', 'Mensal'])
        ->assertSee('Em pausa não cria despesas.')
        ->assertActionVisible('resume')
        ->assertActionHidden('pause')
        ->assertActionHidden('generateNext');

    Livewire::test(RecurringExpenseSummary::class, ['record' => $template])
        ->assertSeeInOrder(['Próxima', '—', 'Em pausa: não cria despesas']);
});

it('shows one past its end date as ended, with nothing left to generate', function () {
    $template = internetTemplate(['ends_on' => '2026-10-31']);
    generatedExpense($template, '2026-10-10');

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertSeeInOrder(['Internet', 'Terminada', 'Mensal', 'desde 10/05/2025', 'até 31/10/2026'])
        ->assertSeeInOrder(['Terminou em', '31/10/2026'])
        ->assertActionHidden('generateNext');

    Livewire::test(RecurringExpenseSummary::class, ['record' => $template])
        ->assertSeeInOrder(['Próxima', '—', 'Terminou a 31/10/2026']);
});

it('says what a direct cost is charged to', function () {
    $project = Project::factory()->create(['code' => 'DEMO-03', 'title' => 'Site institucional']);
    $template = internetTemplate(['kind' => ExpenseKind::Direct, 'project_id' => $project->id]);

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertSeeInOrder(['Tipo', 'Direta', 'Imputada a', 'DEMO-03 · Site institucional']);
});

it('sums up what each period costs, the next date, this year and what is unpaid', function () {
    $template = internetTemplate();
    generatedExpense($template, '2025-12-10');
    generatedExpense($template, '2026-10-10');
    generatedExpense($template, '2026-11-10', ['is_paid' => false, 'paid_on' => null]);
    // Another template's expense counts nowhere here.
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-01', 'net_amount' => 99900]);

    Livewire::test(RecurringExpenseSummary::class, ['record' => $template])
        ->assertSeeInOrder(['Por mês, com IVA', Money::format(6150), Money::format(5000).' sem IVA · IVA a 23'], false)
        ->assertSeeInOrder(['Próxima', '10/12/2026', 'Daqui a 29 dias · gerada sozinha'], false)
        ->assertSeeInOrder(['Gasto em 2026', Money::format(12300), '2 despesas · '.Money::format(18450).' até ao fim do ano'], false)
        ->assertSeeInOrder(['Por pagar', Money::format(6150), 'Novembro · desde 10/11/2026'], false);
});

it('names the period of a quarterly one and has nothing unpaid before it creates anything', function () {
    $template = internetTemplate(['frequency' => Frequency::Quarterly, 'starts_on' => '2026-12-01']);

    Livewire::test(RecurringExpenseSummary::class, ['record' => $template])
        ->assertSeeInOrder(['Por trimestre, com IVA', Money::format(6150)], false)
        ->assertSeeInOrder(['Próxima', '10/12/2026'])
        ->assertSeeInOrder(['Gasto em 2026', Money::format(0), 'Nenhuma despesa · '.Money::format(6150).' até ao fim do ano'], false)
        ->assertSeeInOrder(['Por pagar', '—', 'Nada por pagar']);
});

it('lists only the expenses it created, each under its month and leading to the expense', function () {
    $template = internetTemplate();
    $november = generatedExpense($template, '2026-11-10', ['is_paid' => false, 'paid_on' => null]);
    $october = generatedExpense($template, '2026-10-10', ['invoice_number' => 'FT A/2104']);
    $other = Expense::factory()->create(['description' => 'Router']);
    $url = ExpenseResource::getUrl(ExpenseResource::hasPage('view') ? 'view' : 'edit', ['record' => $november]);

    Livewire::test(GeneratedExpensesRelationManager::class, ['ownerRecord' => $template, 'pageClass' => ViewRecurringExpense::class])
        ->loadTable()
        ->assertSee('Despesas geradas')
        ->assertCanSeeTableRecords([$november, $october], inOrder: true)
        ->assertCanNotSeeTableRecords([$other])
        ->assertSeeInOrder(['Novembro 2026', 'Outubro 2026'])
        ->assertSeeHtml($url)
        ->assertTableColumnStateSet('proof', 'FT A/2104', $october)
        ->assertTableColumnStateSet('proof', 'Em falta', $november)
        ->assertTableColumnSummarySet('total', 'sum', 12300)
        ->assertTableActionVisible('markPaid', $november)
        ->assertTableActionHidden('markPaid', $october);
});

it('pauses an active one and resumes a paused one', function () {
    $template = internetTemplate();

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->callAction('pause')
        ->assertNotified('Despesa recorrente em pausa')
        ->assertRedirect(RecurringExpenseResource::getUrl('view', ['record' => $template]));

    expect($template->refresh()->is_active)->toBeFalse();

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->callAction('resume')
        ->assertNotified('Despesa recorrente retomada');

    expect($template->refresh()->is_active)->toBeTrue();
});

it('creates only the expense the page offered, however many times it is asked', function () {
    $template = internetTemplate();
    generatedExpense($template, '2026-11-10');

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->callAction('generateNext')
        ->assertNotified('Despesa de dezembro criada')
        ->callAction('generateNext')
        ->assertNotified('Não há nenhuma despesa por gerar.');

    expect($template->expenses()->pluck('spent_on')->map->toDateString()->sort()->values()->all())->toBe(['2026-11-10', '2026-12-10']);
});

it('creates the next expense ahead of its date, once, and the generator carries on after it', function () {
    $template = internetTemplate();
    generatedExpense($template, '2026-11-10');

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->callAction('generateNext')
        ->assertNotified('Despesa de dezembro criada')
        ->assertRedirect(RecurringExpenseResource::getUrl('view', ['record' => $template]));

    $created = $template->expenses()->orderByDesc('spent_on')->first();

    expect($template->expenses()->count())->toBe(2)
        ->and($created->spent_on->toDateString())->toBe('2026-12-10')
        ->and($created->is_paid)->toBeFalse()
        ->and($created->total)->toBe(6150);

    $this->travelTo(Carbon::parse('2026-12-11 06:15'));

    expect(app(GenerateRecurringExpenses::class)->forTemplate($template->refresh()))->toBe(0);

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertActionHasLabel('generateNext', 'Gerar a de janeiro 2027');
});

it('escapes what the user typed in the title and in the line under it', function () {
    $template = internetTemplate(['description' => 'Net <b>1</b>']);
    $template->supplier->update(['name' => '<script>alert(1)</script>']);

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertSee('Net <b>1</b>')
        ->assertDontSeeHtml('<b>1</b>')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('is not found for a recurring expense of another account', function () {
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => internetTemplate());

    Livewire::test(ViewRecurringExpense::class, ['record' => $foreign->getRouteKey()])->assertNotFound();
});

it('is forbidden to an accountant, as the list is', function () {
    $template = internetTemplate();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant);

    Livewire::test(ManageRecurringExpenses::class)->assertForbidden();
    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])->assertForbidden();
});

it('offers nothing that writes while the account is read-only', function () {
    $template = internetTemplate();
    $owner = User::factory()->create();
    $this->account->users()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);
    $this->account->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $this->account->unsetRelation('subscriptions');

    Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('pause')
        ->assertActionHidden('edit')
        ->assertActionHidden('delete')
        ->assertActionHidden('generateNext');
});

it('opens with strict models, as they are in the local environment', function () {
    $project = Project::factory()->create();
    $template = internetTemplate(['kind' => ExpenseKind::Direct, 'project_id' => $project->id]);
    generatedExpense($template, '2026-11-10', ['is_paid' => false, 'paid_on' => null]);
    Model::shouldBeStrict();

    try {
        Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])->assertOk();
        Livewire::test(RecurringExpenseSummary::class, ['record' => $template])->assertOk();
        Livewire::test(GeneratedExpensesRelationManager::class, ['ownerRecord' => $template, 'pageClass' => ViewRecurringExpense::class])
            ->loadTable()
            ->assertOk();
    } finally {
        Model::shouldBeStrict(false);
    }
});

it('loads the page, its figures and its table without a query per expense', function () {
    $queries = function (RecurringExpense $template): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ViewRecurringExpense::class, ['record' => $template->getRouteKey()])->assertOk();
        Livewire::test(RecurringExpenseSummary::class, ['record' => $template])->assertOk();
        Livewire::test(GeneratedExpensesRelationManager::class, ['ownerRecord' => $template, 'pageClass' => ViewRecurringExpense::class])
            ->loadTable()
            ->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $make = function (int $months): RecurringExpense {
        $template = internetTemplate();

        foreach (range(1, $months) as $month) {
            generatedExpense($template, Carbon::parse('2026-11-10')->subMonths($month - 1)->toDateString(), ['is_paid' => false, 'paid_on' => null]);
        }

        return $template;
    };
    $small = $make(2);
    $large = $make(9);

    // The first render also loads what is cached for the rest of the test (permissions, settings).
    $queries($small);

    expect($queries($large))->toBe($queries($small));
})->group('performance');
