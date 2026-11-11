<?php

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\Actions\InvoiceXpressCancellationAction;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Account;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressCancellation;
use App\Support\InvoiceXpressImport;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicexpress');

class TestInvoiceXpressCancellationList extends ListFiscalDocuments
{
    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([InvoiceXpressCancellationAction::make()]);
    }
}

function ixCancellationOriginal(string $type = 'Invoice'): array
{
    actingAsAdmin();
    InvoiceXpressConfig::store('pilot', 'fake-test-key');
    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);

    $source = [
        'id' => 65, 'type' => $type, 'status' => 'final', 'currency' => 'Euro',
        'date' => '10/11/2026', 'inverted_sequence_number' => 'PILOTO/7',
        'reference' => 'SERV-107', 'before_taxes' => '100.01', 'taxes' => '23.00', 'total' => '123.01',
        'client' => ['name' => 'Cliente Teste', 'fiscal_id' => 'TEST-01'],
    ];

    return [app(InvoiceXpressImport::class)->confirmInvoice($source), $source];
}

function ixCancel(FiscalDocument $document, string $reason = 'Duplicado'): FiscalDocument
{
    return app(InvoiceXpressCancellation::class)->cancel($document->id, $reason, $document->invoiceXpressSnapshot->source_hash);
}

it('uses the type-specific endpoint and only imports the verified GET after requesting cancellation', function (string $type, bool $timeout) {
    [$document, $source] = ixCancellationOriginal($type);
    $charge = Receivable::factory()->create(['amount' => 12301]);
    $document->links()->create(['receivable_id' => $charge->id]);
    $resource = $type === 'Invoice' ? 'invoices' : 'simplified_invoices';
    $envelope = $type === 'Invoice' ? 'invoice' : 'simplified_invoice';
    $path = "/{$resource}/65";
    $steps = [];
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$steps, $source, $path, $envelope, $timeout, $document) {
        $step = [$request->method(), parse_url($request->url(), PHP_URL_PATH)];
        $steps[] = $step;

        return match ($step) {
            ['GET', $path.'.json'] => Http::response([$envelope => [
                ...$source, 'status' => count($steps) === 1 ? 'final' : 'canceled',
            ]]),
            ['GET', '/document/65/related_documents.json'] => Http::response(['documents' => []]),
            ['PUT', $path.'/change-state.json'] => (function () use ($request, $timeout, $document) {
                expect($request->data())->toBe(['invoice' => ['state' => 'canceled', 'message' => 'Duplicado']])
                    ->and($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);

                return $timeout
                    ? throw new ConnectionException('Simulated timeout')
                    : Http::response(['invoice' => ['status' => 'final', 'id' => 999]]);
            })(),
            default => null,
        };
    });

    $canceled = ixCancel($document, '  Duplicado  ');

    expect($steps)->toBe([
        ['GET', $path.'.json'], ['GET', '/document/65/related_documents.json'],
        ['PUT', $path.'/change-state.json'], ['GET', $path.'.json'],
    ]);
    expect($canceled->id)->toBe($document->id)
        ->and($document->fresh()->status)->toBe(FiscalDocumentStatus::Voided)
        ->and($canceled->total_amount)->toBe(12301)
        ->and($canceled->number)->toBe('PILOTO/7')
        ->and($canceled->links->receivable_id)->toBe($charge->id)
        ->and($charge->fresh()->outstanding)->toBe(12301)
        ->and(InvoiceXpressSnapshot::query()->count())->toBe(2);
})->with([
    'FT' => ['Invoice', false],
    'simplified invoice with ambiguous PUT' => ['SimplifiedInvoice', true],
])->group('integration');

it('keeps local data intact if the verification GET cannot confirm the same canceled original', function (?array $changed) {
    [$document, $source] = ixCancellationOriginal();
    $requested = false;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$requested, $source, $changed) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/invoices/65.json'] => $requested && $changed === null
                ? Http::response([], 503)
                : Http::response(['invoice' => $requested ? [...$source, 'status' => 'canceled', ...$changed] : $source]),
            ['GET', '/document/65/related_documents.json'] => Http::response(['documents' => []]),
            ['PUT', '/invoices/65/change-state.json'] => tap(Http::response(['invoice' => ['status' => 'canceled']]), function () use (&$requested): void {
                $requested = true;
            }),
            default => null,
        };
    });

    expect(fn () => ixCancel($document))->toThrow(ValidationException::class);

    Http::assertSentCount(4);
    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Active)
        ->and(InvoiceXpressSnapshot::query()->count())->toBe(1);
})->with([
    'wrong id' => [['id' => 66]],
    'still final' => [['status' => 'final']],
    'different total' => [['total' => '123.02']],
    'different number' => [['inverted_sequence_number' => 'PILOTO/8']],
    'different reference' => [['reference' => 'SERV-108']],
    'unavailable after PUT' => [null],
])->group('integration');

it('blocks cancellation before HTTP when local eligibility or the submitted confirmation changed', function (string $blocker) {
    [$document] = ixCancellationOriginal($blocker === 'invoice receipt' ? 'InvoiceReceipt' : 'Invoice');
    $reason = 'Duplicado';
    $hash = $document->invoiceXpressSnapshot->source_hash;
    Http::fake([]);
    Http::preventStrayRequests();

    if ($blocker === 'payment') {
        $charge = Receivable::factory()->create(['amount' => 12301]);
        $document->links()->create(['receivable_id' => $charge->id]);
        Payment::factory()->for($charge)->create(['amount' => 1]);
    }
    if ($blocker === 'credit') {
        CreditApplication::factory()->create(['invoice_id' => $document->id]);
    }
    if ($blocker === 'rectification') {
        FiscalDocument::factory()->create(['type' => FiscalDocumentType::DebitNote])
            ->links()->create(['related_fiscal_document_id' => $document->id]);
    }
    if ($blocker === 'catalogue gate') {
        config(['services.invoicexpress.catalog_safe_accounts' => []]);
    }
    if ($blocker === 'stale confirmation') {
        $hash = str_repeat('0', 64);
    }
    if ($blocker === 'missing reason') {
        $reason = '   ';
    }

    expect(fn () => app(InvoiceXpressCancellation::class)->cancel($document->id, $reason, $hash))->toThrow(ValidationException::class);

    Http::assertNothingSent();
    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);
})->with(['payment', 'credit', 'rectification', 'catalogue gate', 'stale confirmation', 'missing reason', 'invoice receipt'])->group('integration');

it('requires the account owner and refuses records from another account', function () {
    [$document] = ixCancellationOriginal();
    $owner = auth()->user();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create());
    Http::fake([]);
    Http::preventStrayRequests();

    $this->actingAs($accountant);
    expect(fn () => ixCancel($document))->toThrow(HttpException::class);
    $this->actingAs($owner);
    expect(fn () => app(InvoiceXpressCancellation::class)->cancel($foreign->id, 'Duplicado', str_repeat('a', 64)))
        ->toThrow(ModelNotFoundException::class);
    Http::assertNothingSent();
})->group('integration');

it('requires an empty related document list before sending the PUT', function (array $response) {
    [$document, $source] = ixCancellationOriginal();
    Http::preventStrayRequests();
    Http::fake([
        'https://pilot.app.invoicexpress.com/invoices/65.json?*' => Http::response(['invoice' => $source]),
        'https://pilot.app.invoicexpress.com/document/65/related_documents.json?*' => Http::response($response),
    ]);

    expect(fn () => ixCancel($document))->toThrow(ValidationException::class);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);
})->with([
    'existing receipt' => [['documents' => [['id' => 77, 'type' => 'Receipt']]]],
    'missing list' => [['results' => []]],
])->group('integration');

it('does not send a PUT for a settled original or a version different from the local invoice', function (string $case) {
    [$document, $source] = ixCancellationOriginal();
    if ($case === 'changed locally') {
        $document->update(['party_name' => 'Cliente alterado']);
    }
    if ($case === 'settled remotely') {
        $source['status'] = 'settled';
    }
    if ($case === 'changed remotely') {
        $source['total'] = '123.02';
    }
    Http::preventStrayRequests();
    Http::fake([
        'https://pilot.app.invoicexpress.com/invoices/65.json?*' => Http::response(['invoice' => $source]),
    ]);

    expect(fn () => ixCancel($document))->toThrow(ValidationException::class);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);
})->with(['settled remotely', 'changed locally', 'changed remotely'])->group('integration');

it('leaves a remotely canceled invoice for reconciliation when there is no verified before and after pair', function () {
    [$document, $source] = ixCancellationOriginal();
    Http::preventStrayRequests();
    Http::fake([
        'https://pilot.app.invoicexpress.com/invoices/65.json?*' => Http::response(['invoice' => [...$source, 'status' => 'canceled']]),
        'https://pilot.app.invoicexpress.com/document/65/related_documents.json?*' => Http::response(['documents' => []]),
    ]);

    expect(fn () => ixCancel($document))->toThrow(ValidationException::class);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);
})->group('integration');

it('opens a confirmation with a reason and requires submitting it before contacting the provider', function () {
    [$document, $source] = ixCancellationOriginal();
    $requested = false;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($source, &$requested) {
        return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
            ['GET', '/invoices/65.json'] => Http::response(['invoice' => [...$source, 'status' => $requested ? 'canceled' : 'final']]),
            ['GET', '/document/65/related_documents.json'] => Http::response(['documents' => []]),
            ['PUT', '/invoices/65/change-state.json'] => tap(Http::response(['invoice' => ['status' => 'canceled']]), function () use (&$requested): void {
                $requested = true;
            }),
            default => null,
        };
    });
    $action = InvoiceXpressCancellationAction::make();

    $page = Livewire::test(TestInvoiceXpressCancellationList::class)
        ->mountAction(TestAction::make($action->getName())->table($document))
        ->assertSchemaStateSet(['sourceHash' => $document->invoiceXpressSnapshot->source_hash]);
    $mounted = $page->instance()->getMountedAction();
    expect($mounted->isConfirmationRequired())->toBeTrue()
        ->and($mounted->getModalSubmitActionLabel())->toBe('Confirmar cancelamento');

    $page->fillForm(['reason' => '   '])
        ->callMountedAction()
        ->assertHasActionErrors(['reason']);
    Http::assertNothingSent();

    $page->fillForm(['reason' => 'Duplicado'])->callMountedAction()->assertHasNoActionErrors()
        ->assertNotified('Fatura cancelada e confirmada no InvoiceXpress');
    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Voided);
    Http::assertSentCount(4);
})->group('ui');
