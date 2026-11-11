<?php

use App\Actions\LinkInvoiceAdvance;
use App\Actions\ManageCustomerAdvance;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\WithholdingRecord;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use App\Support\InvoiceXpressIssueAdvance;
use App\Support\WithholdingReview;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('fiscal');

/** @return array{FiscalDocument, FiscalDocument, Receivable} */
function serviceInvoicesWithAdvance(int $finalAmount = 40000): array
{
    actingAsAdmin();
    test()->travelTo(now()->setDate(2026, 11, 10));
    $client = Client::factory()->create(['vat_number' => 'PT508025338']);
    $charge = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 60000]);
    $advances = app(ManageCustomerAdvance::class);
    $payment = $advances->record($client->id, 20000, '2026-11-01', PaymentMethod::Transfer);
    $advances->apply($payment->id, $charge->id, '2026-11-05');
    $advance = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::InvoiceReceipt, 'number' => 'FR SINAL/1', 'document_date' => '2026-11-01',
        'net_amount' => 20000, 'vat_amount' => 0, 'total_amount' => 20000, 'party_tax_id' => '508025338',
    ]);
    $advance->links()->create(['payment_id' => $payment->id]);
    $final = FiscalDocument::factory()->create([
        'number' => 'FT SERVIÇO/1', 'document_date' => '2026-11-05',
        'net_amount' => $finalAmount, 'vat_amount' => 0, 'total_amount' => $finalAmount, 'party_tax_id' => 'PT508025338',
    ]);
    $final->links()->create(['receivable_id' => $charge->id]);

    return [$advance, $final, $charge];
}

it('links a documented customer advance to the final invoice without creating cash or duplicating turnover', function () {
    [$advance, $final, $charge] = serviceInvoicesWithAdvance();
    Livewire::test(ListFiscalDocuments::class)
        ->callAction(TestAction::make('linkInvoiceAdvance')->table($final), ['advance' => $advance->id])
        ->assertHasNoActionErrors();

    app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id);
    expect($final->fresh()->links->relatedFiscalDocument->is($advance))->toBeTrue()
        ->and($charge->fresh()->outstanding)->toBe(40000)
        ->and(DocumentedTurnover::forYear(2026)->billedNet)->toBe(60000)
        ->and(DocumentedTurnover::forYear(2026)->received)->toBe(20000)
        ->and(Payment::count())->toBe(1);

    $other = FiscalDocument::factory()->create($final->only(['type', 'document_date', 'party_tax_id', 'net_amount', 'vat_amount', 'total_amount']));
    $other->links()->create(['receivable_id' => $charge->id]);
    expect(fn () => app(LinkInvoiceAdvance::class)->handle($other->id, $advance->id))->toThrow(ValidationException::class);
})->group('ui');

it('refuses to link an advance if the issued final invoice still includes the full service price', function () {
    [$advance, $final] = serviceInvoicesWithAdvance(60000);
    expect(fn () => app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id))->toThrow(ValidationException::class)
        ->and($final->fresh()->links->related_fiscal_document_id)->toBeNull();
})->group('integration');

it('keeps a cash advance linkable when its invoice has a separate receipt', function () {
    [$advance, $final] = serviceInvoicesWithAdvance();
    $advance->update(['type' => FiscalDocumentType::Invoice]);
    $receipt = FiscalDocument::factory()->create([
        ...$advance->only(['document_date', 'net_amount', 'vat_amount', 'total_amount', 'party_tax_id']),
        'type' => FiscalDocumentType::Receipt,
    ]);
    $receipt->links()->create(['payment_id' => $advance->links->payment_id, 'related_fiscal_document_id' => $advance->id]);

    app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id);

    expect($final->fresh()->links->related_fiscal_document_id)->toBe($advance->id);
})->group('integration');

/** @return array{FiscalDocument, FiscalDocument, Receivable, Payment} */
function serviceInvoicesWithRetainedAdvance(bool $confirmRetention = true, bool $separateReceipt = false): array
{
    actingAsAdmin();
    test()->travelTo(now()->setDate(2026, 11, 10));
    $client = Client::factory()->create(['vat_number' => 'PT508025338']);
    $project = Project::factory()->for($client)->create(['vat_rate' => '0.2300']);
    $charge = Receivable::factory()->for($project, 'billable')->create(['amount' => 73800]);
    $advances = app(ManageCustomerAdvance::class);
    $payment = $advances->record($client->id, 19600, '2026-11-01', PaymentMethod::Transfer);
    $advance = FiscalDocument::factory()->create([
        'type' => $separateReceipt ? FiscalDocumentType::Invoice : FiscalDocumentType::InvoiceReceipt,
        'number' => $separateReceipt ? 'FT SINAL/1' : 'FR SINAL/1', 'document_date' => '2026-11-01',
        'net_amount' => 20000, 'vat_amount' => 4600, 'total_amount' => 24600, 'party_tax_id' => '508025338',
    ]);
    $advance->links()->create(['payment_id' => $payment->id]);
    $evidence = $advance;
    if ($separateReceipt) {
        $evidence = FiscalDocument::factory()->create([
            ...$advance->only(['document_date', 'net_amount', 'vat_amount', 'total_amount', 'party_tax_id']),
            'type' => FiscalDocumentType::Receipt, 'number' => 'RG SINAL/1',
        ]);
        $evidence->links()->create(['payment_id' => $payment->id, 'related_fiscal_document_id' => $advance->id]);
    }
    if ($confirmRetention) {
        WithholdingReview::record($evidence->id, 5000);
    }
    $advances->apply($payment->id, $charge->id, '2026-11-05');

    $final = FiscalDocument::factory()->create([
        'number' => 'FT SERVIÇO/1', 'document_date' => '2026-11-05',
        'net_amount' => 40000, 'vat_amount' => 9200, 'total_amount' => 49200, 'party_tax_id' => 'PT508025338',
    ]);
    $final->links()->create(['receivable_id' => $charge->id]);

    return [$advance, $final, $charge, $charge->payments()->sole()];
}

it('deducts the full documented advance in preview when cash and confirmed IRS cover its gross', function (bool $separateReceipt) {
    [$advance, , $charge, $payment] = serviceInvoicesWithRetainedAdvance(separateReceipt: $separateReceipt);

    $source = InvoiceXpressIssueAdvance::source($charge, $payment, 2300, '2026-11-05');
    $adjustment = InvoiceXpressIssueAdvance::amounts($source, ['net' => 60000, 'tax' => 13800, 'total' => 73800], 2300);

    expect($source->id)->toBe($advance->id);
    expect($adjustment['deduction'])->toBe(['net' => 20000, 'tax' => 4600, 'total' => 24600]);
    expect($adjustment['balance'])->toBe(['net' => 40000, 'tax' => 9200, 'total' => 49200]);
})->with(['invoice-receipt' => false, 'invoice with a separate receipt' => true])->group('integration');

it('links a manually issued final invoice for the balance without duplicating the retained IRS or cash', function (bool $separateReceipt) {
    [$advance, $final, $charge, $payment] = serviceInvoicesWithRetainedAdvance(separateReceipt: $separateReceipt);

    Livewire::test(ListFiscalDocuments::class)
        ->callAction(TestAction::make('linkInvoiceAdvance')->table($final), ['advance' => $advance->id])
        ->assertHasNoActionErrors();
    app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id);

    expect($final->fresh()->links->related_fiscal_document_id)->toBe($advance->id);
    expect($payment->fresh()->amount)->toBe(19600);
    expect($payment->allocations()->sole()->amount)->toBe(19600);
    expect($charge->fresh()->outstanding)->toBe(49200);
    expect(WithholdingRecord::query()->sole()->retained_amount)->toBe(5000);
    expect(WithholdingReview::forYear(2026)->retainedTotal)->toBe(5000);
    expect(DocumentedTurnover::forYear(2026)->received)->toBe(19600);
})->with(['invoice-receipt' => false, 'invoice with a separate receipt' => true])->group('ui');

it('requires current proof of IRS for the difference between cash and the advance document', function (bool $stale) {
    [$advance, $final, $charge, $payment] = serviceInvoicesWithRetainedAdvance(confirmRetention: $stale);
    if ($stale) {
        $advance->update(['number' => 'FR REVISTO/1']);
    }

    expect(fn () => InvoiceXpressIssueAdvance::source($charge, $payment, 2300, '2026-11-05'))->toThrow(ValidationException::class);
    expect(fn () => app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id))->toThrow(ValidationException::class);
    expect($final->fresh()->links->related_fiscal_document_id)->toBeNull();
})->with(['missing confirmation' => false, 'stale confirmation' => true])->group('integration');

it('refuses to link a final invoice with a different VAT breakdown even when the gross balance matches', function () {
    [$advance, $final] = serviceInvoicesWithAdvance();
    $final->update(['net_amount' => 32520, 'vat_amount' => 7480]);

    expect(fn () => app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id))->toThrow(ValidationException::class);
    expect($final->fresh()->links->related_fiscal_document_id)->toBeNull();
})->group('integration');

it('keeps documentary balances of zero and one cent linkable', function (int $balance) {
    [$advance, $final, $charge] = serviceInvoicesWithAdvance($balance);
    $charge->update(['amount' => 20000 + $balance]);

    app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id);

    expect($final->fresh()->links->related_fiscal_document_id)->toBe($advance->id);
})->with([0, 1])->group('integration');

it('calculates a one-cent balance only when the remaining VAT reconciles exactly', function () {
    $advance = FiscalDocument::factory()->make(['net_amount' => 20000, 'vat_amount' => 4600, 'total_amount' => 24600]);

    $adjustment = InvoiceXpressIssueAdvance::amounts($advance, ['net' => 20001, 'tax' => 4600, 'total' => 24601], 2300);

    expect($adjustment['balance'])->toBe(['net' => 1, 'tax' => 0, 'total' => 1]);
    expect(fn () => InvoiceXpressIssueAdvance::amounts($advance, ['net' => 20001, 'tax' => 4601, 'total' => 24602], 2300))
        ->toThrow(ValidationException::class);
})->group('integration');

it('requires a single allocated advance before linking the final invoice', function () {
    [$advance, $final, $charge] = serviceInvoicesWithAdvance();
    $advances = app(ManageCustomerAdvance::class);
    $secondPayment = $advances->record($charge->client->id, 5000, '2026-11-02', PaymentMethod::Transfer);
    $advances->apply($secondPayment->id, $charge->id, '2026-11-04');

    expect(fn () => app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id))->toThrow(ValidationException::class);
    expect($final->fresh()->links->related_fiscal_document_id)->toBeNull();
})->group('integration');

it('cannot link the advance of another account even with the same customer NIF', function () {
    [$advance, $final] = serviceInvoicesWithAdvance();
    $other = Account::factory()->create();
    $foreignAdvance = CurrentAccount::run($other, fn () => FiscalDocument::factory()->create(
        $advance->only(['type', 'document_date', 'party_tax_id', 'net_amount', 'vat_amount', 'total_amount']),
    ));

    expect(fn () => app(LinkInvoiceAdvance::class)->handle($final->id, $foreignAdvance->id))->toThrow(ValidationException::class);
    expect($final->fresh()->links->related_fiscal_document_id)->toBeNull();
})->group('integration');
