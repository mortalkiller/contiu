<?php

use App\Actions\RecordAllocatedPayment;
use App\Enums\FiscalDocumentDirection;
use App\Enums\PaymentMethod;
use App\Filament\Pages\MonthClose;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('exports only the selected account and month and escapes spreadsheet formulas', function () {
    $other = Account::factory()->create();
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);

    $receivable = Receivable::factory()->create([
        'due_on' => '2026-12-12', 'amount' => 12509, 'description' => '=1+1',
    ]);
    FiscalDocument::factory()->create(['number' => 'FT 2026/34'])
        ->links()->create(['receivable_id' => $receivable->id]);
    Payment::factory()->for($receivable)->create(['paid_on' => '2027-01-02', 'amount' => 12509, 'invoice_number' => 'APENAS JANEIRO']);
    Expense::factory()->unpaid()->create([
        'spent_on' => '2026-12-31', 'description' => 'Deslocação', 'invoice_number' => "\t@SUM(1;2)",
        'net_amount' => 10001, 'vat_rate' => 0.23,
    ]);
    CurrentAccount::run($other, fn () => Expense::factory()->create([
        'spent_on' => '2026-12-31', 'description' => 'APENAS OUTRA CONTA',
    ]));

    $page = Livewire::test(MonthClose::class, ['month' => '2027-01'])
        ->callAction('previousMonth')
        ->callAction('exportMonthCsv')
        ->assertFileDownloaded('fecho-'.$this->account->slug.'-2026-12.csv');

    $csv = base64_decode($page->effects['download']['content']);

    expect($csv)
        ->toContain('Saldo atual em aberto (EUR)', '125,09;0,00', '123,01;123,01', 'FT 2026/34', '2026-12-31')
        ->toContain("'\t@SUM(1;2)", "'=1+1", 'Por pagar', 'Falta fatura ou fatura-recibo')
        ->not->toContain('APENAS JANEIRO', 'APENAS OUTRA CONTA');
});

it('exports one row per split receipt and preserves signed statement amounts', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-01']);
    $second = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-02']);
    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 70003, 'paid_on' => '2027-01-01', 'method' => PaymentMethod::Transfer, 'invoice_number' => 'REF REPARTIDA',
    ], [$first->id => 40001, $second->id => 30002]);
    // A legacy date can be stored without the time component in SQLite.
    DB::table('payments')->where('id', $payment->id)->update(['paid_on' => '2027-01-01']);
    BankTransaction::create([
        'hash' => 'month-close-pending', 'booked_on' => '2027-01-31', 'description' => 'Linha pendente',
        'amount' => -5009, 'import_batch' => 'teste',
    ]);

    $page = Livewire::test(MonthClose::class, ['month' => '2027-01'])
        ->callAction('exportMonthCsv')
        ->assertFileDownloaded('fecho-'.$this->account->slug.'-2027-01.csv');

    $csv = base64_decode($page->effects['download']['content']);

    expect(substr_count($csv, 'Recebimento;'.$payment->id.';'))->toBe(1)
        ->and($csv)->toContain('2027-01-01;700,03', 'REF REPARTIDA', 'Falta recibo ou fatura-recibo')
        ->toContain('2027-01-31;-50,09', 'Por tratar');
});

it('lets a read-only owner export and refuses the export for a non-member', function () {
    $readOnly = Account::factory()->create(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    $this->actingAs(ownerOf($readOnly));
    CurrentAccount::set($readOnly);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($readOnly);

    Livewire::test(MonthClose::class, ['month' => '2026-12'])
        ->callAction('exportMonthCsv')
        ->assertFileDownloaded('fecho-'.$readOnly->slug.'-2026-12.csv');

    $this->actingAs(ownerOf($this->account));
    Livewire::test(MonthClose::class, ['month' => '2026-12'])
        ->call('mountAction', 'exportMonthCsv')
        ->assertForbidden();
});

it('only exports an old expense reference as unconfirmed if it differs from the linked invoice', function () {
    actingAsAdmin();
    $expense = Expense::factory()->create(['spent_on' => '2026-12-12', 'invoice_number' => 'FT IMPORTADA']);
    FiscalDocument::factory()->create([
        'number' => 'FT IMPORTADA',
        'direction' => FiscalDocumentDirection::Received,
    ])->links()->create(['expense_id' => $expense->id]);

    $page = Livewire::test(MonthClose::class, ['month' => '2026-12'])->callAction('exportMonthCsv');

    expect(substr_count(base64_decode($page->effects['download']['content']), 'FT IMPORTADA'))->toBe(1);
});
