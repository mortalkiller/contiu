<?php

use App\Actions\RecordTaxPayment;
use App\Enums\CategoryType;
use App\Enums\FiscalDocumentType;
use App\Filament\Pages\AnnualFiscalReview as AnnualFiscalReviewPage;
use App\Models\Category;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Support\AnnualFiscalReview;
use Livewire\Livewire;

it('shows separately dated annual totals and gives the origin and unresolved evidence', function () {
    actingAsAdmin();
    FiscalDocument::factory()->create(['number' => 'FT ANUAL', 'document_date' => '2026-12-18', 'net_amount' => 120000, 'total_amount' => 120000, 'vat_amount' => 0]);
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'document_date' => '2026-12-20', 'net_amount' => 500, 'total_amount' => 500, 'vat_amount' => 0]);
    FiscalDocument::factory()->create(['currency' => 'USD', 'document_date' => '2026-12-18']);
    Payment::factory()->create(['paid_on' => '2027-01-08', 'amount' => 70000]);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-12-22', 'net_amount' => 10001, 'vat_rate' => 0.23]);
    $tax = Category::factory()->create(['type' => CategoryType::Expense, 'name' => RecordTaxPayment::CATEGORY]);
    Expense::factory()->for($tax)->create(['spent_on' => '2025-12-31', 'paid_on' => '2026-01-03', 'net_amount' => 9999]);

    $summary = new AnnualFiscalReview(2026);

    expect($summary->totals())->toMatchArray([
        'billed' => 119500, 'received' => 0, 'expenses' => 12301, 'taxPaid' => 9999,
        'otherCurrencies' => 1, 'expensesWithoutProof' => 1,
    ]);

    Livewire::test(AnnualFiscalReviewPage::class, ['year' => 2026])
        ->assertSee('Ver documentos')
        ->assertSee('Despesas sem comprovativo')
        ->assertSee('Ver pagamentos de impostos e contribuições');
});
