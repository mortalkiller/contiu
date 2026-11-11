<?php

namespace Database\Seeders;

use App\Actions\ApplyBankTransaction;
use App\Actions\ConfirmFiscalProfile;
use App\Actions\ConnectInvoicingProvider;
use App\Actions\RecordTaxPayment;
use App\Actions\SaveFiscalDocument;
use App\Actions\SeedAccountDefaults;
use App\Enums\ExpenseAttribution;
use App\Enums\ExpenseKind;
use App\Enums\FinanceReviewKind;
use App\Enums\FinanceReviewTarget;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Frequency;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Enums\Seat;
use App\Models\Account;
use App\Models\Article;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\Expense;
use App\Models\FinanceReviewItem;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRecord;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\ExpenseEvidence;
use App\Support\FinanceSettings;
use App\Support\FiscalDocumentTotals;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use App\Support\SocialSecurity;
use App\Support\TaxObligations;
use App\Support\VatPeriodReview;
use App\Support\WithholdingReview;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class ContiuDemoSeeder extends Seeder
{
    private const MARKER = 'contiu_demo_fixture';

    private const VERSION = 1;

    private ?string $createdEvidencePath = null;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('A demonstração só pode ser carregada em local ou testing.');
        }

        $this->createdEvidencePath = null;

        try {
            DB::transaction(function (): void {
                $this->scenario('ines', 'Inês · demonstração', 'Inês', 120000, completed: true);
                $this->scenario('ines-por-receber', 'Inês · por receber', 'Inês', 120000);
                $this->scenario('oficina', 'Oficina · demonstração', 'Oficina', 7000);
                $this->createScenario('eni-servicos', 'ENI Serviços · demonstração', 'Leonor', $this->eniServices(...));
                $this->createScenario('integracoes', 'Integrações · demonstração', 'Marta', $this->integrationDocuments(...));
            });
        } catch (Throwable $exception) {
            if ($this->createdEvidencePath !== null) {
                Storage::disk(ExpenseEvidence::DISK)->delete($this->createdEvidencePath);
            }

            throw $exception;
        }
    }

    /**
     * An account connected to an invoicing provider, with what the fiscal documents screens show:
     * an issued invoice, drafts, a quote, articles and, for the synchronisation, a document whose
     * total differs and one that only exists at the provider. The connection is a fixture: its
     * credentials open nothing.
     */
    private function integrationDocuments(): void
    {
        IntegrationConnection::query()->create([
            'category' => IntegrationCategory::Invoicing,
            'provider' => 'invoicexpress',
            'credentials' => ['subdomain' => 'contiu-demo-fixture', 'api_key' => 'local-fixture-no-api-access'],
            'settings' => ConnectInvoicingProvider::DEFAULT_SETTINGS,
            'last_synced_at' => now()->subMinutes(12),
        ]);
        Invoicing::forget();

        $client = Client::query()->create(['name' => 'Atelier Horizonte', 'vat_number' => '508025338', 'fiscal_country_code' => 'PT']);
        // Immutable, so the dates worked out from it never move it.
        $date = CarbonImmutable::today();
        $dueOn = $date->addDays(30);
        $hour = Article::query()->create(['code' => 'DEV-H', 'name' => 'Hora de desenvolvimento', 'unit' => 'h', 'unit_price' => 4500]);
        Article::query()->create(['code' => 'ALOJ-A', 'name' => 'Alojamento anual', 'unit' => 'un', 'unit_price' => 6000]);
        $lines = [
            ['description' => 'Identidade visual', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 15000],
            ['description' => 'Hora de desenvolvimento · formulário de contacto', 'quantity' => 2000, 'unit' => 'h', 'unit_price' => 4500, 'article_id' => $hour->id],
        ];

        $issued = $this->invoicingDocument($client, FiscalDocumentType::Invoice, $lines, [
            'status' => FiscalDocumentStatus::Active, 'number' => 'FT DEMO/1', 'series' => 'DEMO', 'atcud' => 'DEMOATCUD-1',
            'document_date' => $date, 'due_date' => $dueOn, 'issued_at' => now()->subDay(), 'vat_exemption_reason' => 'M10',
        ], $this->charge($client, 'IX-DEMO-01', 'Identidade visual', 24000, $date->toDateString(), $dueOn->toDateString()));
        $this->atProvider($issued, '47001', RemoteDocumentState::Issued, IntegrationRecordState::InSync);
        InvoicingRecords::log('issued', IntegrationOperationStatus::Done, 'FT DEMO/1 emitida', $issued);

        $different = $this->invoicingDocument($client, FiscalDocumentType::Invoice, [$lines[0]], [
            'status' => FiscalDocumentStatus::Active, 'number' => 'FT DEMO/2', 'series' => 'DEMO', 'document_date' => $date->subDays(20), 'issued_at' => now()->subDays(20), 'vat_exemption_reason' => 'M10',
        ]);
        $this->atProvider($different, '47002', RemoteDocumentState::Issued, IntegrationRecordState::Differs, total: 12000);

        $draft = $this->invoicingDocument($client, FiscalDocumentType::Invoice, $lines, [
            'document_date' => $date, 'due_date' => $dueOn, 'vat_exemption_reason' => 'M10',
        ], $this->charge($client, 'IX-DEMO-02', 'Revisão de identidade', 24000, $date->toDateString(), $dueOn->toDateString()));
        $this->atProvider($draft, '47003', RemoteDocumentState::Draft, IntegrationRecordState::InSync);
        InvoicingRecords::log('draft_saved', IntegrationOperationStatus::Done, 'Rascunho criado no fornecedor', $draft);

        $refused = $this->invoicingDocument($client, FiscalDocumentType::Invoice, [$lines[1]], ['document_date' => $date, 'vat_exemption_reason' => 'M10']);
        InvoicingRecords::log('draft_saved', IntegrationOperationStatus::Failed, 'Rascunho recusado', $refused, 'O cliente não tem morada.');

        $quote = $this->invoicingDocument($client, FiscalDocumentType::Quote, $lines, [
            'status' => FiscalDocumentStatus::Active, 'number' => 'OR DEMO/1', 'series' => 'DEMO', 'document_date' => $date->subDays(8),
            'due_date' => $date->addDays(22), 'issued_at' => now()->subDays(8), 'accepted_at' => now()->subDays(3), 'vat_exemption_reason' => 'M10',
        ]);
        $this->atProvider($quote, '47004', RemoteDocumentState::Issued, IntegrationRecordState::InSync);

        IntegrationRecord::query()->create([
            'integration_connection_id' => Invoicing::connection()->getKey(),
            'remote_type' => 'invoices', 'remote_id' => '47005', 'state' => IntegrationRecordState::RemoteOnly, 'synced_at' => now()->subMinutes(12),
            'remote' => ['type' => 'invoice', 'state' => 'issued', 'number' => 'FT DEMO/3', 'date' => $date->subDays(5)->toDateString(),
                'net' => 12000, 'vat' => 0, 'total' => 12000, 'party_name' => $client->name, 'party_tax_id' => $client->vat_number, 'url' => null],
        ]);
        InvoicingRecords::log('synced', IntegrationOperationStatus::Done, 'Sincronizado · 1 documento por importar');
    }

    /**
     * A document written in the app, with its lines and the amounts they add up to.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    private function invoicingDocument(Client $client, FiscalDocumentType $type, array $lines, array $attributes, ?Receivable $charge = null): FiscalDocument
    {
        $totals = FiscalDocumentTotals::of($lines);
        $document = new FiscalDocument;
        $document->forceFill([
            'type' => $type, 'direction' => FiscalDocumentDirection::Issued, 'status' => FiscalDocumentStatus::Draft,
            'origin' => 'InvoiceXpress', 'currency' => 'EUR', 'client_id' => $client->id,
            'party_name' => $client->name, 'party_tax_id' => $client->vat_number,
            'net_amount' => $totals->net, 'vat_amount' => $totals->vat, 'total_amount' => $totals->total, 'discount_amount' => $totals->discount,
            ...$attributes,
        ])->save();

        foreach ($lines as $index => $line) {
            $document->lines()->create([...$line, 'sort' => $index, 'net_amount' => $totals->lines[$index]['net'], 'vat_amount' => $totals->lines[$index]['vat'], 'total_amount' => $totals->lines[$index]['total']]);
        }

        if ($charge !== null) {
            $document->links()->create(['receivable_id' => $charge->id]);
        }

        return $document;
    }

    /** Records the document's counterpart at the provider, as a synchronisation would have left it. */
    private function atProvider(FiscalDocument $document, string $remoteId, RemoteDocumentState $remoteState, IntegrationRecordState $state, ?int $total = null): void
    {
        InvoicingRecords::store($document, new RemoteDocument(
            'invoices', $remoteId, $document->type, $remoteState, CarbonImmutable::parse($document->document_date),
            $total ?? $document->net_amount, 0, $total ?? $document->total_amount, $document->number,
        ), $state);
    }

    /** @param Closure(User): void $seed */
    private function createScenario(string $key, string $name, string $ownerName, Closure $seed): void
    {
        $slug = 'contiu-demo-'.$key;
        $email = $key.'@contiu-demo.test';
        $account = Account::query()->where('slug', $slug)->lockForUpdate()->first();

        if ($account !== null) {
            $marker = CurrentAccount::run($account, fn () => Setting::query()->where('key', self::MARKER)->first()?->value);

            if ($marker !== self::VERSION || $account->owner()?->email !== $email) {
                throw new LogicException('A conta '.$slug.' já existe e não corresponde a esta demonstração.');
            }

            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new LogicException('O utilizador reservado para a conta '.$slug.' já existe.');
        }

        $owner = User::query()->create([
            'name' => $ownerName,
            'email' => $email,
            'password' => Str::random(48),
            'email_verified_at' => now(),
        ]);
        $account = Account::query()->create(['name' => $name, 'slug' => $slug, 'billing_exempt' => true]);
        $account->users()->attach($owner, ['role' => 'owner']);
        app(SeedAccountDefaults::class)->handle($account);

        CurrentAccount::run($account, function () use ($owner, $seed): void {
            $seed($owner);

            $balance = 150000;
            foreach (BankTransaction::query()->orderBy('booked_on')->orderBy('id')->get() as $line) {
                $balance += $line->amount;
                $line->update(['balance' => $balance]);
            }

            Setting::query()->create(['key' => self::MARKER, 'value' => self::VERSION]);
        });
    }

    private function scenario(string $key, string $name, string $ownerName, int $invoiceAmount, bool $completed = false): void
    {
        $this->createScenario($key, $name, $ownerName, function (User $owner) use ($invoiceAmount, $completed, $key): void {
            app(FinanceSettings::class)->set([
                'activity_started_on' => '2024-01-01',
                'activity_start_kind' => 'first',
                'vat_exempt' => true,
                'has_business_account' => true,
                'tax_reserved_amount' => $completed ? 30000 : 0,
                'obligations_tracked_from' => today()->startOfQuarter()->toDateString(),
            ]);

            $client = Client::query()->create(['name' => $key === 'oficina' ? 'Oficina Cliente' : 'Estúdio Atlântico', 'fiscal_country_code' => 'PT']);
            $charge = $this->charge($client, 'DEMO-01', 'Projeto de dezembro', $invoiceAmount, '2026-12-18', '2027-01-31');
            $invoice = $this->document(FiscalDocumentType::Invoice, 'FT DEMO/001', '2026-12-18', $invoiceAmount, ['receivable_id' => $charge->id]);

            if ($key !== 'oficina') {
                foreach (['2027-01-08' => 70000, '2027-01-22' => 50000] as $date => $amount) {
                    $line = $this->bank($date, 'Transferência Estúdio Atlântico', $amount);

                    if ($completed) {
                        $payment = app(ApplyBankTransaction::class)->toReceivable($line, $charge->fresh());
                        $this->document(FiscalDocumentType::Receipt, 'RC DEMO/'.substr($date, -2), $date, $amount, [
                            'payment_id' => $payment->id, 'related_fiscal_document_id' => $invoice->id,
                        ]);
                    }
                }
            } else {
                $this->bank('2027-01-08', 'Transferência Estúdio Atlântico', 70000);
            }

            if ($completed) {
                $this->additionalScenarios($owner, $client);
            }
        });
    }

    private function eniServices(User $owner): void
    {
        app(FinanceSettings::class)->set([
            'activity_started_on' => '2024-01-01',
            'activity_start_kind' => 'first',
            'vat_exempt' => true,
            'has_business_account' => true,
        ]);
        app(ConfirmFiscalProfile::class)->handle($owner, [
            'legal_name' => 'Leonor · ENI de demonstração',
            'country_code' => 'PT',
            'vat_number' => 'DEMO-ENI',
            'vat_regime' => 'Isenção de IVA · demonstração',
            'vat_exempt' => 'yes',
            'activity_started_on' => '2024-01-01',
            'effective_from' => '2024-01-01',
            'confirmed' => true,
        ]);

        $client = Client::query()->create([
            'name' => 'Estúdio Horizonte', 'fiscal_country_code' => 'PT', 'vat_number' => 'DEMO-HORIZONTE',
        ]);
        $charge = $this->charge($client, 'ENI-01', 'Consultoria de design', 120000, '2026-10-05', '2026-10-30');
        $invoice = $this->document(FiscalDocumentType::Invoice, 'FT ENI-DEMO/001', '2026-10-05', $charge->amount, [
            'receivable_id' => $charge->id,
        ], client: $client);

        $bank = app(ApplyBankTransaction::class);
        foreach ([
            ['2026-10-08', 45000, 'RC ENI-DEMO/001'],
            ['2026-10-12', 35000, 'RC ENI-DEMO/002'],
        ] as [$date, $amount, $number]) {
            $payment = $bank->toReceivable($this->bank($date, 'Transferência Estúdio Horizonte', $amount), $charge);
            $this->document(FiscalDocumentType::Receipt, $number, $date, $amount, [
                'payment_id' => $payment->id,
                'related_fiscal_document_id' => $invoice->id,
            ], client: $client);
        }

        $credit = $this->document(FiscalDocumentType::CreditNote, 'NC ENI-DEMO/001', '2026-10-15', -15000, [
            'related_fiscal_document_id' => $invoice->id,
        ], client: $client);
        CreditApplication::query()->create([
            'receivable_id' => $charge->id, 'invoice_id' => $invoice->id, 'credit_note_id' => $credit->id,
            'amount' => 15000, 'applied_on' => '2026-10-15', 'applied_by' => $owner->id,
        ]);

        $supplement = $charge->billable->receivables()->create([
            'description' => 'Complemento da consultoria', 'amount' => 10000, 'due_on' => '2026-11-20',
        ]);
        $this->document(FiscalDocumentType::DebitNote, 'ND ENI-DEMO/001', '2026-10-19', $supplement->amount, [
            'receivable_id' => $supplement->id,
            'related_fiscal_document_id' => $invoice->id,
        ], client: $client);

        $session = $this->charge($client, 'ENI-02', 'Sessão de acompanhamento', 30000, '2026-10-20', '2026-10-20');
        $payment = $bank->toReceivable($this->bank('2026-10-20', 'Pagamento da sessão de acompanhamento', $session->amount), $session);
        $this->document(FiscalDocumentType::InvoiceReceipt, 'FR ENI-DEMO/001', '2026-10-20', $session->amount, [
            'receivable_id' => $session->id,
            'payment_id' => $payment->id,
        ], client: $client);

        $advanceClient = Client::query()->create(['name' => 'Ateliê Poente', 'fiscal_country_code' => 'PT']);
        $advance = Payment::query()->create([
            'advance_client_id' => $advanceClient->id, 'amount' => 20000, 'paid_on' => '2026-10-23', 'method' => PaymentMethod::Transfer,
        ]);
        $bank->linkRecords($this->bank('2026-10-23', 'Sinal Ateliê Poente', $advance->amount), [$advance]);
    }

    private function additionalScenarios(User $owner, Client $client): void
    {
        foreach (['2024-01-01' => 'Enquadramento inicial de demonstração', '2027-01-01' => 'Enquadramento revisto de demonstração'] as $date => $regime) {
            app(ConfirmFiscalProfile::class)->handle($owner, [
                'legal_name' => 'Inês · demonstração',
                'country_code' => 'PT',
                'vat_number' => 'DEMO-PT',
                'vat_regime' => $regime,
                'activity_started_on' => '2024-01-01',
                'effective_from' => $date,
                'confirmed' => true,
            ]);
        }

        $foreign = Client::query()->create(['name' => 'Estudio Brisa', 'fiscal_country_code' => 'ES', 'vat_number' => 'DEMO-ES', 'seat' => Seat::EuropeanUnion]);
        Retainer::query()->create([
            'client_id' => $foreign->id, 'code' => 'AV-DEMO', 'title' => 'Manutenção mensal',
            'net_amount' => 40000, 'vat_rate' => 0, 'frequency' => Frequency::Monthly, 'due_day' => 5,
            'starts_on' => today()->startOfMonth(), 'is_active' => true,
        ]);

        $deposit = $this->charge($client, 'DEMO-02', 'Projeto com sinal', 60000, '2027-02-08', '2027-02-19');
        $early = Payment::query()->create([
            'advance_client_id' => $client->id, 'amount' => 20000, 'paid_on' => '2027-02-02', 'method' => PaymentMethod::Transfer,
        ]);
        app(ApplyBankTransaction::class)->linkRecords($this->bank('2027-02-02', 'Sinal Estúdio Atlântico', 20000), [$early]);
        $early->allocations()->create(['receivable_id' => $deposit->id, 'amount' => 20000, 'allocated_on' => '2027-02-08']);
        $second = app(ApplyBankTransaction::class)->toReceivable($this->bank('2027-02-19', 'Saldo Estúdio Atlântico', 40000), $deposit->fresh());
        $advanceInvoice = $this->document(FiscalDocumentType::Invoice, 'FT DEMO/002', '2027-02-08', 60000, ['receivable_id' => $deposit->id]);
        $this->document(FiscalDocumentType::Receipt, 'RC DEMO/003', '2027-02-02', $early->amount, ['payment_id' => $early->id]);
        $this->document(FiscalDocumentType::Receipt, 'RC DEMO/004', '2027-02-19', $second->amount, [
            'payment_id' => $second->id, 'related_fiscal_document_id' => $advanceInvoice->id,
        ]);

        $corrected = $this->charge($client, 'DEMO-03', 'Projeto com nota de crédito', 80000, '2027-02-03', '2027-02-23');
        $original = $this->document(FiscalDocumentType::Invoice, 'FT DEMO/003', '2027-02-03', 80000, ['receivable_id' => $corrected->id]);
        $creditNote = $this->document(FiscalDocumentType::CreditNote, 'NC DEMO/001', '2027-02-10', -15000, ['related_fiscal_document_id' => $original->id]);
        CreditApplication::query()->create([
            'receivable_id' => $corrected->id, 'invoice_id' => $original->id, 'credit_note_id' => $creditNote->id,
            'amount' => 15000, 'applied_on' => '2027-02-10', 'applied_by' => $owner->id,
        ]);
        app(ApplyBankTransaction::class)->toReceivable($this->bank('2027-02-23', 'Transferência sem recibo', 65000), $corrected);

        $supplier = Supplier::query()->create(['name' => 'Cloud Demo', 'seat' => Seat::OutsideEu]);
        RecurringExpense::query()->create([
            'supplier_id' => $supplier->id, 'description' => 'Alojamento mensal', 'net_amount' => 20000, 'vat_rate' => 0,
            'kind' => ExpenseKind::Overhead, 'frequency' => Frequency::Monthly, 'day' => 25,
            'starts_on' => today()->startOfMonth(), 'is_active' => true, 'method' => PaymentMethod::Transfer,
        ]);
        $cloud = Expense::query()->create([
            'supplier_id' => $supplier->id, 'description' => 'Alojamento', 'spent_on' => '2027-02-25',
            'net_amount' => 20000, 'vat_rate' => 0, 'kind' => ExpenseKind::Overhead,
            'attribution' => ExpenseAttribution::Professional, 'is_paid' => false,
        ]);
        app(ApplyBankTransaction::class)->toExpense($this->bank('2027-02-25', 'Cloud Demo', -20000), $cloud);
        $this->addDemoEvidence($cloud);
        $this->document(FiscalDocumentType::Invoice, 'FT CLOUD/001', '2027-02-25', 20000, ['expense_id' => $cloud->id], FiscalDocumentDirection::Received);

        $mixed = Expense::query()->create([
            'supplier_id' => Supplier::query()->create(['name' => 'Loja Demo', 'seat' => Seat::Portugal])->id,
            'description' => 'Internet · uso misto', 'spent_on' => today(), 'net_amount' => 5000, 'vat_rate' => 0.23,
            'kind' => ExpenseKind::Overhead, 'attribution' => ExpenseAttribution::Mixed, 'is_paid' => false,
        ]);
        $this->bank(today()->toDateString(), 'Débito por rever', -6150);

        $commission = $this->document(FiscalDocumentType::Invoice, 'FT PLAT/001', '2027-02-26', 10000, [], FiscalDocumentDirection::Received);
        $platform = Supplier::query()->create(['name' => 'Plataforma Demo', 'seat' => Seat::OutsideEu]);
        app(ApplyBankTransaction::class)->createPlatformPayouts($this->bank('2027-02-26', 'PAYOUT-DEMO-001', 90000), [[
            'title' => 'Liquidação Plataforma Demo', 'platform_id' => $platform->id, 'reference' => 'PAYOUT-DEMO-001',
            'currency' => 'EUR', 'original_gross' => 100000, 'original_commission' => 10000, 'commission_document_id' => $commission->id,
        ]]);

        $foreignCharge = $this->charge($foreign, 'DEMO-04', 'Serviço a cliente espanhol', 40000, '2027-02-05', '2027-02-24');
        $this->document(FiscalDocumentType::Invoice, 'FT DEMO/004', '2027-02-05', 40000, ['receivable_id' => $foreignCharge->id]);
        $foreignPayment = app(ApplyBankTransaction::class)->toReceivable($this->bank('2027-02-24', 'Transferência Estudio Brisa', 40000), $foreignCharge);
        $this->document(FiscalDocumentType::Receipt, 'RC DEMO/005', '2027-02-24', 40000, ['payment_id' => $foreignPayment->id]);

        $withheld = $this->charge($client, 'DEMO-05', 'Serviço com retenção', 80000, '2027-03-01', '2027-03-15');
        $withheldPayment = app(ApplyBankTransaction::class)->toReceivable($this->bank('2027-03-15', 'Líquido com retenção', 80000), $withheld);
        $receipt = $this->document(FiscalDocumentType::InvoiceReceipt, 'FR DEMO/001', '2027-03-15', 100000, [
            'receivable_id' => $withheld->id, 'payment_id' => $withheldPayment->id,
        ]);
        WithholdingReview::record($receipt->id, 20000);

        $accountant = User::query()->create([
            'name' => 'Contabilista · demonstração', 'email' => 'contabilista@contiu-demo.test',
            'password' => Str::random(48), 'email_verified_at' => now(),
        ]);
        CurrentAccount::get()->users()->attach($accountant, ['role' => 'accountant']);
        FinanceReviewItem::query()->create([
            'target_type' => FinanceReviewTarget::Expense, 'expense_id' => $mixed->id, 'target_label' => $mixed->description,
            'author_id' => $accountant->id, 'kind' => FinanceReviewKind::EvidenceRequest, 'body' => 'Podes anexar o comprovativo desta despesa?',
        ]);

        $this->fiscalScenarios();
    }

    private function fiscalScenarios(): void
    {
        $today = CarbonImmutable::today();
        $security = app(SocialSecurity::class);
        $quarter = $security->latestClosedQuarter();
        $months = collect($security->incomeMonths($quarter))
            ->mapWithKeys(fn (CarbonImmutable $month): array => [$month->format('Y-m') => 30000])->all();
        $security->recordDeclaration($quarter, $months);
        foreach ($security->contributionMonths($quarter) as $month) {
            $security->confirmContribution($quarter, $month->format('Y-m'), 4531);
        }

        $obligations = new TaxObligations(app(FinanceSettings::class), $today);
        $previous = $today->subMonth();
        $paid = collect($obligations->between($previous->startOfMonth(), $previous->endOfMonth()))
            ->first(fn ($row): bool => $row->kind === 'ss' && RecordTaxPayment::applies($row));

        if ($paid !== null) {
            $expense = app(RecordTaxPayment::class)->handle($paid);
            app(ApplyBankTransaction::class)->linkRecords($this->bank($today->toDateString(), 'Pagamento Segurança Social', -$expense->total), [$expense]);
            TaxObligationCompletion::query()->create(['key' => $paid->key, 'completed_at' => now()]);
        }

        VatPeriodReview::forPeriod($previous->year, $previous->month)
            ->confirm(4600, 0, 0, 'Apuramento fictício da demonstração', $today->endOfMonth()->toDateString());
    }

    private function addDemoEvidence(Expense $expense): void
    {
        $path = ExpenseEvidence::directory(CurrentAccount::id()).'/'.Str::uuid().'.pdf';
        $content = 'BT /F1 20 Tf 40 100 Td (Contiu - Comprovativo ficticio) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 150] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = '';

        foreach ($objects as $index => $object) {
            $offsets .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $start = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n".$offsets."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";

        $this->createdEvidencePath = $path;
        if (! Storage::disk(ExpenseEvidence::DISK)->put($path, $pdf)) {
            throw new LogicException('Não foi possível guardar o comprovativo fictício.');
        }

        $expense->forceFill(['proof_path' => $path, 'proof_reference' => 'FT CLOUD/001', 'is_reviewed' => true])->save();
    }

    private function charge(Client $client, string $code, string $title, int $amount, string $issued, string $due): Receivable
    {
        $project = Project::query()->create([
            'client_id' => $client->id, 'title' => $title, 'code' => $code, 'quoted_on' => $issued,
            'net_amount' => $amount, 'vat_rate' => 0, 'status' => ProjectStatus::Approved,
        ]);

        return $project->receivables()->create(['description' => $title, 'amount' => $amount, 'due_on' => $due]);
    }

    /** @param array<string, int> $links */
    private function document(FiscalDocumentType $type, string $reference, string $date, int $amount, array $links, FiscalDocumentDirection $direction = FiscalDocumentDirection::Issued, ?Client $client = null): FiscalDocument
    {
        return app(SaveFiscalDocument::class)->handle([
            'type' => $type, 'direction' => $direction, 'number' => $reference, 'origin' => 'Demonstração Contiu',
            'document_date' => $date, 'operation_date' => $date, 'currency' => 'EUR', 'net_amount' => $amount,
            'total_amount' => $amount, 'vat_amount' => 0, 'party_name' => $client?->name,
            'party_tax_id' => $client?->vat_number, ...$links,
        ]);
    }

    private function bank(string $date, string $description, int $amount): BankTransaction
    {
        return BankTransaction::query()->create([
            'hash' => hash('sha256', $date.'|'.$description.'|'.$amount), 'booked_on' => $date,
            'description' => $description, 'amount' => $amount, 'import_batch' => 'contiu-demo-v1',
        ]);
    }
}
