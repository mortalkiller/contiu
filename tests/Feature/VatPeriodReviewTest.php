<?php

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Pages\VatReviewPage;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\VatPeriodReview;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('separates documentary VAT from items requiring fiscal review and excluded documents', function () {
    $invoice = FiscalDocument::factory()->create(['document_date' => '2026-03-31', 'vat_amount' => 2301, 'total_amount' => 12301]);
    $purchase = FiscalDocument::factory()->create(['document_date' => '2026-01-01', 'direction' => FiscalDocumentDirection::Received]);
    $credit = FiscalDocument::factory()->create([
        'document_date' => '2026-02-01', 'type' => FiscalDocumentType::CreditNote,
        'net_amount' => -10000, 'vat_amount' => -2300, 'total_amount' => -12300,
    ]);
    $zero = FiscalDocument::factory()->create(['document_date' => '2026-03-01', 'vat_amount' => 0, 'total_amount' => 10000]);
    $receipt = FiscalDocument::factory()->create(['document_date' => '2026-03-02', 'type' => FiscalDocumentType::Receipt]);
    $void = FiscalDocument::factory()->create(['document_date' => '2026-03-02', 'status' => FiscalDocumentStatus::Voided]);
    $foreign = FiscalDocument::factory()->create(['document_date' => '2026-03-02', 'currency' => 'USD']);
    $crossPeriod = FiscalDocument::factory()->create(['document_date' => '2026-04-01', 'operation_date' => '2026-03-31']);
    FiscalDocument::factory()->create(['document_date' => '2026-04-01']);
    CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create(['document_date' => '2026-03-31']));

    $review = VatPeriodReview::forPeriod(2026, 1, 3);

    expect($review->issuedVat)->toBe(2301)
        ->and($review->receivedVat)->toBe(2300)
        ->and($review->documents->pluck('document.id', 'status')->all())->toHaveKeys(['supported', 'unresolved', 'excluded'])
        ->and($review->documents->where('status', 'supported')->pluck('document.id')->all())->toEqualCanonicalizing([$invoice->id, $purchase->id])
        ->and($review->documents->where('status', 'unresolved')->pluck('document.id')->all())->toEqualCanonicalizing([$credit->id, $zero->id, $crossPeriod->id])
        ->and($review->documents->where('status', 'excluded')->pluck('document.id')->all())->toEqualCanonicalizing([$receipt->id, $void->id, $foreign->id])
        ->and($review->confirmedBalance())->toBeNull();
});

it('keeps explicit signed balances separate and invalidates a confirmation when source evidence changes', function () {
    $document = FiscalDocument::factory()->create(['document_date' => '2026-03-31']);
    $review = VatPeriodReview::forPeriod(2026, 3);
    $review->confirm(2301, 1005, -1500, 'Apuramento conferido com a contabilidade.');

    expect(VatPeriodReview::forPeriod(2026, 3)->confirmedBalance())->toBe(-204)
        ->and(VatPeriodReview::forPeriod(2026, 1, 3)->confirmedBalance())->toBeNull()
        ->and(CurrentAccount::run(Account::factory()->create(), fn () => VatPeriodReview::forPeriod(2026, 3)->confirmedBalance()))->toBeNull();

    $document->update(['vat_amount' => 2400, 'total_amount' => 12400]);

    expect(VatPeriodReview::forPeriod(2026, 3)->confirmedBalance())->toBeNull()
        ->and(fn () => $review->confirm(2301, 1005, -1500, 'Apuramento anterior.'))->toThrow(ValidationException::class);
});

it('exposes an independent page that confirms cents for the selected period', function () {
    actingAsAdmin();

    $this->get(VatReviewPage::getUrl(parameters: ['year' => 2026, 'month' => 1, 'months' => 3]))
        ->assertOk()
        ->assertSee('1.º trimestre de 2026');

    Livewire::test(VatReviewPage::class, ['year' => 2026, 'month' => 3, 'months' => 1])
        ->set('liquidated', '23,01')
        ->set('deductible', '10,05')
        ->set('adjustments', '-15,00')
        ->set('reference', 'Apuramento validado')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(VatPeriodReview::forPeriod(2026, 3)->confirmedBalance())->toBe(-204);
});

it('refuses VAT confirmations in a read-only account', function () {
    $this->actingAs(ownerOf($this->account));
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    expect(fn () => VatPeriodReview::forPeriod(2026, 3)->confirm(0, 0, 0, 'Período revisto.'))
        ->toThrow(AccountIsReadOnly::class);
});

it('shows the review to a read-only member and refuses a direct Livewire write', function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    Livewire::test(VatReviewPage::class)
        ->assertSee('Apuramento do período')
        ->assertDontSee('Confirmar apuramento')
        ->call('confirm')
        ->assertForbidden();
    $this->assertDatabaseEmpty('vat_period_confirmations');
});

it('exposes the confirmed payment amount and manual dates for forecasts', function () {
    $this->travelTo(now()->setDate(2026, 6, 20));

    VatPeriodReview::forPeriod(2026, 1, 3)
        ->confirm(2301, 1005, 0, 'Apuramento conferido', '2026-05-25', '2026-05-22');
    $review = VatPeriodReview::forPeriod(2026, 1, 3);

    expect($review->amountDue())->toBe(1296)
        ->and($review->dueOn()?->toDateString())->toBe('2026-05-25')
        ->and($review->paidOn()?->toDateString())->toBe('2026-05-22')
        ->and($review->isPaid())->toBeTrue();
});

it('rejects recording a payment date for a credit or in the future', function (int $liquidated, string $paidOn) {
    $this->travelTo(now()->setDate(2026, 6, 20));
    $review = VatPeriodReview::forPeriod(2026, 1, 3);

    expect(fn () => $review->confirm($liquidated, 1005, 0, 'Apuramento conferido', '2026-05-25', $paidOn))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseEmpty('vat_period_confirmations');
})->with([
    'credit' => [1000, '2026-05-22'],
    'future payment' => [2301, '2026-06-21'],
]);
