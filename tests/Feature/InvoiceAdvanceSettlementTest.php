<?php

use App\Actions\LinkInvoiceAdvance;
use App\Actions\ManageCustomerAdvance;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\DocumentedTurnover;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

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
});

it('refuses to link an advance if the issued final invoice still includes the full service price', function () {
    [$advance, $final] = serviceInvoicesWithAdvance(60000);
    expect(fn () => app(LinkInvoiceAdvance::class)->handle($final->id, $advance->id))->toThrow(ValidationException::class)
        ->and($final->fresh()->links->related_fiscal_document_id)->toBeNull();
});
