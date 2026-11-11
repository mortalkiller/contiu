<?php

use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationRecordState;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Pages\ComposeFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentDraftForm;
use App\Models\Account;
use App\Models\Article;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

pest()->group('ui', 'invoicing', 'fiscal');

/*
 * The screens of the fiscal documents: the list, a document's own page and the form a document is
 * written in. What they do is in the actions' own tests; here it is what the screens offer, to
 * whom, and that they call those actions with what the user typed.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->connection = invoicingConnection();
    $this->client = Client::factory()->create(['name' => 'Estúdio Atlântico, Lda.', 'vat_number' => '508025338']);
    $this->undoRepeaterFake = Repeater::fake();

    $this->atProvider = function (FiscalDocument $document, string $remoteId = '9001', RemoteDocumentState $state = RemoteDocumentState::Draft): FiscalDocument {
        InvoicingRecords::store($document, new RemoteDocument('invoices', $remoteId, $document->type, $state, CarbonImmutable::parse('2026-11-12'), $document->net_amount, $document->vat_amount, $document->total_amount, $document->number), IntegrationRecordState::InSync);

        return $document;
    };
    $this->lines = [
        ['description' => 'Desenho do site institucional', 'quantity' => '1', 'unit' => 'un', 'unit_price' => 150, 'discount' => '0', 'vat_basis_points' => '0'],
        ['description' => 'Hora de desenvolvimento', 'quantity' => '2', 'unit' => 'h', 'unit_price' => 45, 'discount' => '0', 'vat_basis_points' => '0'],
        ['description' => 'Alojamento anual', 'quantity' => '1', 'unit' => 'un', 'unit_price' => 60, 'discount' => '10', 'vat_basis_points' => '0'],
    ];
});

afterEach(fn () => ($this->undoRepeaterFake)());

describe('the list', function () {
    it('tells drafts, issued documents and who issued them apart', function () {
        ($this->atProvider)(draftDocument());
        ($this->atProvider)(FiscalDocument::factory()->create(['number' => 'FT 2026/13', 'party_name' => 'Oficina Norte']), '8813', RemoteDocumentState::Issued);
        FiscalDocument::factory()->create(['number' => 'FT 2026/8', 'origin' => 'Registo manual']);

        Livewire::test(ListFiscalDocuments::class)->loadTable()
            ->assertSee('Fatura por emitir')
            ->assertSee('FT 2026/13')
            ->assertSee('InvoiceXpress')
            ->assertSee('Rascunho por emitir')
            ->assertSee('Em sincronia')
            ->assertSee('Registo manual')
            ->assertCountTableRecords(3);

        Livewire::test(ListFiscalDocuments::class, ['activeTab' => 'drafts'])->loadTable()
            ->assertCountTableRecords(1)
            ->assertSee('Fatura por emitir');
    });

    it('offers each kind the provider issues, and transport guides only to who sells goods', function () {
        Livewire::test(ListFiscalDocuments::class)
            ->assertActionVisible('new_invoice')
            ->assertActionVisible('new_quote')
            ->assertActionDoesNotExist('new_transport_guide')
            ->assertActionDoesNotExist('new_credit_note');

        app(FinanceSettings::class)->set(['sells_goods' => true]);

        Livewire::test(ListFiscalDocuments::class)->assertActionVisible('new_transport_guide');
    });

    it('only offers to record a document while no provider is connected', function () {
        IntegrationConnection::query()->delete();
        Invoicing::forget();

        Livewire::test(ListFiscalDocuments::class)
            ->assertActionVisible('record')
            ->assertActionDoesNotExist('new_invoice')
            ->assertActionHidden('synchronisation');
    });

    it('never lists another account\'s documents', function () {
        FiscalDocument::factory()->create(['number' => 'FT 2026/13']);
        CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create(['number' => 'FT 9999/1']));

        Livewire::test(ListFiscalDocuments::class)->loadTable()->assertSee('FT 2026/13')->assertDontSee('FT 9999/1');
    });

    it('loads what the rows show without a query per document', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListFiscalDocuments::class)->loadTable();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $make = function (): void {
            $receivable = Receivable::factory()->for(Project::factory()->for($this->client), 'billable')->create();
            $document = draftDocument();
            $document->links()->create(['receivable_id' => $receivable->id]);
        };

        $make();
        // The first render also loads what is cached for the rest of the test (permissions, settings).
        $queries();
        $few = $queries();
        $make();
        $make();
        $make();

        expect($queries())->toBe($few);
    })->group('performance');
});

describe('a document\'s page', function () {
    it('shows the document as it reads, with its lines and what they add up to', function () {
        $document = ($this->atProvider)(draftDocument(
            ['status' => FiscalDocumentStatus::Active, 'number' => 'FT 2026/15', 'withholding_basis_points' => 2300, 'atcud' => 'JFX4K2Q9-15', 'issued_at' => now()],
            [
                ['description' => 'Desenho do site institucional', 'quantity' => 1000, 'unit_price' => 15000],
                ['description' => 'Hora de desenvolvimento', 'quantity' => 2000, 'unit_price' => 4500, 'unit' => 'h'],
                ['description' => 'Alojamento anual', 'quantity' => 1000, 'unit_price' => 6000, 'discount_basis_points' => 1000],
            ],
        ), '9001', RemoteDocumentState::Issued);

        Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
            ->assertSee('FT 2026/15')
            ->assertSee('Estúdio Atlântico, Lda.')
            ->assertSee('Hora de desenvolvimento')
            ->assertSee('JFX4K2Q9-15')
            ->assertSee('294,00')
            ->assertSee('67,62')
            ->assertSee('226,38')
            ->assertActionVisible('cancel')
            ->assertActionHidden('discard');
    });

    it('cancels the document from its page', function () {
        $cancelled = false;
        fakeInvoiceXpress([
            'GET /invoices/9001.json' => function () use (&$cancelled) {
                return Http::response(invoiceXpressDocument(['status' => $cancelled ? 'canceled' : 'final', 'inverted_sequence_number' => '2026/15']));
            },
            'PUT /invoices/9001/change-state.json' => function () use (&$cancelled) {
                $cancelled = true;

                return Http::response('', 200);
            },
        ]);
        $document = ($this->atProvider)(FiscalDocument::factory()->create(['number' => 'FT 2026/15', 'net_amount' => 15000, 'vat_amount' => 0, 'total_amount' => 15000]), '9001', RemoteDocumentState::Issued);

        Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('cancel', ['reason' => 'Emitida por engano'])
            ->assertHasNoActionErrors();

        expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Voided);
    });

    it('is closed to an accountant, who only has the review screens', function () {
        $document = ($this->atProvider)(FiscalDocument::factory()->create(['number' => 'FT 2026/15']), '9001', RemoteDocumentState::Issued);
        $accountant = User::factory()->create(['email_verified_at' => now()]);
        $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
        $this->actingAs($accountant);

        Livewire::test(ViewFiscalDocument::class, ['record' => $document->getRouteKey()])->assertForbidden();
    });

    it('is not found for a document of another account', function () {
        $foreign = CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create());

        Livewire::test(ViewFiscalDocument::class, ['record' => $foreign->getRouteKey()])->assertNotFound();
    });
});

describe('writing a document', function () {
    beforeEach(function () {
        $this->provider = fn (array $routes = []) => fakeInvoiceXpress([
            'POST /clients.json' => ['client' => ['id' => 77]],
            'POST /invoices.json' => invoiceXpressDocument(['before_taxes' => 294.0, 'total' => 294.0]),
            ...$routes,
        ]);
    });

    it('saves what was typed as a draft, with its lines, and creates it at the provider', function () {
        ($this->provider)();

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->assertSee('Nova fatura')
            ->fillForm(['client_id' => $this->client->id, 'vat_exemption_reason' => 'M10', 'withholding_basis_points' => '2300', 'lines' => $this->lines])
            ->call('saveDraft')
            ->assertHasNoFormErrors();

        $draft = FiscalDocument::query()->sole();

        expect($draft->status)->toBe(FiscalDocumentStatus::Draft)
            ->and($draft->client_id)->toBe($this->client->id)
            ->and([$draft->net_amount, $draft->discount_amount, $draft->withholding_amount])->toBe([29400, 600, 6762])
            ->and($draft->lines->pluck('quantity')->all())->toBe([1000, 2000, 1000])
            ->and($draft->lines[2]->discount_basis_points)->toBe(1000)
            ->and(InvoicingRecords::remoteId($draft))->toBe('9001');
    });

    it('issues from the form what is on screen', function () {
        $issued = false;
        ($this->provider)([
            'GET /invoices/9001.json' => function () use (&$issued) {
                return Http::response(invoiceXpressDocument(['before_taxes' => 294.0, 'total' => 294.0, ...($issued ? ['status' => 'final', 'inverted_sequence_number' => '2026/15', 'atcud' => 'JFX4K2Q9-15'] : [])]));
            },
            'PUT /invoices/9001/change-state.json' => function () use (&$issued) {
                $issued = true;

                return Http::response('', 200);
            },
        ]);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm(['client_id' => $this->client->id, 'vat_exemption_reason' => 'M10', 'lines' => $this->lines])
            ->call('issue')
            ->assertRedirect(FiscalDocumentResource::getUrl('view', ['record' => FiscalDocument::query()->sole()]));

        expect(FiscalDocument::query()->sole()->number)->toBe('FT 2026/15');
    });

    it('hands the browser the articles a line can be filled from, and keeps a price changed for that document', function () {
        ($this->provider)();
        $article = Article::factory()->create(['name' => 'Hora de desenvolvimento', 'unit' => 'h', 'unit_price' => 4500, 'vat_basis_points' => null]);
        Article::factory()->create(['name' => 'Fora de uso', 'is_active' => false]);

        expect(FiscalDocumentDraftForm::articleCatalogue())->toBe([
            ['id' => $article->id, 'name' => 'Hora de desenvolvimento', 'unit' => 'h', 'price' => '45.00', 'vat' => null],
        ]);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm(['client_id' => $this->client->id, 'vat_exemption_reason' => 'M10', 'lines' => [
                ['article_id' => $article->id, 'description' => 'Hora de desenvolvimento', 'quantity' => '2', 'unit' => 'h', 'unit_price' => 40, 'discount' => '0', 'vat_basis_points' => '0'],
            ]])
            ->call('saveDraft')
            ->assertHasNoFormErrors();

        $line = FiscalDocument::query()->sole()->lines->sole();

        expect($line->article_id)->toBe($article->id)
            ->and($line->unit_price)->toBe(4000);
    });

    it('never reloads the form for what the browser can work out', function () {
        app(FinanceSettings::class)->set(['sells_goods' => true]);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'simplified_invoice'])
            ->assertFormFieldExists('consumer', fn (Field $field): bool => ! $field->isLive())
            ->assertFormFieldExists('withholding_basis_points', fn (Field $field): bool => ! $field->isLive())
            ->assertFormFieldExists('with_transport', fn (Field $field): bool => ! $field->isLive())
            ->assertFormFieldExists('lines', function (Repeater $field): bool {
                $fields = collect($field->getChildSchema()->getFlatFields(withHidden: true));

                return $fields->has('unit_price') && $fields->every(fn (Field $child): bool => ! $child->isLive());
            });
    });

    it('starts an invoice from the receivable it charges', function () {
        $receivable = Receivable::factory()->for(Project::factory()->for($this->client), 'billable')->create(['amount' => 29400, 'description' => '2.ª prestação']);

        Livewire::withQueryParams(['receivable' => $receivable->id])
            ->test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->assertSet('data.client_id', $this->client->id)
            ->assertSet('data.receivable_id', $receivable->id)
            ->assertSet('data.lines.0.unit_price', 294.0)
            ->assertSee('294,00');
    });

    it('puts the domain\'s refusal on the field it is about', function () {
        ($this->provider)();

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm(['client_id' => $this->client->id, 'vat_exemption_reason' => 'M10', 'withholding_basis_points' => '2300', 'lines' => [$this->lines[2]]])
            ->call('saveDraft')
            ->assertHasErrors(['data.withholding_basis_points']);

        expect(FiscalDocument::query()->count())->toBe(0);
    });

    it('keeps the draft when the provider refuses it and sends the user back to it', function () {
        ($this->provider)(['POST /invoices.json' => fn () => Http::response(['errors' => [['error' => 'O cliente não tem NIF']]], 422)]);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm(['client_id' => $this->client->id, 'vat_exemption_reason' => 'M10', 'lines' => $this->lines])
            ->call('saveDraft')
            ->assertRedirect(FiscalDocumentResource::getUrl('draft', ['record' => FiscalDocument::query()->sole()]))
            ->assertNotified('Guardado aqui, mas não emitido');
    });

    it('opens a saved draft again with what it had', function () {
        $draft = ($this->atProvider)(draftDocument(['client_reference' => 'EA-2026-118'], [['description' => 'Desenho do site', 'quantity' => 1500, 'unit_price' => 15000]]));

        Livewire::test(ComposeFiscalDocument::class, ['record' => $draft->getRouteKey()])
            ->assertSet('data.client_reference', 'EA-2026-118')
            ->assertSet('data.lines.0.quantity', '1.5')
            ->assertSet('data.lines.0.unit_price', 150.0);
    });

    it('writes a credit note on top of an issued invoice, starting from its lines', function () {
        $invoice = ($this->atProvider)(draftDocument(['status' => FiscalDocumentStatus::Active, 'number' => 'FT 2026/15'], [
            ['description' => 'Alojamento anual', 'quantity' => 1000, 'unit_price' => 5400],
        ]), '9001', RemoteDocumentState::Issued);

        Livewire::withQueryParams(['original' => $invoice->id])
            ->test(ComposeFiscalDocument::class, ['type' => 'credit_note'])
            ->assertSee('Nota de crédito sobre a FT 2026/15')
            ->assertSet('data.lines.0.description', 'Alojamento anual')
            ->assertSet('data.receivable_effect', 'apply');
    });

    it('is not found for a note without an issued invoice of this account behind it', function (Closure $original) {
        Livewire::withQueryParams(['original' => $original()])
            ->test(ComposeFiscalDocument::class, ['type' => 'credit_note'])
            ->assertNotFound();
    })->with([
        'no invoice' => [fn () => 0],
        'a draft' => [fn () => draftDocument()->id],
        'another account\'s invoice' => [fn () => CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create())->id],
    ]);

    it('has no transport guide for an account that does not sell goods', function () {
        Livewire::test(ComposeFiscalDocument::class, ['type' => 'transport_guide'])->assertNotFound();

        app(FinanceSettings::class)->set(['sells_goods' => true]);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'transport_guide'])->assertSee('Nova guia de transporte');
    });

    it('asks for how it was paid on a fatura-recibo and not on an invoice', function () {
        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice_receipt'])->assertFormFieldExists('payment.method');
        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])->assertFormFieldDoesNotExist('payment.method');
    });

    it('is forbidden to someone who can only read', function () {
        $accountant = User::factory()->create(['email_verified_at' => now()]);
        $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
        $this->actingAs($accountant);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])->assertForbidden();
    });

    it('sends whoever has no provider to the manual record instead', function () {
        IntegrationConnection::query()->delete();
        Invoicing::forget();

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])->assertRedirect(FiscalDocumentResource::getUrl('create'));
    });

    it('never sends the provider anything while the form is only being filled in', function () {
        ($this->provider)();

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm(['client_id' => $this->client->id, 'lines' => $this->lines]);

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    });
});
