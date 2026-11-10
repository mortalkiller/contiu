<?php

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function fiscalDocumentFormData(array $overrides = []): array
{
    return array_replace([
        'type' => 'invoice',
        'direction' => 'issued',
        'number' => 'FT 2026/1',
        'origin' => 'Registo manual',
        'document_date' => '2026-11-10',
        'operation_date' => '2026-11-09',
        'currency' => 'EUR',
        'net_amount' => '100.05',
        'vat_amount' => '23.01',
        'total_amount' => '123.06',
        'party_name' => 'Cliente Exemplo',
        'party_tax_id' => 'PT123456789',
    ], $overrides);
}

function actAsFiscalDocumentsOwner(): void
{
    test()->actingAs(ownerOf(test()->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant(test()->account);
}

it('registers an invoice and two partial receipts against the same invoice', function () {
    actAsFiscalDocumentsOwner();
    $receivable = Receivable::factory()->create(['amount' => 12306]);
    $first = Payment::factory()->for($receivable)->create(['amount' => 6000, 'invoice_number' => 'REF IMPORTADA']);
    $second = Payment::factory()->for($receivable)->create(['amount' => 6306]);

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData(['receivable_id' => $receivable->id]))
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = FiscalDocument::query()->sole();
    expect($invoice->total_amount)->toBe(12306)
        ->and($invoice->net_amount)->toBe(10005)
        ->and($invoice->vat_amount)->toBe(2301)
        ->and($invoice->party_tax_id)->toBe('PT123456789')
        ->and($invoice->links->receivable_id)->toBe($receivable->id)
        ->and($invoice->links->payment_id)->toBeNull();

    foreach ([[$first, 'RC 2026/1', '60.00'], [$second, 'RC 2026/2', '63.06']] as [$payment, $number, $amount]) {
        Livewire::test(CreateFiscalDocument::class)
            ->fillForm(fiscalDocumentFormData([
                'type' => 'receipt',
                'number' => $number,
                'net_amount' => $amount,
                'vat_amount' => '0',
                'total_amount' => $amount,
                'payment_id' => $payment->id,
                'related_fiscal_document_id' => $invoice->id,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect($invoice->relatedDocuments()->pluck('number')->all())->toEqualCanonicalizing(['RC 2026/1', 'RC 2026/2']);
    expect($first->refresh()->invoice_number)->toBe('REF IMPORTADA');

    $receipt = FiscalDocument::where('number', 'RC 2026/1')->sole();
    Livewire::test(EditFiscalDocument::class, ['record' => $receipt->id])
        ->assertFormSet(['payment_id' => $first->id, 'related_fiscal_document_id' => $invoice->id])
        ->assertSee('Referência anterior por confirmar')
        ->assertSee('REF IMPORTADA')
        ->fillForm(['party_name' => 'Cliente Exemplo, Lda.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($receipt->refresh()->party_name)->toBe('Cliente Exemplo, Lda.');
    expect($receipt->links->relatedFiscalDocument->is($invoice))->toBeTrue();
    Livewire::test(ListFiscalDocuments::class)->assertCanSeeTableRecords(FiscalDocument::all());
});

it('registers an invoice-receipt as one document with an explicit expense link', function () {
    actAsFiscalDocumentsOwner();
    $expense = Expense::factory()->create(['invoice_number' => 'LEGADO FORNECEDOR']);

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData([
            'type' => 'invoice_receipt',
            'direction' => 'received',
            'number' => 'FR 12',
            'expense_id' => $expense->id,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $document = FiscalDocument::query()->sole();
    expect($document->type->value)->toBe('invoice_receipt')
        ->and($document->direction->value)->toBe('received')
        ->and($document->links->expense_id)->toBe($expense->id)
        ->and($document->links->related_fiscal_document_id)->toBeNull();

    Livewire::test(ListFiscalDocuments::class)
        ->mountAction(TestAction::make('view')->table($document))
        ->assertSchemaStateSet(['expense_id' => $expense->id]);

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->assertSee('LEGADO FORNECEDOR');
});

it('keeps a signed credit note and lets an owner remove its last link', function () {
    actAsFiscalDocumentsOwner();
    $invoice = FiscalDocument::factory()->create();

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData([
            'type' => 'credit_note',
            'number' => 'NC 1',
            'net_amount' => '-100.05',
            'vat_amount' => '-23.01',
            'total_amount' => '-123.06',
            'related_fiscal_document_id' => $invoice->id,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $creditNote = FiscalDocument::where('number', 'NC 1')->sole();
    expect($creditNote->total_amount)->toBe(-12306);

    Livewire::test(EditFiscalDocument::class, ['record' => $creditNote->id])
        ->fillForm(['related_fiscal_document_id' => $creditNote->id])
        ->call('save')
        ->assertHasFormErrors(['related_fiscal_document_id'])
        ->fillForm(['related_fiscal_document_id' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($creditNote->fresh()->links)->toBeNull();
});

it('filters voided documents and retains their links and financial values', function () {
    actAsFiscalDocumentsOwner();
    $payment = Payment::factory()->create(['amount' => 12300, 'invoice_number' => 'REF LEGADA']);
    $invoice = FiscalDocument::factory()->create();
    $document = FiscalDocument::factory()->create();
    $link = $document->links()->create(['payment_id' => $payment->id, 'related_fiscal_document_id' => $invoice->id]);

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->assertFormSet(['status' => FiscalDocumentStatus::Active])
        ->fillForm(['status' => 'voided'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($document->refresh()->status->value)->toBe('voided')
        ->and($document->total_amount)->toBe(12300);
    expect($document->links->id)->toBe($link->id)
        ->and($document->links->payment_id)->toBe($payment->id)
        ->and($document->links->related_fiscal_document_id)->toBe($invoice->id);
    expect($payment->refresh()->amount)->toBe(12300)
        ->and($payment->invoice_number)->toBe('REF LEGADA');

    Livewire::test(ListFiscalDocuments::class)
        ->filterTable('status', 'voided')
        ->assertCanSeeTableRecords([$document])
        ->assertCanNotSeeTableRecords([$invoice])
        ->assertSee('Anulado');
});

it('rejects an inconsistent total before writing a document or its links', function () {
    actAsFiscalDocumentsOwner();
    $expense = Expense::factory()->create();

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData(['total_amount' => '123.05', 'expense_id' => $expense->id]))
        ->call('create')
        ->assertHasFormErrors(['total_amount'])
        ->assertSee('O total deve ser igual à soma da base tributável e do IVA.');

    $this->assertDatabaseEmpty('fiscal_documents');
    $this->assertDatabaseEmpty('fiscal_document_links');
});

it('rejects forged form links to another account', function () {
    actAsFiscalDocumentsOwner();
    $foreign = CurrentAccount::run(Account::factory()->create(), fn (): array => [
        'payment_id' => Payment::factory()->create()->id,
        'expense_id' => Expense::factory()->create()->id,
        'receivable_id' => Receivable::factory()->create()->id,
        'related_fiscal_document_id' => FiscalDocument::factory()->create()->id,
    ]);

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData($foreign))
        ->call('create')
        ->assertHasFormErrors(array_keys($foreign));

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData(['payment_id' => 'invalid-id']))
        ->call('create')
        ->assertHasFormErrors(['payment_id']);

    expect(FiscalDocument::count())->toBe(0);
    $this->assertDatabaseEmpty('fiscal_document_links');
});

it('rejects a non-integer target ID even when the database can coerce it', function () {
    actAsFiscalDocumentsOwner();
    $payment = Payment::factory()->create();

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm(fiscalDocumentFormData(['payment_id' => "{$payment->id}e0"]))
        ->call('create')
        ->assertHasFormErrors(['payment_id']);

    $this->assertDatabaseEmpty('fiscal_documents');
});

it('keeps records from another account out of the list and edit routes', function () {
    actAsFiscalDocumentsOwner();
    $own = FiscalDocument::factory()->create();
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create());

    Livewire::test(ListFiscalDocuments::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    asAWebRequest();
    $this->get(FiscalDocumentResource::getUrl('edit', ['record' => $foreign], tenant: $this->account))
        ->assertNotFound();
});

it('refuses document creation when the account is read only', function () {
    $account = Account::factory()->create(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    asAWebRequest();

    $this->get(FiscalDocumentResource::getUrl(tenant: $account))->assertOk();
    $this->get(FiscalDocumentResource::getUrl('create', tenant: $account))->assertForbidden();
});

it('enforces the account of every link target in the database', function (string $column, string $targetModel) {
    $document = FiscalDocument::factory()->create();
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => $targetModel::factory()->create());

    $link = [
        'account_id' => $this->account->id,
        'fiscal_document_id' => $document->id,
        $column => $foreign->id,
    ];

    expect(fn () => DB::transaction(fn () => DB::table('fiscal_document_links')->insert($link)))
        ->toThrow(fn (QueryException $exception) => expect(strtolower($exception->getMessage()))->toContain('foreign key constraint'));
})->with([
    'receivable' => ['receivable_id', Receivable::class],
    'payment' => ['payment_id', Payment::class],
    'expense' => ['expense_id', Expense::class],
    'related document' => ['related_fiscal_document_id', FiscalDocument::class],
    'source document' => ['fiscal_document_id', FiscalDocument::class],
]);

it('blocks deleting linked targets even when model events are bypassed', function (string $column, string $targetModel) {
    $document = FiscalDocument::factory()->create();
    $target = $targetModel::factory()->create();
    DB::table('fiscal_document_links')->insert([
        'account_id' => $this->account->id,
        'fiscal_document_id' => $document->id,
        $column => $target->id,
    ]);

    expect(fn () => DB::transaction(fn () => DB::table($target->getTable())->where('id', $target->id)->delete()))
        ->toThrow(fn (QueryException $exception) => expect(strtolower($exception->getMessage()))->toContain('foreign key constraint'));

    $this->assertModelExists($target);
})->with([
    'receivable' => ['receivable_id', Receivable::class],
    'payment' => ['payment_id', Payment::class],
    'expense' => ['expense_id', Expense::class],
    'related document' => ['related_fiscal_document_id', FiscalDocument::class],
]);

it('keeps the existing account deletion cascade when linked rows belong to that account', function () {
    $document = FiscalDocument::factory()->create();
    $related = FiscalDocument::factory()->create();
    $payment = Payment::factory()->create();
    $expense = Expense::factory()->create();
    DB::table('fiscal_document_links')->insert([
        'account_id' => $this->account->id,
        'fiscal_document_id' => $document->id,
        'receivable_id' => $payment->receivable_id,
        'payment_id' => $payment->id,
        'expense_id' => $expense->id,
        'related_fiscal_document_id' => $related->id,
    ]);

    DB::table('accounts')->where('id', $this->account->id)->delete();

    $this->assertDatabaseEmpty('fiscal_documents');
    $this->assertDatabaseEmpty('fiscal_document_links');
});

it('lists each document with its type, direction and what it is linked to', function () {
    actingAsAdmin();
    $receivable = Receivable::factory()->create(['description' => 'Sinal de 50%', 'due_on' => '2026-11-15']);
    $invoice = FiscalDocument::factory()->create(['number' => 'FT 2026/9']);
    $invoice->links()->create(['receivable_id' => $receivable->id]);
    $creditNote = FiscalDocument::factory()->create(['number' => 'NC 2026/2', 'type' => FiscalDocumentType::CreditNote]);
    $creditNote->links()->create(['related_fiscal_document_id' => $invoice->id]);
    FiscalDocument::factory()->create(['number' => 'FT A/2291', 'direction' => FiscalDocumentDirection::Received]);

    Livewire::test(ListFiscalDocuments::class)
        ->assertTableColumnStateSet('linkedTo', $receivable->label, $invoice)
        ->assertTableColumnStateSet('linkedTo', 'FT 2026/9', $creditNote)
        ->assertSee('Valor a receber · vence a 15/11/2026')
        ->assertSee('Nota de crédito · emitida')
        ->assertSee('Fatura · recebida')
        ->assertSee('Documento original')
        ->assertSee('Sem ligação');
});
