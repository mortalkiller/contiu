<?php

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentType;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\FiscalProfile;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\ClientStatement;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use App\Support\InvoiceXpressIssue;
use Database\Seeders\ContiuDemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

pest()->group('integration');

beforeEach(fn () => Storage::fake('local'));

it('seeds a reproducible December and January journey and a separate starting point', function () {
    $this->seed(ContiuDemoSeeder::class);
    $demo = Account::query()->where('slug', 'contiu-demo-ines')->sole();
    $start = Account::query()->where('slug', 'contiu-demo-ines-por-receber')->sole();
    $other = Account::query()->where('slug', 'contiu-demo-oficina')->sole();

    $inspect = fn (): array => [
        'docs' => FiscalDocument::query()->count(),
        'december' => DocumentedTurnover::forYear(2026)->billedNet,
        'january' => (int) Payment::query()->whereYear('paid_on', 2027)->whereMonth('paid_on', 1)->sum('amount'),
        'januaryBankDifferences' => BankTransaction::query()->whereYear('booked_on', 2027)->whereMonth('booked_on', 1)->orderBy('id')->get()->map->difference()->all(),
        'outstanding' => Receivable::query()->where('description', 'Projeto de dezembro')->sole()->outstanding,
    ];
    $before = CurrentAccount::run($demo, $inspect);
    expect($before)->toMatchArray(['december' => 120000, 'january' => 120000, 'januaryBankDifferences' => [0, 0], 'outstanding' => 0])
        ->and(CurrentAccount::run($start, $inspect))->toMatchArray(['december' => 120000, 'january' => 0, 'januaryBankDifferences' => [70000, 50000], 'outstanding' => 120000])
        ->and(CurrentAccount::run($other, $inspect))->toMatchArray(['december' => 7000, 'january' => 0, 'outstanding' => 7000])
        ->and($demo->owner()->canAccessTenant($other))->toBeFalse();

    $this->seed(ContiuDemoSeeder::class);
    expect(CurrentAccount::run($demo, $inspect))->toBe($before)
        ->and(Account::query()->where('slug', 'like', 'contiu-demo-%')->count())->toBe(5)
        ->and(CurrentAccount::id())->toBe($this->account->id);

    CurrentAccount::run($demo, function (): void {
        $evidence = Expense::query()->where('description', 'Alojamento')->sole();
        expect($evidence->hasProof())->toBeTrue()
            ->and(Storage::disk('local')->get($evidence->proof_path))->toStartWith('%PDF-1.4');
    });
});

it('leaves an unrelated account using a demo slug intact', function (string $key) {
    $account = Account::factory()->create(['slug' => 'contiu-demo-'.$key, 'name' => 'Dados alheios']);

    expect(fn () => $this->seed(ContiuDemoSeeder::class))->toThrow(LogicException::class);
    expect(Account::query()->where('slug', 'like', 'contiu-demo-%')->count())->toBe(1)
        ->and($account->fresh()->name)->toBe('Dados alheios');
})->with(['oficina', 'eni-servicos']);

it('seeds an isolated integration account with local drafts and one imported document', function () {
    Http::fake([]);
    Http::preventStrayRequests();

    $this->seed(ContiuDemoSeeder::class);
    $this->seed(ContiuDemoSeeder::class);

    $account = Account::query()->where('slug', 'contiu-demo-integracoes')->sole();
    CurrentAccount::run($account, function (): void {
        expect(InvoiceXpressConfig::current()?->subdomain)->toBe('contiu-demo-fixture')
            ->and(FiscalDocumentDraft::query()->whereNull('fiscal_document_id')->count())->toBe(2)
            ->and(FiscalDocumentDraft::query()->whereNull('remote_id')->count())->toBe(1)
            ->and(FiscalDocument::query()->count())->toBe(1)
            ->and(InvoiceXpressSnapshot::query()->sole()->fiscal_document_id)->toBe(FiscalDocument::query()->sole()->id)
            ->and(InvoiceXpressIssue::canWriteDocuments())->toBeFalse();
    });

    expect(FiscalDocumentDraft::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('refuses to seed demo data in production', function () {
    $environment = app()->environment();
    app()->instance('env', 'production');

    try {
        expect(fn () => app(ContiuDemoSeeder::class)->run())
            ->toThrow(LogicException::class, 'A demonstração só pode ser carregada em local ou testing.');
    } finally {
        app()->instance('env', $environment);
    }

    expect(Account::query()->where('slug', 'like', 'contiu-demo-%')->exists())->toBeFalse();
});

it('seeds the ENI documents and balances without configuring issuance or contacting a provider', function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    Http::fake([]);
    Http::preventStrayRequests();

    $this->seed(ContiuDemoSeeder::class);
    $demo = Account::query()->where('slug', 'contiu-demo-eni-servicos')->first();
    expect($demo)->toBeInstanceOf(Account::class);

    CurrentAccount::run($demo, function (): void {
        $profile = FiscalProfile::query()->sole();
        expect($profile->country_code)->toBe('PT')
            ->and($profile->vat_exempt)->toBeTrue();

        $documents = FiscalDocument::query()->with(['links.receivable', 'links.payment.allocations'])->get()->keyBy('number');
        $invoice = $documents['FT ENI-DEMO/001'];
        $charge = $invoice->links->receivable;
        expect($documents)->toHaveCount(6)
            ->and($invoice->type)->toBe(FiscalDocumentType::Invoice)
            ->and($invoice->total_amount)->toBe(120000)
            ->and($invoice->party_name)->toBe($charge->client->name)
            ->and($invoice->party_tax_id)->toBe('DEMO-HORIZONTE')
            ->and($documents->pluck('direction')->unique()->all())->toBe([FiscalDocumentDirection::Issued]);

        foreach (['RC ENI-DEMO/001' => 45000, 'RC ENI-DEMO/002' => 35000] as $number => $amount) {
            $receipt = $documents[$number];
            $payment = $receipt->links->payment;
            $allocation = $payment->allocations->sole();
            expect($receipt->type)->toBe(FiscalDocumentType::Receipt)
                ->and($receipt->total_amount)->toBe($amount)
                ->and($receipt->links->related_fiscal_document_id)->toBe($invoice->id)
                ->and($payment->amount)->toBe($amount)
                ->and($allocation->amount)->toBe($amount)
                ->and($allocation->receivable_id)->toBe($charge->id)
                ->and($allocation->allocated_on->toDateString())->toBe($receipt->document_date->toDateString());
        }

        $credit = $documents['NC ENI-DEMO/001'];
        $application = $charge->creditApplications()->sole();
        expect($credit->type)->toBe(FiscalDocumentType::CreditNote)
            ->and($credit->total_amount)->toBe(-15000)
            ->and($credit->links->related_fiscal_document_id)->toBe($invoice->id)
            ->and($application->credit_note_id)->toBe($credit->id)
            ->and($application->invoice_id)->toBe($invoice->id)
            ->and($application->amount)->toBe(15000)
            ->and($charge->outstanding)->toBe(25000);

        $debit = $documents['ND ENI-DEMO/001'];
        expect($debit->type)->toBe(FiscalDocumentType::DebitNote)
            ->and($debit->total_amount)->toBe(10000)
            ->and($debit->links->related_fiscal_document_id)->toBe($invoice->id)
            ->and($debit->party_tax_id)->toBe($invoice->party_tax_id)
            ->and($debit->links->receivable->id)->not->toBe($charge->id)
            ->and($debit->links->receivable->client->id)->toBe($charge->client->id)
            ->and($debit->links->receivable->outstanding)->toBe(10000);

        $invoiceReceipt = $documents['FR ENI-DEMO/001'];
        $allocation = $invoiceReceipt->links->payment->allocations->sole();
        expect($invoiceReceipt->type)->toBe(FiscalDocumentType::InvoiceReceipt)
            ->and($invoiceReceipt->total_amount)->toBe(30000)
            ->and($allocation->receivable_id)->toBe($invoiceReceipt->links->receivable_id)
            ->and($allocation->amount)->toBe(30000)
            ->and($invoiceReceipt->links->receivable->outstanding)->toBe(0);

        $advance = Payment::query()->whereNotNull('advance_client_id')->sole();
        expect($advance->amount)->toBe(20000)
            ->and($advance->allocations)->toBeEmpty()
            ->and((new ClientStatement($charge->client))->balance())->toBe(35000);

        $turnover = DocumentedTurnover::forYear(2026);
        expect($turnover->billedNet)->toBe(145000)
            ->and($turnover->received)->toBe(130000);

        $bank = BankTransaction::query()->orderBy('booked_on')->get();
        expect($bank->map->difference()->all())->toBe([0, 0, 0, 0])
            ->and($bank->pluck('balance')->all())->toBe([195000, 230000, 260000, 280000]);

        expect(InvoiceXpressConfig::current())->toBeNull()
            ->and(InvoiceXpressIssueAttempt::query()->exists())->toBeFalse()
            ->and(InvoiceXpressIssue::canWriteDocuments())->toBeFalse();
    });

    $this->seed(ContiuDemoSeeder::class);
    expect(CurrentAccount::run($demo, fn () => FiscalDocument::query()->count()))->toBe(6)
        ->and(CurrentAccount::run($demo, fn () => Payment::query()->count()))->toBe(4)
        ->and(CurrentAccount::id())->toBe($this->account->id)
        ->and(FiscalDocument::query()->count())->toBe(0);
    Http::assertNothingSent();
});
