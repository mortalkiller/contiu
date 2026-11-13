<?php

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Models\Article;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

pest()->group('ui', 'invoicing', 'fiscal');

/*
 * A fiscal document's own page: what each kind of document shows (a receipt the invoice it pays,
 * a note the invoice it changes, a cancelled document that it no longer counts) and the one thing
 * each kind offers to do next. Issuing, cancelling and the other actions have their own tests.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    invoicingConnection();

    $this->atProvider = function (FiscalDocument $document, string $remoteId = '9001'): FiscalDocument {
        InvoicingRecords::store($document, new RemoteDocument('invoices', $remoteId, $document->type, RemoteDocumentState::Issued, CarbonImmutable::parse('2026-11-12'), $document->net_amount, $document->vat_amount, $document->total_amount, $document->number), IntegrationRecordState::InSync);

        return $document;
    };
    $this->issued = fn (array $attributes = [], array $lines = [['description' => 'Desenho do site', 'quantity' => 1000, 'unit_price' => 15000]]): FiscalDocument => ($this->atProvider)(
        draftDocument(['status' => FiscalDocumentStatus::Active, 'number' => 'FT 2026/15', 'document_date' => '2026-11-12', 'issued_at' => '2026-11-12 10:42', ...$attributes], $lines),
    );
    $this->receivableOf = fn (int $amount, array $attributes = [], array $project = []): Receivable => Receivable::factory()
        ->for(Project::factory()->for(Client::factory())->create($project), 'billable')
        ->create(['description' => '2.ª prestação', 'amount' => $amount, 'due_on' => '2026-12-12', ...$attributes]);
});

it('shows an issued invoice with its state, its dates, its lines and what it is tied to', function () {
    $article = Article::factory()->create(['code' => 'DEV-H']);
    $document = ($this->issued)(
        ['due_date' => '2026-12-12', 'series' => '2026', 'vat_exemption_reason' => 'M10'],
        [
            ['description' => 'Desenho do site institucional', 'quantity' => 1000, 'unit_price' => 15000],
            ['description' => 'Hora de desenvolvimento', 'quantity' => 2000, 'unit_price' => 4500, 'unit' => 'h', 'article_id' => $article->id],
        ],
    );
    $document->links()->create(['receivable_id' => ($this->receivableOf)(24000, project: ['code' => 'DEMO-03', 'title' => 'Site institucional'])->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertOk()
        ->assertSee('Fatura FT 2026/15')
        ->assertSeeInOrder(['Emitida', 'Por receber', 'emitida a 12/11/2026', 'vence a 12/12/2026'])
        ->assertSee('Artigo DEV-H')
        ->assertSee('2 h')
        ->assertSee('IVA · isento (M10)')
        ->assertSee('Em sincronia')
        ->assertSee('12/11/2026 às 10:42')
        ->assertSeeInOrder(['Ligado a', '2.ª prestação', 'Site institucional', 'Venda DEMO-03'])
        ->assertSeeInOrder(['Soma das linhas', '240,00', 'Descontos', '—', 'Total do documento', '240,00', 'Retenção de IRS', '—', 'A receber', '240,00'])
        ->assertDontSee('Retenção de IRS ·')
        ->assertActionVisible('recordPayment');
});

it('shows what the client withholds, at the rate of the document', function () {
    $document = ($this->issued)(['withholding_basis_points' => 1150], [['description' => 'Consultoria', 'quantity' => 1000, 'unit_price' => 40000]]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSeeInOrder(['Total do documento', '400,00', 'Retenção de IRS · 11,5%', '-46,00', 'A receber', '354,00'])
        ->assertSee('Retenção na fonte de IRS à taxa de 11,5%, artigo 101.º do CIRS.');
});

it('says how a fatura-recibo was paid and that it is settled', function () {
    $document = ($this->issued)(['type' => FiscalDocumentType::InvoiceReceipt, 'number' => 'FR 2026/14', 'payment' => ['method' => 'mbway', 'paid_on' => '2026-11-12']]);
    $payment = Payment::factory()->for(($this->receivableOf)(15000))->create(['amount' => 15000, 'paid_on' => '2026-11-12', 'method' => 'mbway']);
    $document->links()->create(['payment_id' => $payment->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSee('Fatura-recibo FR 2026/14')
        ->assertSeeInOrder(['Emitida', 'Recebida', 'emitida e paga a 12/11/2026'])
        ->assertSee('Paga ao emitir')
        ->assertSeeInOrder(['Pagamento', 'Como foi pago', 'MB Way', 'Recebido em', '12/11/2026'])
        ->assertSeeInOrder(['Ligado a', 'Recebimento de 12/11/2026', 'registado ao emitir'])
        ->assertActionHidden('recordPayment');
});

it('lists the invoice a receipt pays instead of lines', function () {
    $receivable = ($this->receivableOf)(85000, ['description' => 'Manutenção do site · outubro']);
    $invoice = ($this->issued)(['number' => 'FT 2026/9', 'document_date' => '2026-10-01'], [['description' => 'Manutenção do site', 'quantity' => 1000, 'unit_price' => 85000]]);
    $invoice->links()->create(['receivable_id' => $receivable->id]);
    $payment = Payment::factory()->for($receivable)->create(['amount' => 65000, 'paid_on' => '2026-10-15']);
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt, 'number' => 'RC 2026/5', 'document_date' => '2026-10-15', 'party_name' => 'Estúdio Atlântico, Lda.',
        'net_amount' => 65000, 'vat_amount' => 0, 'total_amount' => 65000, 'payment' => ['method' => 'transfer', 'paid_on' => '2026-10-15'],
    ]);
    $receipt->links()->create(['related_fiscal_document_id' => $invoice->id, 'payment_id' => $payment->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $receipt->getRouteKey()])
        ->assertSee('Recibo RC 2026/5')
        ->assertSeeInOrder(['Emitido', 'emitido a 15/10/2026', 'paga parte da', 'FT 2026/9'])
        ->assertSeeInOrder(['Faturas pagas neste recibo', 'FT 2026/9', 'Manutenção do site · outubro', '01/10/2026', '850,00', '650,00', '200,00', 'Total recebido', '650,00'])
        ->assertSee('Transferência')
        ->assertSee('Um recibo não tem linhas')
        ->assertDontSee('Qtd.')
        ->assertDontSee('Valor sem IVA');
});

it('shows on a credit note the invoice it corrects and what stays invoiced', function () {
    $receivable = ($this->receivableOf)(85000);
    $invoice = ($this->issued)(['number' => 'FT 2026/9', 'document_date' => '2026-10-01'], [['description' => 'Manutenção do site', 'quantity' => 1000, 'unit_price' => 85000]]);
    $invoice->links()->create(['receivable_id' => $receivable->id]);
    Payment::factory()->for($receivable)->create(['amount' => 65000, 'paid_on' => '2026-10-15']);
    $debitNote = FiscalDocument::factory()->create(['type' => FiscalDocumentType::DebitNote, 'number' => 'ND 2026/1', 'net_amount' => 3500, 'vat_amount' => 0, 'total_amount' => 3500]);
    $debitNote->links()->create(['related_fiscal_document_id' => $invoice->id]);
    $note = ($this->issued)(
        ['type' => FiscalDocumentType::CreditNote, 'number' => 'NC 2026/2', 'document_date' => '2026-10-20', 'reason' => 'Desconto acordado depois de emitida', 'receivable_effect' => 'apply'],
        [['description' => 'Manutenção do site', 'quantity' => 1000, 'unit_price' => 5000]],
    );
    $note->links()->create(['related_fiscal_document_id' => $invoice->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $note->getRouteKey()])
        ->assertSee('Nota de crédito NC 2026/2')
        ->assertSeeInOrder(['emitida a 20/10/2026', 'corrige a', 'FT 2026/9'])
        ->assertSeeInOrder(['Corrige', 'FT 2026/9 de 01/10/2026', 'Motivo', 'Desconto acordado depois de emitida', 'Efeito', 'Abate ao que falta receber'])
        ->assertSeeInOrder(['Total creditado', '-50,00'])
        ->assertSeeInOrder(['Fatura corrigida', 'Fatura FT 2026/9', '850,00', 'Esta nota', '-50,00', 'Outras notas', '35,00', 'Fica faturado', '835,00', 'Já recebido', '650,00', 'Por receber', '200,00'])
        ->assertSee('Referente à fatura FT 2026/9 de 01/10/2026.')
        ->assertDontSee('Documentos relacionados');
});

it('lists on an invoice the notes and receipts issued against it and offers to write another', function () {
    $invoice = ($this->issued)(['number' => 'FT 2026/9']);
    $note = FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'number' => 'NC 2026/2', 'net_amount' => 5000, 'vat_amount' => 0, 'total_amount' => 5000]);
    $note->links()->create(['related_fiscal_document_id' => $invoice->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $invoice->getRouteKey()])
        ->assertSeeInOrder(['Documentos relacionados', 'NC 2026/2', 'Nota de crédito · emitida', '-50,00'])
        ->assertSeeHtml(FiscalDocumentResource::getUrl('view', ['record' => $note]))
        ->assertSeeHtml(e(FiscalDocumentResource::getUrl('compose', ['type' => 'credit_note', 'original' => $invoice->id])))
        ->assertSeeHtml(e(FiscalDocumentResource::getUrl('compose', ['type' => 'receipt', 'original' => $invoice->id])));
});

it('says that a cancelled document no longer counts, when it was cancelled and why', function () {
    $document = ($this->issued)(['status' => FiscalDocumentStatus::Voided, 'number' => 'FT 2026/12', 'document_date' => '2026-11-02', 'due_date' => '2026-12-02']);
    $document->links()->create(['receivable_id' => ($this->receivableOf)(15000)->id]);
    $this->travelTo(Carbon::parse('2026-11-03 11:15'));
    InvoicingRecords::log('cancelled', IntegrationOperationStatus::Done, 'FT 2026/12 anulada', $document, 'Emitida com a data errada');

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSee('Este documento foi anulado.')
        ->assertSeeInOrder(['Anulada', 'emitida a 02/11/2026', 'anulada a 03/11/2026'])
        ->assertSeeInOrder(['Emissão', 'Anulação', '03/11/2026 às 11:15', 'Motivo', 'Emitida com a data errada'])
        ->assertDontSee('vence a 02/12/2026')
        ->assertActionHidden('cancel')
        ->assertActionHidden('recordPayment');
});

it('says that a record made by hand has no lines and offers to edit it', function () {
    $document = FiscalDocument::factory()->create(['number' => 'FT 2026/8', 'document_date' => '2026-09-14', 'party_name' => 'Oficina Norte, Lda.', 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSeeInOrder(['Fatura FT 2026/8', 'Emitida', 'emitida a 14/09/2026', 'registada à mão'])
        ->assertSee('Registo sem linhas: o documento foi emitido noutro programa')
        ->assertSeeInOrder(['Total do documento', '180,00'])
        ->assertSeeInOrder(['Registo', 'Origem', 'Registo manual', 'Por anexar', 'A Contiu não emitiu este documento'])
        ->assertDontSee('Em sincronia')
        ->assertActionVisible('editRecord')
        ->assertActionHidden('cancel');
});

it('shows a supplier\'s invoice as received and still to pay, with its expense', function () {
    $expense = Expense::factory()->unpaid()->for(Supplier::factory()->create(['name' => 'Loja Demo, S.A.']))->create(['description' => 'Internet · novembro', 'net_amount' => 5000]);
    $document = FiscalDocument::factory()->create([
        'direction' => FiscalDocumentDirection::Received, 'number' => 'FT A/2291', 'origin' => 'e-Fatura', 'document_date' => '2026-11-10',
        'party_name' => 'Loja Demo, S.A.', 'party_tax_id' => '509999966', 'net_amount' => 5000, 'vat_amount' => 1150, 'total_amount' => 6150,
    ]);
    $document->links()->create(['expense_id' => $expense->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSeeInOrder(['Fatura recebida FT A/2291', 'Registada', 'Por pagar', 'Loja Demo, S.A.', 'recebida a 10/11/2026'])
        ->assertSee('NIF 509999966')
        ->assertSee('O detalhe está no PDF do fornecedor.')
        ->assertSeeInOrder(['Origem', 'e-Fatura', 'Emitida por', 'Loja Demo, S.A.'])
        ->assertSeeInOrder(['Ligado a', 'Internet · novembro', 'Por pagar', 'Fornecedor'])
        ->assertActionVisible('payExpense')
        ->assertActionDoesNotExist('recordPayment');
});

it('marks the expense of a received invoice as paid', function () {
    $expense = Expense::factory()->unpaid()->create();
    $document = FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received, 'number' => 'FT A/2291']);
    $document->links()->create(['expense_id' => $expense->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->callAction('payExpense')
        ->assertNotified('Despesa marcada como paga');

    expect($expense->refresh())
        ->is_paid->toBeTrue()
        ->paid_on->toDateString()->toBe('2026-11-12');
});

it('does not offer to pay an expense that is already paid', function () {
    $document = FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received, 'number' => 'FT A/2291']);
    $document->links()->create(['expense_id' => Expense::factory()->create()->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSee('Paga')
        ->assertActionHidden('payExpense');
});

it('keeps the payment of an expense disabled while the account is read-only', function () {
    $expense = Expense::factory()->unpaid()->create();
    $document = FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received, 'number' => 'FT A/2291']);
    $document->links()->create(['expense_id' => $expense->id]);
    $this->account->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $this->account->unsetRelation('subscriptions');

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertActionDisabled('payExpense');

    expect($expense->refresh()->is_paid)->toBeFalse();
});

it('offers to turn a quote into an invoice and a pro forma into a fatura-recibo', function (FiscalDocumentType $type, string $number, string $target, string $label) {
    $document = ($this->issued)(['type' => $type, 'number' => $number, 'due_date' => '2026-11-30']);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertActionHasLabel('convert', $label)
        ->assertActionHasUrl('convert', FiscalDocumentResource::getUrl('compose', ['type' => $target, 'from' => $document->id]))
        ->assertSee('Este documento não serve de fatura.')
        ->assertActionDoesNotExist('recordPayment');
})->with([
    'a quote' => [FiscalDocumentType::Quote, 'OR 2026/3', 'invoice', 'Converter em fatura'],
    'a pro forma' => [FiscalDocumentType::ProForma, 'PF 2026/1', 'invoice_receipt', 'Converter em fatura-recibo'],
]);

it('says of a quote until when it stands and that it is not an invoice', function () {
    $document = ($this->issued)(['type' => FiscalDocumentType::Quote, 'number' => 'OR 2026/3', 'document_date' => '2026-11-04', 'due_date' => '2026-11-30', 'accepted_at' => '2026-11-09 09:00']);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSeeInOrder(['Orçamento OR 2026/3', 'Aceite', 'emitido a 04/11/2026', 'válido até 30/11/2026'])
        ->assertSeeInOrder(['Válido até', '30/11/2026', 'Total do orçamento', '150,00'])
        ->assertSee('Um orçamento não é uma fatura: não conta como faturado nem entra nos impostos.')
        ->assertDontSee('Por receber');
});

it('shows where the goods of a transport guide go and when they leave', function () {
    $document = ($this->issued)(
        [
            'type' => FiscalDocumentType::TransportGuide, 'number' => 'GT 2026/1',
            'transport' => [
                'load' => ['address' => 'Rua da Demo 12', 'postal_code' => '1000-001', 'city' => 'Lisboa'],
                'unload' => ['address' => 'Rua da Oficina 3', 'postal_code' => '4000-100', 'city' => 'Porto'],
                'starts_at' => '2026-11-12 15:00:00', 'plate' => 'AA-00-AA',
            ],
        ],
        [['description' => 'Brochuras A5 impressas', 'quantity' => 200000, 'unit_price' => 120]],
    );

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSeeInOrder(['Guia de transporte GT 2026/1', 'Emitida', 'os bens saem às 15:00'])
        ->assertSeeInOrder(['Início do transporte', '12/11/2026 às 15:00', 'Matrícula', 'AA-00-AA'])
        ->assertSeeInOrder(['Transporte', 'Local de carga', 'Rua da Demo 12, 1000-001 Lisboa', 'Local de descarga', 'Rua da Oficina 3, 4000-100 Porto'])
        ->assertSeeInOrder(['Bens transportados', 'Brochuras A5 impressas', '200 un', 'Valor dos bens', '240,00'])
        ->assertDontSee('Valor sem IVA')
        ->assertActionDoesNotExist('convert');
});

it('escapes what the user typed in the title and in the line under it', function () {
    $document = ($this->issued)(['number' => 'FT <b>1</b>', 'party_name' => '<script>alert(1)</script>']);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
        ->assertSee('FT <b>1</b>')
        ->assertDontSeeHtml('<b>1</b>')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

it('loads the page without a query per line or per related document, and without asking the provider', function () {
    Http::fake();
    $queries = function (FiscalDocument $document): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $make = function (int $lines, int $notes, string $remoteId): FiscalDocument {
        $article = Article::factory()->create();
        $invoice = ($this->atProvider)(draftDocument(
            ['status' => FiscalDocumentStatus::Active, 'number' => "FT 2026/{$remoteId}", 'issued_at' => now()],
            array_fill(0, $lines, ['description' => 'Hora de desenvolvimento', 'quantity' => 1000, 'unit_price' => 4500, 'article_id' => $article->id]),
        ), $remoteId);
        $invoice->links()->create(['receivable_id' => ($this->receivableOf)(4500 * $lines)->id]);

        foreach (range(1, $notes) as $index) {
            FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'number' => "NC {$remoteId}/{$index}"])
                ->links()->create(['related_fiscal_document_id' => $invoice->id]);
        }

        return $invoice;
    };
    $small = $make(1, 1, '9001');
    $large = $make(8, 6, '9002');

    // The first render also loads what is cached for the rest of the test (permissions, settings).
    $queries($small);

    expect($queries($large))->toBe($queries($small));
    Http::assertNothingSent();
})->group('performance');
