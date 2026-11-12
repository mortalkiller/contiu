<?php

use App\Enums\IntegrationRecordState;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Resources\FiscalDocuments\Pages\ViewFiscalDocument;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

pest()->group('ui', 'fiscal');

function fiscalPdfUrl(FiscalDocument $document, Account $account): string
{
    return FiscalDocumentResource::getUrl(tenant: $account).'/'.$document->getKey().'/pdf';
}

/** Connects the account to the provider and ties the document to its counterpart there. */
function fiscalPdfProviderLink(FiscalDocument $document): void
{
    IntegrationConnection::factory()->create(['credentials' => ['subdomain' => 'fiscal', 'api_key' => 'fiscal-test-secret']]);
    Invoicing::forget();
    InvoicingRecords::store($document, new RemoteDocument(
        'invoices', '1050', $document->type, RemoteDocumentState::Issued, CarbonImmutable::parse($document->document_date),
        $document->net_amount, $document->vat_amount, $document->total_amount, $document->number,
    ), IntegrationRecordState::InSync);
}

it('attaches a manual PDF on creation and retains each version when the PDF is replaced', function () {
    $user = actingAsAdmin();
    $disk = Storage::fake('local');

    Livewire::test(CreateFiscalDocument::class)
        ->fillForm([
            'type' => 'invoice',
            'direction' => 'issued',
            'number' => 'FT 2026/42',
            'document_date' => today()->toDateString(),
            'net_amount' => '100.05',
            'vat_amount' => '23.01',
            'total_amount' => '123.06',
        ])
        ->set('data.pdf_upload', [UploadedFile::fake()->createWithContent('original.pdf', fiscalPdfBytes())])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = FiscalDocument::sole();
    $original = $document->pdfVersions()->sole();
    expect($document->total_amount)->toBe(12306)
        ->and($original->user_id)->toBe($user->id)
        ->and($original->sha256)->toBe(hash('sha256', fiscalPdfBytes()))
        ->and($disk->visibility($original->path()))->toBe('private');

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->fillForm(['party_name' => 'Empresa de teste'])
        ->call('save', false)
        ->assertHasNoFormErrors();
    expect($document->pdfVersions()->count())->toBe(1);

    $replacement = fiscalPdfBytes('Substituição');
    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->set('data.pdf_upload', [UploadedFile::fake()->createWithContent('substituicao.pdf', $replacement)])
        ->call('save', false)
        ->assertHasNoFormErrors();

    expect($document->pdfVersions()->count())->toBe(2)
        ->and($disk->get($original->path()))->toBe(fiscalPdfBytes())
        ->and($disk->get($document->currentPdf()->firstOrFail()->path()))->toBe($replacement);

    $anotherDocument = FiscalDocument::factory()->create();
    $this->account->update(['trial_ends_at' => today()->subDay()]);
    $this->actingAs(ownerOf($this->account));
    asAWebRequest();
    $response = $this->get(fiscalPdfUrl($document, $this->account))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('documento-fiscal-'.$document->id.'.pdf');
    expect($response->streamedContent())->toBe($replacement);

    $history = $this->get(fiscalPdfUrl($document, $this->account).'/'.$original->id)->assertOk();
    expect($history->streamedContent())->toBe(fiscalPdfBytes());
    $this->get(fiscalPdfUrl($anotherDocument, $this->account).'/'.$original->id)->assertNotFound();
});

it('opens the edit form with strict models, as they are in the local environment', function () {
    actingAsAdmin();
    $document = FiscalDocument::factory()->create();
    Model::shouldBeStrict();

    try {
        Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
            ->assertOk()
            ->assertFormFieldExists('pdf_upload', fn (FileUpload $field): bool => $field->getUploadedFiles() === []);
    } finally {
        Model::shouldBeStrict(false);
    }
});

it('rejects invalid PDF content before saving an edit', function (string $content) {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $document = FiscalDocument::factory()->create();

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->fillForm(['party_name' => 'Não deve guardar'])
        ->set('data.pdf_upload', [UploadedFile::fake()->createWithContent('falso.pdf', $content)])
        ->call('save', false)
        ->assertHasFormErrors(['pdf_upload']);

    expect($document->refresh()->party_name)->toBeNull()
        ->and($disk->allFiles())->toBe([]);
})->with([
    'truncated PDF' => "%PDF-1.4\nFicheiro truncado",
    'PDF markers around invalid content' => "%PDF-1.4\nFalso PDF\n%%EOF\n",
]);

it('rejects a client-supplied storage path as a manual upload', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $document = FiscalDocument::factory()->create();
    $disk->put('other-document.pdf', fiscalPdfBytes());

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->set('data.pdf_upload', ['other-document.pdf'])
        ->call('save', false)
        ->assertHasFormErrors(['pdf_upload']);

    expect($document->pdfVersions()->count())->toBe(0);
    $disk->assertExists('other-document.pdf');
});

it('rolls back the document update when writing the PDF fails', function () {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $document = FiscalDocument::factory()->create();
    $blockedDirectory = "fiscal-document-pdfs/{$document->account_id}/{$document->id}";
    $disk->put($blockedDirectory, 'not a directory');

    Livewire::test(EditFiscalDocument::class, ['record' => $document->id])
        ->fillForm(['party_name' => 'Não deve guardar'])
        ->set('data.pdf_upload', [UploadedFile::fake()->createWithContent('original.pdf', fiscalPdfBytes())])
        ->call('save', false)
        ->assertHasFormErrors(['pdf_upload']);

    expect($document->refresh()->party_name)->toBeNull()
        ->and($document->pdfVersions()->count())->toBe(0);
    $disk->assertExists($blockedDirectory);
});

it('requires authenticated tenant access and keeps the E2 accountant review boundary', function () {
    $document = FiscalDocument::factory()->create();
    $other = Account::factory()->create();
    $foreign = CurrentAccount::run($other, fn () => FiscalDocument::factory()->create());
    $owner = ownerOf($this->account);
    $unverified = User::factory()->unverified()->create();
    $accountant = User::factory()->create();
    $this->account->users()->attach($unverified, ['role' => 'owner']);
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    asAWebRequest();

    $this->get(fiscalPdfUrl($document, $this->account))->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs($unverified)->get(fiscalPdfUrl($document, $this->account))
        ->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
    $this->actingAs($owner)->get(fiscalPdfUrl($foreign, $this->account))->assertNotFound();
    $this->get(fiscalPdfUrl($foreign, $other))->assertNotFound();
    $this->get(fiscalPdfUrl($document, $this->account))->assertNotFound();
    $this->actingAs($accountant)->get(fiscalPdfUrl($document, $this->account))->assertForbidden();
});

it('obtains the official PDF only on request and keeps it marked as the provider\'s', function (string $downloadUrl) {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $document = FiscalDocument::factory()->create(['origin' => 'InvoiceXpress']);
    fiscalPdfProviderLink($document);

    Http::preventStrayRequests()->fake([
        'https://fiscal.app.invoicexpress.com/api/pdf/1050.json?*' => Http::sequence()
            ->push('', 202)
            ->push(['output' => ['pdfUrl' => $downloadUrl, 'signed' => true]]),
        $downloadUrl => Http::response(fiscalPdfBytes(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    $page = Livewire::test(ViewFiscalDocument::class, ['record' => $document->id]);
    Http::assertNothingSent();

    $page->callAction('retrieveOfficialPdf')->assertNotified('O PDF ainda está a ser preparado.');
    expect($document->pdfVersions()->count())->toBe(0);

    $page->callAction('retrieveOfficialPdf')->assertNotified('PDF oficial guardado');
    $version = $document->pdfVersions()->sole();
    expect($version->source)->toBe('provider')
        ->and($disk->get($version->path()))->toBe(fiscalPdfBytes());

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://fiscal.app.invoicexpress.com/api/pdf/1050.json?')
        && str_contains($request->url(), 'second_copy=false')
        && str_contains($request->url(), 'api_key=fiscal-test-secret'));
    Http::assertSent(fn (Request $request): bool => $request->url() === $downloadUrl
        && ! $request->hasHeader('Authorization'));
})->with([
    'documents bucket' => 'https://invoicexpress-documents-pdfs.s3.eu-west-1.amazonaws.com/a1/b2/c3/9f-1-false-sent-pt-1.pdf?response-content-disposition=attachment%3B%20filename%3D%22RC-6.pdf%22&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=600&X-Amz-Signature=test',
    'downloads bucket' => 'https://invoicexpress-downloads.s3.amazonaws.com/invoice.pdf?X-Amz-Signature=test',
]);

it('fails closed when the official response cannot be used', function (string $failure) {
    actingAsAdmin();
    $disk = Storage::fake('local');
    $document = FiscalDocument::factory()->create();
    fiscalPdfProviderLink($document);

    $allowedUrl = 'https://fiscal.app.invoicexpress.com/invoice.pdf';
    $url = match ($failure) {
        'untrusted-host' => 'https://fiscal.app.invoicexpress.com.example.com/invoice.pdf',
        'untrusted-bucket' => 'https://invoicexpress-documents-pdfs.s3.eu-west-1.amazonaws.com.example.com/invoice.pdf',
        'reflected-key' => $allowedUrl.'?api_key=fiscal-test-secret',
        default => $allowedUrl,
    };

    Http::preventStrayRequests()->fake([
        'https://fiscal.app.invoicexpress.com/api/pdf/1050.json?*' => Http::response(['output' => ['pdfUrl' => $url]]),
        $allowedUrl => match ($failure) {
            'redirect' => Http::response('', 302, ['Location' => 'https://example.com/invoice.pdf']),
            'mime' => Http::response(fiscalPdfBytes(), 200, ['Content-Type' => 'text/html']),
            'size' => Http::response(fiscalPdfBytes().str_repeat('x', 10 * 1024 * 1024), 200, ['Content-Type' => 'application/pdf']),
            default => Http::response(fiscalPdfBytes(), 200, ['Content-Type' => 'application/pdf']),
        },
    ]);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])
        ->callAction('retrieveOfficialPdf')
        ->assertNotified();

    Http::assertSentCount(in_array($failure, ['untrusted-host', 'untrusted-bucket', 'reflected-key'], true) ? 1 : 2);
    expect($document->pdfVersions()->count())->toBe(0)
        ->and($disk->allFiles())->toBe([]);
})->with(['untrusted-host', 'untrusted-bucket', 'reflected-key', 'redirect', 'mime', 'size']);

it('does not offer the official PDF without a provider behind the document, nor to a read-only account', function () {
    actingAsAdmin();
    $document = FiscalDocument::factory()->create();
    Http::preventStrayRequests();

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])->assertActionHidden('retrieveOfficialPdf');

    fiscalPdfProviderLink($document);
    $owner = ownerOf($this->account);
    $this->account->update(['trial_ends_at' => today()->subDay()]);
    $this->actingAs($owner);
    Filament::setTenant($this->account);

    Livewire::test(ViewFiscalDocument::class, ['record' => $document->id])->assertActionHidden('retrieveOfficialPdf');
    Http::assertNothingSent();
});
