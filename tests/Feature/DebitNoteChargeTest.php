<?php

use App\Actions\RecordDebitNoteCharge;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\MonthReview;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function debitNoteWithOriginal(array $attributes = [], string $customerTaxId = '508025338'): array
{
    $client = Client::factory()->create(['vat_number' => $customerTaxId]);
    $receivable = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 12300]);
    $original = FiscalDocument::factory()->create([
        'document_date' => '2026-11-01', 'party_tax_id' => 'PT508025338',
        'net_amount' => 10000, 'vat_amount' => 2300, 'total_amount' => 12300,
    ]);
    $original->links()->create(['receivable_id' => $receivable->id]);
    $debitNote = FiscalDocument::factory()->create($attributes + [
        'type' => FiscalDocumentType::DebitNote, 'number' => 'ND TESTE/1',
        'document_date' => '2026-11-05', 'party_tax_id' => '508025338',
        'net_amount' => 2000, 'vat_amount' => 460, 'total_amount' => 2460,
    ]);
    $debitNote->links()->create(['related_fiscal_document_id' => $original->id]);

    return [$debitNote, $original, $receivable];
}

it('opens a separate charge from a debit note without changing the original or recording cash', function () {
    actingAsAdmin();
    $this->travelTo(now()->setDate(2026, 11, 10));
    [$note, $invoice, $originalCharge] = debitNoteWithOriginal();

    Livewire::test(ListFiscalDocuments::class)
        ->callAction(TestAction::make('recordDebitNoteCharge')->table($note), ['due_on' => '2026-12-31'])
        ->assertHasNoActionErrors();

    $charge = $note->fresh()->links->receivable;
    expect($charge->outstanding)->toBe(2460)
        ->and($charge->due_on->toDateString())->toBe('2026-12-31')
        ->and($charge->billable->is($originalCharge->billable))->toBeTrue()
        ->and($note->fresh()->links->relatedFiscalDocument->is($invoice))->toBeTrue()
        ->and($originalCharge->fresh()->outstanding)->toBe(12300)
        ->and(Payment::count())->toBe(0);

    $again = app(RecordDebitNoteCharge::class)->handle($note->id, '2026-12-31', 2460, $invoice->id);
    expect($again->is($charge))->toBeTrue()->and(Receivable::count())->toBe(2);
    expect((new MonthReview(today()))->receivablesQuery(withFiscalReference: true)->findOrFail($charge->id)->fiscal_reference)
        ->toBe($note->number);
});

it('rejects an ineligible or changed debit note without opening a charge', function (array $attributes, string $dueOn, int $total, string $customerTaxId = '508025338') {
    actingAsAdmin();
    $this->travelTo(now()->setDate(2026, 11, 10));
    [$note, $invoice] = debitNoteWithOriginal($attributes, $customerTaxId);

    expect(fn () => app(RecordDebitNoteCharge::class)->handle($note->id, $dueOn, $total, $invoice->id))
        ->toThrow(ValidationException::class);
    expect(Receivable::count())->toBe(1);
})->with([
    'other customer' => [['party_tax_id' => '501234560'], '2026-11-30', 2460],
    'charge belongs to another customer' => [[], '2026-11-30', 2460, '501234560'],
    'voided' => [['status' => FiscalDocumentStatus::Voided], '2026-11-30', 2460],
    'stale amount' => [[], '2026-11-30', 2500],
    'due before issue' => [[], '2026-11-04', 2460],
]);
