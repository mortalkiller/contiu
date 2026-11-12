<?php

use App\Actions\SendFiscalDocumentByEmail;
use App\Enums\IntegrationRecordState;
use App\Filament\Resources\FiscalDocuments\Pages\ComposeFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Mail\FiscalDocumentEmail;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPdf;
use App\Models\IntegrationOperation;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicing', 'ui');

/*
 * Sending an issued document to the client: the PDF attached, the address the user confirmed,
 * and a note of it in the client's messages and the document's history.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->connection = invoicingConnection();
    $this->disk = Storage::fake('local');
    Mail::fake();
    $this->client = Client::factory()->create(['name' => 'Estúdio Atlântico, Lda.', 'email' => 'contas@atlantico.pt']);

    /** An issued invoice of the client, at the provider as document 9001, with its PDF kept. */
    $this->issued = function (array $attributes = [], bool $withPdf = true): FiscalDocument {
        $document = FiscalDocument::factory()->create(['number' => 'FT 2026/15', 'client_id' => $this->client->id, 'party_name' => $this->client->name, ...$attributes]);
        InvoicingRecords::store($document, new RemoteDocument('invoices', '9001', $document->type, RemoteDocumentState::Issued, CarbonImmutable::parse('2026-11-12'), $document->net_amount, $document->vat_amount, $document->total_amount, $document->number), IntegrationRecordState::InSync);

        if ($withPdf) {
            /** @var FiscalDocumentPdf $pdf */
            $pdf = $document->pdfVersions()->create(['source' => 'provider', 'storage_key' => (string) Str::uuid(), 'sha256' => hash('sha256', fiscalPdfBytes()), 'size' => strlen(fiscalPdfBytes())]);
            $this->disk->put($pdf->path(), fiscalPdfBytes());
        }

        return $document;
    };
});

it('sends the document with its PDF to the address in the modal, and notes it', function () {
    $document = ($this->issued)();
    $path = $document->currentPdf->path();

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
        ->mountAction('sendByEmail')
        ->assertSchemaStateSet(['to' => 'contas@atlantico.pt'])
        ->fillForm(['to' => 'faturas@atlantico.pt', 'message' => "Olá,\nSegue a fatura."])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(__('Sent to :email', ['email' => 'faturas@atlantico.pt']));

    Mail::assertQueuedCount(1);
    Mail::assertQueued(FiscalDocumentEmail::class, fn (FiscalDocumentEmail $mail): bool => $mail->hasTo('faturas@atlantico.pt')
        && ! $mail->hasTo('contas@atlantico.pt')
        && $mail->hasAttachment(Attachment::fromStorageDisk('local', $path)->as('FT-2026-15.pdf')->withMime('application/pdf'))
        && $mail->body === "Olá,\nSegue a fatura.");

    expect(ClientMessage::query()->sole()->only(['client_id', 'type', 'to', 'body']))->toBe([
        'client_id' => $this->client->id,
        'type' => ClientMessage::FISCAL_DOCUMENT,
        'to' => 'faturas@atlantico.pt',
        'body' => "Olá,\nSegue a fatura.",
    ])
        ->and(IntegrationOperation::query()->where('kind', 'emailed')->sole()->summary)->toContain('faturas@atlantico.pt');
});

it('writes the email in the client\'s language, with the number, the total and what is left to pay', function () {
    $document = ($this->issued)(['total_amount' => 12300, 'withholding_amount' => 2300]);

    $mail = new FiscalDocumentEmail($document, $document->currentPdf->path());

    expect($mail->locale)->toBe('pt_PT')
        ->and($mail->envelope()->subject)->toContain('FT 2026/15');
    $mail->assertSeeInHtml('FT 2026/15')
        ->assertSeeInHtml('123,00')
        ->assertSeeInHtml('100,00')
        ->assertSeeInHtml($this->account->name);
});

it('sends from a row of the list too', function () {
    $document = ($this->issued)();

    Livewire::test(ListFiscalDocuments::class)
        ->loadTable()
        ->callAction(TestAction::make('sendByEmail')->table($document), ['to' => 'contas@atlantico.pt'])
        ->assertHasNoFormErrors();

    Mail::assertQueued(FiscalDocumentEmail::class, fn (FiscalDocumentEmail $mail): bool => $mail->hasTo('contas@atlantico.pt'));
});

it('requires a valid address', function (?string $to, string $rule) {
    $document = ($this->issued)();

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
        ->callAction('sendByEmail', ['to' => $to])
        ->assertHasFormErrors(['to' => $rule]);

    Mail::assertNothingQueued();
})->with([
    'none' => [null, 'required'],
    'not an address' => ['contas-atlantico', 'email'],
]);

it('fetches the PDF from the provider when it is not kept yet, and does not send while it is being prepared', function () {
    $document = ($this->issued)(withPdf: false);
    $ready = false;
    fakeInvoiceXpress([
        'GET /api/pdf/9001.json' => function () use (&$ready) {
            return $ready
                ? Http::response(['output' => ['pdfUrl' => 'https://contiu-test.app.invoicexpress.com/downloads/FT-2026-15.pdf']])
                : Http::response('', 202);
        },
        'GET /downloads/FT-2026-15.pdf' => fn () => Http::response(fiscalPdfBytes(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
        ->callAction('sendByEmail', ['to' => 'contas@atlantico.pt'])
        ->assertNotified(__('The PDF is still being prepared. Try sending it again in a moment.'));

    Mail::assertNothingQueued();
    $ready = true;

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
        ->callAction('sendByEmail', ['to' => 'contas@atlantico.pt'])
        ->assertNotified(__('Sent to :email', ['email' => 'contas@atlantico.pt']));

    $path = $document->currentPdf()->sole()->path();
    Mail::assertQueued(FiscalDocumentEmail::class, fn (FiscalDocumentEmail $mail): bool => $mail->hasAttachment(Attachment::fromStorageDisk('local', $path)->as('FT-2026-15.pdf')->withMime('application/pdf')));
});

it('never sends a draft', function () {
    $draft = draftDocument(['client_id' => $this->client->id]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $draft->id])
        ->assertActionHidden('sendByEmail');

    expect(fn () => app(SendFiscalDocumentByEmail::class)->handle($draft, 'contas@atlantico.pt'))->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
});

describe('issuing', function () {
    beforeEach(function () {
        $this->undoRepeaterFake = Repeater::fake();
        $issued = false;
        fakeInvoiceXpress([
            'POST /clients.json' => ['client' => ['id' => 77]],
            'POST /invoices.json' => invoiceXpressDocument(),
            'GET /invoices/9001.json' => function () use (&$issued) {
                return Http::response(invoiceXpressDocument($issued ? ['status' => 'final', 'inverted_sequence_number' => '2026/15', 'atcud' => 'JFX4K2Q9-15'] : []));
            },
            'PUT /invoices/9001/change-state.json' => function () use (&$issued) {
                $issued = true;

                return Http::response('', 200);
            },
            'GET /api/pdf/9001.json' => ['output' => ['pdfUrl' => 'https://contiu-test.app.invoicexpress.com/downloads/FT-2026-15.pdf']],
            'GET /downloads/FT-2026-15.pdf' => fn () => Http::response(fiscalPdfBytes(), 200, ['Content-Type' => 'application/pdf']),
        ]);
        $this->form = ['client_id' => $this->client->id, 'vat_exemption_reason' => 'M10', 'lines' => [
            ['description' => 'Desenho do site', 'quantity' => '1', 'unit' => 'un', 'unit_price' => 150, 'discount' => '0', 'vat_basis_points' => '0'],
        ]];
    });

    afterEach(fn () => ($this->undoRepeaterFake)());

    it('sends the issued document to the client only when asked in the confirmation', function (bool $send) {
        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm($this->form)
            ->mountAction(TestAction::make('issue')->schemaComponent('form-actions', 'content'))
            ->assertSchemaStateSet(['send_by_email' => false])
            ->fillForm(['send_by_email' => $send])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect(FiscalDocument::query()->sole()->number)->toBe('FT 2026/15');

        if ($send) {
            Mail::assertQueued(FiscalDocumentEmail::class, fn (FiscalDocumentEmail $mail): bool => $mail->hasTo('contas@atlantico.pt')
                && $mail->hasAttachment(Attachment::fromStorageDisk('local', FiscalDocument::query()->sole()->currentPdf->path())->as('FT-2026-15.pdf')->withMime('application/pdf')));
        } else {
            Mail::assertNothingQueued();
        }
    })->with(['asked' => true, 'not asked' => false]);

    it('does not offer to send when the client has no email', function () {
        $this->client->update(['email' => null]);

        Livewire::test(ComposeFiscalDocument::class, ['type' => 'invoice'])
            ->fillForm($this->form)
            ->mountAction(TestAction::make('issue')->schemaComponent('form-actions', 'content'))
            ->tap(fn ($screen) => expect($screen->get('mountedActions.0.data') ?? [])->not->toHaveKey('send_by_email'))
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect(FiscalDocument::query()->sole()->number)->toBe('FT 2026/15');
        Mail::assertNothingQueued();
    });
});
