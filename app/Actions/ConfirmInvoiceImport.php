<?php

namespace App\Actions;

use App\Enums\ExpenseKind;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\InvoiceImportStatus;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\InvoiceImport;
use App\Models\Supplier;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\ExpenseEvidence;
use App\Support\FiscalDocumentPdfs;
use App\Support\ImageToPdf;
use App\Support\InvoiceImportDraft;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Está certo": turns one invoice read on "Importar faturas" into what the app said it would
 * become. An expense (new, or one already typed), with the file as its supporting document, the
 * received fiscal document and the bank line that paid it; or, for an invoice the account itself
 * issued, a sale.
 */
final class ConfirmInvoiceImport
{
    public function __construct(
        private DecideInvoiceImport $decide,
        private ImportExpenseDocuments $imports,
        private ApplyBankTransaction $bank,
        private FiscalDocumentPdfs $pdfs,
        private IssueFiscalDocument $issue,
    ) {}

    public static function canConfirm(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null
            && ! CurrentAccount::readOnly()
            && AccountantAccess::canManage($account)
            && Gate::allows('create', Expense::class)
            && Gate::allows('update', new Expense)
            && Gate::allows('create', FiscalDocument::class);
    }

    /** The invoice becomes an expense: a new one, or the one it belongs to. */
    public function expense(int $importId): InvoiceImport
    {
        abort_unless(self::canConfirm(), 403);
        $proofs = [];

        try {
            return DB::transaction(function () use ($importId, &$proofs): InvoiceImport {
                $import = $this->locked($importId);
                $decision = $this->decide->handle($import);
                $draft = $import->data;

                if ($decision['kind'] !== 'expense') {
                    throw new DomainException($decision['kind'] === 'sale'
                        ? __('This invoice was issued by you: record it as a sale.')
                        : __('This invoice had already been imported.'));
                }
                if ($decision['missing'] !== []) {
                    throw new DomainException(__('Fill in what is missing before confirming this invoice.'));
                }
                if ($decision['questions'] !== []) {
                    throw new DomainException(__('Answer the questions about this invoice before confirming it.'));
                }

                $supplier = $decision['supplier'] ?? $this->createSupplier($draft);
                $joined = $decision['expense'];
                $expenses = $joined !== null
                    ? [$this->join($joined, $draft, $decision)]
                    : $this->createExpenses($supplier, $draft, $decision);

                $pdf = $this->pdfBytes($import);
                foreach ($expenses as $expense) {
                    if ($expense->proof_path === null) {
                        $proofs[] = $this->keepProof($expense, $pdf);
                    }
                }

                $document = $this->recordDocument($decision, $expenses, $pdf);
                if ($decision['bank'] !== null && $expenses[0]->bankLink()->doesntExist()) {
                    $this->bank->linkRecords($decision['bank'], $expenses);
                }

                $import->forceFill([
                    'status' => InvoiceImportStatus::Confirmed,
                    'expense_id' => $expenses[0]->id,
                    'fiscal_document_id' => $document?->id,
                    'handled_at' => now(),
                    'outcome' => [
                        'kind' => $joined !== null ? 'joined' : 'new',
                        'total' => $draft['total'],
                        'expenses' => count($expenses),
                        'supplier_new' => $decision['supplier'] === null,
                        'label' => $expenses[0]->description,
                    ],
                ])->save();

                return $import;
            });
        } catch (Throwable $exception) {
            Storage::disk(ExpenseEvidence::DISK)->delete($proofs);

            throw $exception;
        }
    }

    /** "Registar como venda": an invoice the account issued elsewhere becomes a sale, with what it charges or what it received. */
    public function sale(int $importId): InvoiceImport
    {
        abort_unless(self::canConfirm(), 403);

        return DB::transaction(function () use ($importId): InvoiceImport {
            $import = $this->locked($importId);
            $decision = $this->decide->handle($import);
            $draft = $import->data;

            if ($decision['kind'] !== 'sale') {
                throw new DomainException($decision['kind'] === 'duplicate'
                    ? __('This invoice is already among your documents.')
                    : __('This invoice was not issued by you.'));
            }
            if (array_diff($decision['missing'], ['supplier']) !== [] || blank($draft['number'] ?? null) || ! InvoiceImportDraft::adds($draft)) {
                throw new DomainException(__('We could not read the number, the date or the amounts of this invoice. Record the sale by hand.'));
            }

            $type = FiscalDocumentType::tryFrom((string) ($draft['type'] ?? '')) ?? FiscalDocumentType::Invoice;
            $client = $decision['client'] ?? $this->createClient($draft);
            if ($client === null && $type === FiscalDocumentType::Invoice) {
                throw new DomainException(__('We could not read who this invoice was issued to. Record the sale by hand.'));
            }

            $document = new FiscalDocument;
            $document->forceFill([
                'type' => $type,
                'direction' => FiscalDocumentDirection::Issued,
                'status' => FiscalDocumentStatus::Active,
                'number' => $draft['number'],
                'atcud' => $draft['atcud'] ?? null,
                'origin' => __('Imported invoice'),
                'document_date' => $draft['date'],
                'due_date' => $type->hasTerm() ? ($draft['due_date'] ?? null) : null,
                'issued_at' => $draft['date'],
                'currency' => 'EUR',
                'net_amount' => $draft['net'],
                'vat_amount' => $draft['vat'],
                'total_amount' => $draft['total'],
                'party_name' => $client?->name ?? ($draft['customer_name'] ?? null),
                'party_tax_id' => $draft['customer_nif'] ?? null,
                'client_id' => $client?->id,
            ])->save();

            $this->issue->applyEffects($document);
            $this->keepPdf($document, $this->pdfBytes($import));

            $import->forceFill([
                'status' => InvoiceImportStatus::Sale,
                'fiscal_document_id' => $document->id,
                'handled_at' => now(),
                'outcome' => ['kind' => 'sale', 'total' => $draft['total'], 'label' => $draft['number'], 'client' => $client?->name],
            ])->save();

            return $import;
        });
    }

    /** "Não é uma despesa minha", "Já a tenho registada", "Tirar da lista": the invoice leaves the list and nothing is recorded. */
    public function dismiss(int $importId): void
    {
        abort_unless(self::canConfirm(), 403);

        DB::transaction(function () use ($importId): void {
            $import = InvoiceImport::query()->lockForUpdate()->findOrFail($importId);
            if ($import->status->isHandled()) {
                return;
            }

            $import->forceFill(['status' => InvoiceImportStatus::Dismissed, 'handled_at' => now(), 'outcome' => ['kind' => 'dismissed']])->save();
        });
    }

    /** Clears the invoices already dealt with, and their files, to start a new batch. */
    public function clearHandled(): void
    {
        abort_unless(self::canConfirm(), 403);

        $imports = InvoiceImport::query()->whereNotNull('handled_at')->get();
        Storage::disk(InvoiceImport::DISK)->delete($imports->map->storagePath()->all());
        InvoiceImport::query()->whereKey($imports->modelKeys())->delete();
    }

    private function locked(int $importId): InvoiceImport
    {
        Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
        $import = InvoiceImport::query()->lockForUpdate()->findOrFail($importId);

        if ($import->status !== InvoiceImportStatus::Read || ! is_array($import->data)) {
            throw new DomainException(__('This invoice has already been dealt with.'));
        }

        return $import;
    }

    /** @param array<string, mixed> $draft */
    private function createSupplier(array $draft): Supplier
    {
        Gate::authorize('create', Supplier::class);

        return Supplier::create(['name' => $draft['supplier_name'], 'vat_number' => $draft['supplier_nif'] ?? null]);
    }

    /** @param array<string, mixed> $draft */
    private function createClient(array $draft): ?Client
    {
        if (blank($draft['customer_name'] ?? null) && blank($draft['customer_nif'] ?? null)) {
            return null;
        }

        Gate::authorize('create', Client::class);

        return Client::create([
            'name' => $draft['customer_name'] ?? __('Client :tax_id', ['tax_id' => $draft['customer_nif']]),
            'vat_number' => $draft['customer_nif'] ?? null,
        ]);
    }

    /**
     * The invoice joins an expense already typed. Told to, the expense takes the invoice's date
     * and amounts; either way it gains the invoice number.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $decision
     */
    private function join(Expense $expense, array $draft, array $decision): Expense
    {
        $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
        Gate::authorize('update', $expense);

        $expense->fill(array_filter([
            'invoice_number' => blank($expense->invoice_number) ? ($draft['number'] ?? null) : null,
            'category_id' => $expense->category_id === null ? $decision['category_id'] : null,
        ], filled(...)));

        if ($decision['adjust']) {
            $expense->fill([
                'spent_on' => $draft['date'],
                'net_amount' => $draft['rates'][0]['net'],
                'vat_rate' => $draft['rates'][0]['rate'],
                'is_reviewed' => true,
            ]);
        }

        $expense->save();

        return $expense;
    }

    /**
     * One expense per VAT rate on the invoice, because an expense carries a single rate.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $decision
     * @return list<Expense>
     */
    private function createExpenses(Supplier $supplier, array $draft, array $decision): array
    {
        $description = filled($draft['description'] ?? null) ? $draft['description']
            : (count($draft['lines'] ?? []) === 1 ? $draft['lines'][0]['description'] : $supplier->name);
        $expenses = [];

        foreach ($draft['rates'] as $rate) {
            $expenses[] = Expense::create([
                'supplier_id' => $supplier->id,
                'category_id' => $decision['category_id'],
                'kind' => ExpenseKind::Overhead,
                'description' => Str::limit($decision['split'] ? $description.' · '.__('VAT at :rate%', ['rate' => (int) round((float) $rate['rate'] * 100)]) : $description, 250, ''),
                'spent_on' => $draft['date'],
                'net_amount' => $rate['net'],
                'vat_rate' => $rate['rate'],
                'is_paid' => $decision['is_paid'],
                'paid_on' => $decision['is_paid'] ? $draft['date'] : null,
                'invoice_number' => $draft['number'] ?? null,
                'attribution' => $decision['attribution'],
                'is_reviewed' => true,
            ]);
        }

        return $expenses;
    }

    /**
     * The received fiscal document, as every other import records it, linked to the expense when
     * the two agree to the cent. Without the invoice number or the supplier's tax ID there is no
     * document to record: the expense stands by itself.
     *
     * @param  array<string, mixed>  $decision
     * @param  list<Expense>  $expenses
     */
    private function recordDocument(array $decision, array $expenses, string $pdf): ?FiscalDocument
    {
        $row = $decision['row'];
        if ($row === null) {
            return null;
        }

        $expense = $expenses[0]->refresh();
        $agrees = count($expenses) === 1
            && $expense->spent_on->toDateString() === $row['document_date']
            && (int) $expense->net_amount === $row['net_amount']
            && $expense->vat_amount === $row['vat_amount'];
        $bank = $agrees && $decision['bank'] !== null && $expense->bankLink()->doesntExist() ? (string) $decision['bank']->id : '';

        $this->imports->confirm($row['party_tax_id'], [$row], [['expense' => $agrees ? (string) $expense->id : 'unlinked', 'bank' => $bank]]);
        $document = $this->imports->previousImport($row['party_tax_id'], $row);

        return $document === null ? null : $this->keepPdf($document, $pdf);
    }

    /** The invoice's own file on the fiscal document too, when it is a PDF the app accepts there. */
    private function keepPdf(FiscalDocument $document, string $pdf): FiscalDocument
    {
        return FiscalDocumentPdfs::isPdf($pdf) ? $this->pdfs->attach($document, $pdf) : $document;
    }

    /** The file as a PDF: itself, or the photo on a page. */
    private function pdfBytes(InvoiceImport $import): string
    {
        $bytes = Storage::disk(InvoiceImport::DISK)->get($import->storagePath());
        if (! is_string($bytes)) {
            throw new DomainException(__('The file of this invoice is no longer available.'));
        }

        return $import->isPdf() ? $bytes : ImageToPdf::convert($bytes);
    }

    private function keepProof(Expense $expense, string $pdf): string
    {
        $path = ExpenseEvidence::directory(CurrentAccount::id()).'/'.Str::uuid().'.pdf';

        if (! Storage::disk(ExpenseEvidence::DISK)->put($path, $pdf, 'private')) {
            throw new DomainException(__('Could not save the supporting document.'));
        }

        $expense->forceFill(['proof_path' => $path])->save();

        return $path;
    }
}
