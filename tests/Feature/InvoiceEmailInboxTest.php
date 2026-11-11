<?php

use App\Actions\ImportExpenseDocuments;
use App\Actions\IntakeInvoiceEmail;
use App\Actions\ReviewInvoiceEmailAttachment;
use App\Filament\Pages\InvoiceEmailInboxPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\InvoiceEmailAttachment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentCsv;
use App\Support\ExpenseDocumentQr;
use App\Support\ExpenseEvidence;
use App\Support\InvoiceEmailPdfQr;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['contiu.invoice_email_inbox_enabled' => true]);
    Storage::fake('local');
    actingAsAdmin();
});

function invoiceEmailSamplePdf(): string
{
    return file_get_contents(resource_path('fixtures/invoice-email-sample.pdf'));
}

function invoiceEmailPdf(string $marker): string
{
    return "%PDF-1.4\n% ".$marker."\n%%EOF\n";
}

function invoiceEmailQr(): string
{
    return 'A:508025338*B:999999990*C:PT*D:FT*E:N*F:20261110*G:FT EMAIL/1*H:EMAIL001-1*I1:PT*I7:20.00*I8:4.60*N:4.60*O:24.60*Q:YhGV*R:9999';
}

function receiveInvoiceEmail(string $id, array $files): int
{
    $intake = app(IntakeInvoiceEmail::class);

    return $intake->receive($id, [$intake->recipient()], $files);
}

it('queues multiple private PDFs using the envelope alias and deduplicates delivery and bytes per account', function () {
    $intake = app(IntakeInvoiceEmail::class);
    $alias = $intake->recipient();
    $other = Account::factory()->create();
    $other->users()->attach(auth()->user(), ['role' => 'owner']);
    $foreignAlias = CurrentAccount::run($other, fn () => $intake->recipient());
    $files = [
        ['name' => 'first.pdf', 'bytes' => invoiceEmailPdf('first')],
        ['name' => 'ignored.txt', 'bytes' => 'plain text'],
        ['name' => 'second.pdf', 'bytes' => invoiceEmailPdf('second')],
        ['name' => 'copy.pdf', 'bytes' => invoiceEmailPdf('first')],
    ];

    expect($intake->receive('message-1', [strtoupper($alias)], $files))->toBe(2)
        ->and($intake->receive('message-1', [$alias], [['name' => 'new.pdf', 'bytes' => invoiceEmailPdf('new')]]))->toBe(0)
        ->and($intake->receive('message-2', [$alias], $files))->toBe(0);

    expect(fn () => $intake->receive('wrong', [$foreignAlias], $files))->toThrow(DomainException::class);
    expect(fn () => $intake->receive('ambiguous', [$alias, $foreignAlias], $files))->toThrow(DomainException::class);

    expect(InvoiceEmailAttachment::orderBy('id')->pluck('filename')->all())->toBe(['first.pdf', 'second.pdf'])
        ->and(Expense::count())->toBe(0)
        ->and(FiscalDocument::count())->toBe(0);
    $path = InvoiceEmailAttachment::firstOrFail()->storagePath();
    expect($path)->toStartWith("invoice-email/{$this->account->id}/");
    expect(Storage::disk('local')->getVisibility($path))->toBe('private');
    Storage::disk('public')->assertMissing($path);

    CurrentAccount::run($other, function () use ($intake, $foreignAlias, $files): void {
        expect(InvoiceEmailAttachment::count())->toBe(0)
            ->and($intake->receive('message-1', [$foreignAlias], $files))->toBe(2);
    });

    $rotated = $intake->recipient(rotate: true);
    expect($rotated)->not->toBe($alias);
    expect(fn () => $intake->receive('revoked', [$alias], $files))->toThrow(DomainException::class);
});

it('rejects disguised PDFs and enforces file and message limits before writing', function () {
    expect(receiveInvoiceEmail('invalid', [['name' => 'fake.pdf', 'bytes' => 'plain text']]))->toBe(0);
    $tooLarge = "%PDF-1.4\n".str_repeat('x', 10 * 1024 * 1024);
    expect(fn () => receiveInvoiceEmail('size', [['name' => 'big.pdf', 'bytes' => $tooLarge]]))
        ->toThrow(DomainException::class);

    $large = substr($tooLarge, 0, 7 * 1024 * 1024);
    expect(fn () => receiveInvoiceEmail('total', array_fill(0, 3, ['name' => 'big.pdf', 'bytes' => $large])))
        ->toThrow(DomainException::class);
    expect(InvoiceEmailAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('invoice-email'))->toBe([]);
});

it('suggests the expense already imported by CSV or QR and preserves existing evidence when attaching', function (string $method) {
    $supplier = Supplier::factory()->create(['name' => 'Email Supplier', 'vat_number' => 'PT508025338']);
    $expense = Expense::factory()->unpaid()->create([
        'supplier_id' => $supplier->id, 'spent_on' => '2026-11-10', 'invoice_number' => 'FT EMAIL/1',
        'net_amount' => 2000, 'vat_rate' => '0.23',
    ]);
    $original = ExpenseEvidence::directory($this->account->id).'/'.str()->uuid().'.pdf';
    Storage::disk('local')->put($original, invoiceEmailPdf('original'), 'private');
    $expense->forceFill(['proof_path' => $original])->save();

    $rows = $method === 'CSV'
        ? app(ExpenseDocumentCsv::class)->parse("data;referencia;liquido;iva;total\n2026-11-10;FT EMAIL/1;20,00;4,60;24,60")
        : [app(ExpenseDocumentQr::class)->parse(invoiceEmailQr())];
    app(ImportExpenseDocuments::class)->confirm($method === 'CSV' ? $supplier->name : '508025338', $rows, [
        ['expense' => (string) $expense->id],
    ]);

    receiveInvoiceEmail('matched', [['name' => 'bill.pdf', 'bytes' => invoiceEmailPdf('bill'), 'qr' => invoiceEmailQr()]]);
    $attachment = InvoiceEmailAttachment::sole();
    $review = app(ReviewInvoiceEmailAttachment::class);

    expect($attachment->expense_id)->toBeNull()
        ->and($review->preview($attachment)['suggested'])->toBe($expense->id);
    $review->attach($attachment->id, $expense->id);
    $review->attach($attachment->id, $expense->id);

    expect(Expense::count())->toBe(1)
        ->and(FiscalDocument::count())->toBe(1)
        ->and($attachment->fresh()->expense_id)->toBe($expense->id)
        ->and($expense->fresh()->proof_path)->toBe($original)
        ->and(Storage::disk('local')->get($original))->toBe(invoiceEmailPdf('original'));
    Storage::disk('local')->assertExists($attachment->storagePath());
})->with(['CSV', 'QR']);

it('requires an explicit manual choice without QR and refuses a cross-account expense', function () {
    $expense = Expense::factory()->create();
    $other = Account::factory()->create();
    $foreign = CurrentAccount::run($other, fn () => Expense::factory()->create());
    receiveInvoiceEmail('manual', [['name' => 'manual.pdf', 'bytes' => invoiceEmailPdf('manual')]]);
    $attachment = InvoiceEmailAttachment::sole();
    $review = app(ReviewInvoiceEmailAttachment::class);

    expect($review->preview($attachment)['state'])->toBe('manual')
        ->and($attachment->expense_id)->toBeNull();
    expect(fn () => $review->attach($attachment->id, $foreign->id))->toThrow(ModelNotFoundException::class);

    $review->attach($attachment->id, $expense->id);

    expect(FiscalDocument::count())->toBe(0)
        ->and($attachment->fresh()->expense_id)->toBe($expense->id);
    $proof = ExpenseEvidence::path($expense->fresh());
    expect(Storage::disk('local')->get($proof))->toBe(invoiceEmailPdf('manual'))
        ->and($proof)->not->toBe($attachment->storagePath());
});

it('leaves the QR import pending if the evidence cannot be copied and confirms it once on retry', function () {
    $expense = Expense::factory()->create([
        'spent_on' => '2026-11-10', 'invoice_number' => 'FT EMAIL/1', 'net_amount' => 2000, 'vat_rate' => '0.23',
        'supplier_id' => Supplier::factory()->create(['vat_number' => 'PT508025338'])->id,
    ]);
    $bytes = invoiceEmailPdf('retry');
    receiveInvoiceEmail('retry', [['name' => 'retry.pdf', 'bytes' => $bytes, 'qr' => invoiceEmailQr()]]);
    $attachment = InvoiceEmailAttachment::sole();
    $disk = Storage::disk('local');
    $disk->delete($attachment->storagePath());
    $review = app(ReviewInvoiceEmailAttachment::class);

    expect(fn () => $review->attach($attachment->id, $expense->id))->toThrow(DomainException::class);
    expect(FiscalDocument::count())->toBe(0)
        ->and($attachment->fresh()->expense_id)->toBeNull()
        ->and($expense->fresh()->proof_path)->toBeNull();

    $disk->put($attachment->storagePath(), $bytes, 'private');
    $review->attach($attachment->id, $expense->id);
    $review->attach($attachment->id, $expense->id);

    expect(FiscalDocument::sole()->links->expense_id)->toBe($expense->id)
        ->and($disk->allFiles(ExpenseEvidence::directory($this->account->id)))->toHaveCount(1);
});

it('renders the inbox in the tenant panel and forbids accountants and foreign account owners', function () {
    $owner = ownerOf($this->account);
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $foreign = Account::factory()->create();
    asAWebRequest();

    $url = url("/{$this->account->slug}/integracoes/faturas-por-email");
    $this->actingAs($owner)->get($url)->assertOk()->assertSeeText(__('Invoice email inbox'));
    $this->get(url("/{$foreign->slug}/integracoes/faturas-por-email"))->assertNotFound();
    $this->actingAs($accountant)->get($url)->assertForbidden();
});

it('simulates a sample and an upload in the browser component and downloads the private PDF', function () {
    Livewire::test(InvoiceEmailInboxPage::class)
        ->call('loadSample')
        ->assertHasNoErrors()
        ->assertSee('invoice-email-sample.pdf')
        ->set('pdf', UploadedFile::fake()->createWithContent('uploaded.pdf', invoiceEmailPdf('uploaded')))
        ->call('simulate')
        ->assertHasNoErrors()
        ->assertSee('uploaded.pdf')
        ->callAction(TestAction::make('download')->table(InvoiceEmailAttachment::where('filename', 'uploaded.pdf')->sole()))
        ->assertFileDownloaded('uploaded.pdf');

    expect(InvoiceEmailAttachment::count())->toBe(2)
        ->and(Expense::count())->toBe(0);
});

it('reads the QR in the first PDF page when the bounded renderer is available', function () {
    $reader = app(InvoiceEmailPdfQr::class);
    if (! $reader->available()) {
        $this->markTestSkipped('The optional local renderer is unavailable.');
    }

    expect($reader->read(invoiceEmailSamplePdf()))->toBe(invoiceEmailQr())
        ->and($reader->read(invoiceEmailPdf('invalid')))->toBeNull();
});

it('leaves an unmatched QR invoice pending and links to the existing expense form', function () {
    receiveInvoiceEmail('unmatched', [['name' => 'new.pdf', 'bytes' => invoiceEmailPdf('new'), 'qr' => invoiceEmailQr()]]);

    $page = Livewire::test(InvoiceEmailInboxPage::class)
        ->mountAction(TestAction::make('review')->table(InvoiceEmailAttachment::sole()));
    $action = $page->instance()->getMountedAction();
    $create = collect($action->getExtraModalFooterActions())->first();

    expect($action->getModalDescription())->toContain(__('No matching expenses. Create an expense, then reopen this review to attach the PDF.'))
        ->and($create->getUrl())->toBe(ExpenseResource::getUrl('create'))
        ->and($create->shouldOpenUrlInNewTab())->toBeTrue();

    expect(InvoiceEmailAttachment::sole()->expense_id)->toBeNull()
        ->and(Expense::count())->toBe(0)
        ->and(FiscalDocument::count())->toBe(0);
});

it('lets the owner confirm a suggested expense from the review modal', function () {
    $this->actingAs(ownerOf($this->account));
    $expense = Expense::factory()->create([
        'spent_on' => '2026-11-10', 'invoice_number' => 'FT EMAIL/1', 'net_amount' => 2000, 'vat_rate' => '0.23',
        'supplier_id' => Supplier::factory()->create(['vat_number' => 'PT508025338'])->id,
    ]);
    receiveInvoiceEmail('modal', [['name' => 'modal.pdf', 'bytes' => invoiceEmailPdf('modal'), 'qr' => invoiceEmailQr()]]);
    $attachment = InvoiceEmailAttachment::sole();

    Livewire::test(InvoiceEmailInboxPage::class)
        ->mountAction(TestAction::make('review')->table($attachment))
        ->assertSet('mountedActions.0.data.expense', $expense->id)
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($attachment->fresh()->expense_id)->toBe($expense->id)
        ->and(FiscalDocument::sole()->links->expense_id)->toBe($expense->id);
});

it('blocks simulator actions and temporary upload initialization in production', function () {
    app()->instance('env', 'production');
    $this->actingAs(ownerOf($this->account));

    Livewire::test(InvoiceEmailInboxPage::class)
        ->assertDontSee(__('Email simulator'))
        ->call('loadSample')->assertForbidden();

    Livewire::test(InvoiceEmailInboxPage::class)
        ->call('_startUpload', 'pdf', [['name' => 'invoice.pdf', 'size' => 64, 'type' => 'application/pdf']], false)
        ->assertForbidden();

    expect(fn () => app(IntakeInvoiceEmail::class)->receive('production', [], []))
        ->toThrow(HttpException::class);
    expect(InvoiceEmailAttachment::count())->toBe(0);
});

it('does not list or download a PDF owned by another account even for a super admin', function () {
    $other = Account::factory()->create();
    $foreign = CurrentAccount::run($other, fn () => InvoiceEmailAttachment::factory()->create([
        'filename' => 'foreign-private.pdf',
    ]));

    Livewire::test(InvoiceEmailInboxPage::class)
        ->assertDontSee('foreign-private.pdf')
        ->call('mountAction', 'download', [], ['table' => true, 'recordKey' => (string) $foreign->id])
        ->assertNoFileDownloaded();
});

it('removes already written attachments and allows retry when a simulated message fails mid-batch', function () {
    $files = [
        ['name' => 'first.pdf', 'bytes' => invoiceEmailPdf('first')],
        ['name' => 'second.pdf', 'bytes' => invoiceEmailPdf('second'), 'qr' => str_repeat('x', 4097)],
    ];

    expect(fn () => receiveInvoiceEmail('failed-batch', $files))->toThrow(DomainException::class);
    expect(InvoiceEmailAttachment::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('invoice-email'))->toBe([]);

    unset($files[1]['qr']);
    expect(receiveInvoiceEmail('failed-batch', $files))->toBe(2);
});

it('lets a real owner attach a previously imported unlinked CSV to the selected matching expense', function () {
    $this->actingAs(ownerOf($this->account));
    $supplier = Supplier::factory()->create(['name' => 'Known Supplier', 'vat_number' => 'PT508025338']);
    $expense = Expense::factory()->create([
        'supplier_id' => $supplier->id, 'spent_on' => '2026-11-10', 'invoice_number' => 'FT EMAIL/1',
        'net_amount' => 2000, 'vat_rate' => '0.23',
    ]);
    $rows = app(ExpenseDocumentCsv::class)->parse("data;referencia;liquido;iva;total\n2026-11-10;FT EMAIL/1;20,00;4,60;24,60");
    app(ImportExpenseDocuments::class)->confirm($supplier->name, $rows, [['expense' => 'unlinked']]);
    receiveInvoiceEmail('prior', [['name' => 'bill.pdf', 'bytes' => invoiceEmailPdf('prior'), 'qr' => invoiceEmailQr()]]);

    $attachment = InvoiceEmailAttachment::sole();
    app(ReviewInvoiceEmailAttachment::class)->attach($attachment->id, $expense->id);

    expect(FiscalDocument::sole()->links->expense_id)->toBe($expense->id)
        ->and($attachment->fresh()->expense_id)->toBe($expense->id);
});

it('flags a same-reference free-source CSV for manual PDF attachment without a duplicate fiscal document', function () {
    $expense = Expense::factory()->create([
        'supplier_id' => Supplier::factory()->create(['vat_number' => 'PT508025338'])->id,
        'spent_on' => '2026-11-10', 'invoice_number' => 'FT EMAIL/1', 'net_amount' => 2000, 'vat_rate' => '0.23',
    ]);
    $rows = app(ExpenseDocumentCsv::class)->parse("data;referencia;liquido;iva;total\n2026-11-10;FT EMAIL/1;20,00;4,60;24,60");
    app(ImportExpenseDocuments::class)->confirm('Manually labelled export', $rows, [['expense' => 'unlinked']]);
    receiveInvoiceEmail('free-source', [['name' => 'bill.pdf', 'bytes' => invoiceEmailPdf('free source'), 'qr' => invoiceEmailQr()]]);

    $review = app(ReviewInvoiceEmailAttachment::class);
    $attachment = InvoiceEmailAttachment::sole();

    expect($review->preview($attachment)['state'])->toBe('possible_duplicate')
        ->and($review->preview($attachment)['suggested'])->toBeNull()
        ->and($attachment->expense_id)->toBeNull();

    $review->attach($attachment->id, $expense->id);
    expect(FiscalDocument::sole()->links)->toBeNull()
        ->and($attachment->fresh()->expense_id)->toBe($expense->id);
});

it('restores a missing private PDF on an exact replay and preserves the existing attachment', function (string $replayId) {
    $file = ['name' => 'bill.pdf', 'bytes' => invoiceEmailPdf('restore')];
    receiveInvoiceEmail('original', [$file]);
    $attachment = InvoiceEmailAttachment::sole();
    $disk = Storage::disk('local');
    $disk->delete($attachment->storagePath());

    expect(receiveInvoiceEmail($replayId, [$file]))->toBe(1);
    expect(InvoiceEmailAttachment::sole()->id)->toBe($attachment->id)
        ->and($disk->get($attachment->storagePath()))->toBe($file['bytes'])
        ->and($disk->getVisibility($attachment->storagePath()))->toBe('private');
})->with(['original', 'new message ID']);

it('rolls back the fiscal import and removes the copied evidence when attachment confirmation fails before commit', function () {
    $expense = Expense::factory()->create([
        'spent_on' => '2026-11-10', 'invoice_number' => 'FT EMAIL/1', 'net_amount' => 2000, 'vat_rate' => '0.23',
        'supplier_id' => Supplier::factory()->create(['vat_number' => 'PT508025338'])->id,
    ]);
    receiveInvoiceEmail('atomic', [['name' => 'bill.pdf', 'bytes' => invoiceEmailPdf('atomic'), 'qr' => invoiceEmailQr()]]);
    $attachment = InvoiceEmailAttachment::sole();
    $fail = true;
    Event::listen('eloquent.saved: '.InvoiceEmailAttachment::class, function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('Failure after copying the evidence');
        }
    });

    $review = app(ReviewInvoiceEmailAttachment::class);
    expect(fn () => $review->attach($attachment->id, $expense->id))->toThrow(RuntimeException::class, 'Failure after copying');
    expect(FiscalDocument::count())->toBe(0)
        ->and($attachment->fresh()->expense_id)->toBeNull()
        ->and($expense->fresh()->proof_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles(ExpenseEvidence::directory($this->account->id)))->toBe([]);

    $fail = false;
    $review->attach($attachment->id, $expense->id);
    expect(FiscalDocument::sole()->links->expense_id)->toBe($expense->id)
        ->and(Storage::disk('local')->allFiles(ExpenseEvidence::directory($this->account->id)))->toHaveCount(1);
});

it('keeps a successful repair of a committed PDF when a later attachment rolls back the delivery', function () {
    $original = ['name' => 'existing.pdf', 'bytes' => invoiceEmailPdf('existing')];
    receiveInvoiceEmail('original', [$original]);
    $existing = InvoiceEmailAttachment::sole();
    $disk = Storage::disk('local');
    $disk->delete($existing->storagePath());

    $new = ['name' => 'new.pdf', 'bytes' => invoiceEmailPdf('new')];
    $invalid = ['name' => 'invalid.pdf', 'bytes' => invoiceEmailPdf('invalid'), 'qr' => str_repeat('x', 4097)];

    expect(fn () => receiveInvoiceEmail('failed-with-repair', [$original, $new, $invalid]))
        ->toThrow(DomainException::class);

    expect(InvoiceEmailAttachment::sole()->id)->toBe($existing->id)
        ->and($disk->get($existing->storagePath()))->toBe($original['bytes'])
        ->and($disk->allFiles('invoice-email'))->toBe([$existing->storagePath()]);
    expect(receiveInvoiceEmail('failed-with-repair', [$new]))->toBe(1);
});
