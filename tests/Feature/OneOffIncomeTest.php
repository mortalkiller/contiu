<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\RecordOneOffIncome;
use App\Enums\CategoryType;
use App\Filament\Resources\Receivables\Pages\ListReceivables;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Livewire\QuickCreateMenu;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Support\FinanceSettings;
use App\Support\MarginReport;
use App\Support\Period;
use App\Support\PeriodSummary;
use App\Support\TaxEstimator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-07')));

function donation(array $overrides = []): OneOffIncome
{
    return app(RecordOneOffIncome::class)->handle([
        'received_on' => '2026-10-14',
        'title' => 'Buy Me a Coffee',
        'category_id' => Category::firstOrCreate(['type' => CategoryType::Income, 'name' => 'Donativos'])->id,
        'gross_amount' => 500,
        'commission' => 68,
        'commission_supplier_id' => (Supplier::firstWhere('name', 'Stripe') ?? Supplier::factory()->create(['name' => 'Stripe']))->id,
        'invoice_number' => 'FR 2026/7',
        'tax_exempt' => false,
        ...$overrides,
    ]);
}

it('records a donation with the platform commission', function () {
    $income = donation();

    $payment = Payment::sole();
    $commission = Expense::sole();

    expect($income->code)->toBe('RA-20261014-1')
        ->and($income->business_line)->toBe('Receitas avulsas')
        ->and($payment)->amount->toBe(500)->invoice_number->toBe('FR 2026/7')->tax_exempt->toBeFalse()
        ->and($payment->paid_on->toDateString())->toBe('2026-10-14')
        ->and($commission)->total->toBe(68)->is_paid->toBeTrue()->description->toBe('Comissão · Buy Me a Coffee')
        ->and($commission->category->name)->toBe('Comissões')
        ->and($commission->supplier->name)->toBe('Stripe')
        ->and($income->commission_expense_id)->toBe($commission->id);
})->group('integration');

it('records an income without commission', function () {
    donation(['commission' => null, 'commission_supplier_id' => null]);

    expect(Expense::count())->toBe(0)
        ->and(Payment::sole()->amount)->toBe(500);
})->group('integration');

it('counts in received, contracted, income by category, margins and tax estimate', function () {
    donation();
    $period = Period::of(2026);

    $summary = new PeriodSummary($period);
    $lines = (new MarginReport($period))->byBusinessLine();

    expect($summary->received())->toBe(500)
        ->and($summary->contracted())->toBe(500)
        ->and($summary->receivedByCategory())->toHaveKey('Donativos')
        ->and($lines)->toHaveKey('Receitas avulsas')
        ->and($lines['Receitas avulsas']['received'])->toBe(500)
        ->and((new TaxEstimator(app(FinanceSettings::class), 2026))->income())->toBe(500);
})->group('integration');

it('is never open or overdue', function () {
    donation();

    expect(Receivable::query()->open()->count())->toBe(0)
        ->and(Receivable::sole()->billableUrl())->toBeNull();
})->group('integration');

it('creates the income from a bank line and links payment and commission', function () {
    $line = BankTransaction::create(['hash' => 'd1', 'booked_on' => '2026-10-14', 'description' => 'STRIPE TECHNOLOGY EUROPE, LIMITED', 'amount' => 432, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->createOneOffIncome($line, [
        'title' => 'Buy Me a Coffee',
        'category_id' => null,
        'gross_amount' => 500,
        'commission' => 68,
        'commission_supplier_id' => Supplier::factory()->create()->id,
        'invoice_number' => 'FR 2026/7',
        'tax_exempt' => false,
    ]);

    expect($line->fresh()->difference())->toBe(0)
        ->and($line->links()->count())->toBe(2)
        ->and($line->links()->where('created_from_line', true)->count())->toBe(2)
        ->and(OneOffIncome::sole()->received_on->toDateString())->toBe('2026-10-14');
})->group('integration');

it('undoing the line removes income, receivable, payment and commission', function () {
    $line = BankTransaction::create(['hash' => 'd2', 'booked_on' => '2026-10-14', 'description' => 'STRIPE', 'amount' => 432, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->createOneOffIncome($line, ['title' => 'Donativo', 'gross_amount' => 500, 'commission' => 68, 'commission_supplier_id' => Supplier::factory()->create()->id]);

    app(ApplyBankTransaction::class)->undo($line);

    expect(OneOffIncome::count())->toBe(0)
        ->and(Receivable::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
})->group('integration');

it('deleting the income removes its receivable, payment and commission', function () {
    donation()->delete();

    expect(Receivable::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
})->group('integration');

it('records a one-off income from the quick create menu', function () {
    actingAsAdmin();
    $category = Category::factory()->create(['type' => CategoryType::Income, 'name' => 'Donativos']);

    Livewire::test(QuickCreateMenu::class)
        ->callAction('oneOffIncome', data: [
            'received_on' => '2026-11-01',
            'title' => 'Donativo',
            'category_id' => $category->id,
            'gross_amount' => 10,
            'invoice_number' => 'FR 2026/8',
        ])
        ->assertHasNoErrors();

    expect(OneOffIncome::sole()->title)->toBe('Donativo')
        ->and(Payment::sole()->amount)->toBe(1000);
})->group('ui');

it('deleting the payment of a one-off income removes the whole income', function () {
    donation();

    Payment::sole()->delete();

    expect(OneOffIncome::count())->toBe(0)
        ->and(Receivable::count())->toBe(0)
        ->and(Expense::count())->toBe(0);
})->group('integration');

it('editing the amount of a one-off income payment keeps the receivable in step', function () {
    $income = donation();

    Payment::sole()->update(['amount' => 700]);

    expect(Receivable::sole()->amount)->toBe(700)
        ->and(Receivable::sole()->outstanding)->toBe(0)
        ->and($income->fresh()->net_amount)->toBe(700);
})->group('integration');

it('gives a new code after an income of the same day was deleted', function () {
    $first = donation();
    donation(['category_id' => null]);
    $first->delete();

    expect(donation(['category_id' => null])->code)->toBe('RA-20261014-3');
})->group('integration');

it('shows one-off incomes as such in the receivables list', function () {
    actingAsAdmin();
    donation();

    Livewire::test(ListReceivables::class)->loadTable()
        ->set('activeTab', 'paid')
        ->assertSee('Receita avulsa')
        ->assertDontSee(RetainerResource::getUrl('view', ['record' => OneOffIncome::sole()->id]), escape: false);
})->group('ui');
