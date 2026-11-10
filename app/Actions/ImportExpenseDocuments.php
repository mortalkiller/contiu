<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\ExpenseDocumentImport;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentCsv;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** @phpstan-import-type ExpenseDocumentRow from ExpenseDocumentCsv */
final class ImportExpenseDocuments
{
    private const CANDIDATE_LIMIT = 20;

    public function __construct(
        private SaveFiscalDocument $saveFiscalDocument,
        private ApplyBankTransaction $applyBankTransaction,
    ) {}

    /**
     * @param  list<ExpenseDocumentRow>  $rows
     * @return list<array{state: string, expenses: array<int, string>, banks: array<int, string>, expense_match: string, bank_match: string}>
     */
    public function preview(string $source, array $rows): array
    {
        $source = $this->source($source);
        $aliases = $this->sourceAliases($source);
        $imports = ExpenseDocumentImport::query()
            ->whereIn('source_key', array_merge(...array_map(fn (array $row): array => $this->sourceKeys($aliases, $row), $rows)))
            ->with('fiscalDocument')
            ->get()
            ->toBase()
            ->keyBy('source_key');

        $preview = [];
        foreach ($rows as $row) {
            $matches = $imports->only($this->sourceKeys($aliases, $row));
            if ($matches->isNotEmpty()) {
                $preview[] = [
                    'state' => $matches->count() === 1 && $this->sameDocument($matches->first(), $row) ? 'imported' : 'conflict',
                    'expenses' => [],
                    'banks' => [],
                    'expense_match' => 'none',
                    'bank_match' => 'none',
                ];

                continue;
            }

            $expenses = $this->expenses($row)
                ->whereNotIn('id', FiscalDocumentLink::query()->whereNotNull('expense_id')->select('expense_id'))
                ->with(['supplier:id,name', 'bankLink'])
                ->orderBy('id')
                ->lazy(100)
                ->filter(fn (Expense $expense): bool => $expense->vat_amount === $row['vat_amount'])
                ->take(self::CANDIDATE_LIMIT + 1)
                ->collect();
            $exactExpenses = $expenses->filter(fn (Expense $expense): bool => $expense->invoice_number === $row['number']);
            $banks = $this->banks($row)->orderBy('booked_on')->orderBy('id')->limit(self::CANDIDATE_LIMIT + 1)->get();

            $preview[] = [
                'state' => 'new',
                'expenses' => $exactExpenses->concat($expenses->diff($exactExpenses))
                    ->take(self::CANDIDATE_LIMIT)
                    ->mapWithKeys(fn (Expense $expense): array => [$expense->id => $this->expenseLabel($expense)])
                    ->all(),
                'banks' => $banks->take(self::CANDIDATE_LIMIT)->mapWithKeys(fn (BankTransaction $bank): array => [
                    $bank->id => "#{$bank->id} · {$bank->booked_on->format('d/m/Y')} · {$bank->description}",
                ])->all(),
                'expense_match' => $this->matchLabel($expenses->count(), $exactExpenses->count() === 1),
                'bank_match' => $this->matchLabel($banks->count(), $banks->count() === 1 && $banks->first()->booked_on->toDateString() === $row['document_date']),
            ];
        }

        return $preview;
    }

    /**
     * @param  list<ExpenseDocumentRow>  $rows
     * @param  array<int, array{expense?: ?string, bank?: ?string}>  $choices
     */
    public function confirm(string $source, array $rows, array $choices): int
    {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        $source = $this->source($source);

        if (array_diff_key($choices, $rows) !== []) {
            throw new DomainException('Há escolhas que já não pertencem a esta pré-visualização.');
        }

        return DB::transaction(function () use ($source, $rows, $choices): int {
            // Concurrent confirmations for the same account must see the latest imported rows.
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());

            $aliases = $this->sourceAliases($source);
            $created = 0;
            foreach ($rows as $index => $row) {
                $selectedExpense = $choices[$index]['expense'] ?? '';
                $selectedBank = $choices[$index]['bank'] ?? '';

                if ($selectedExpense === '' && $selectedBank === '') {
                    continue;
                }

                try {
                    $created += (int) $this->confirmRow($source, $aliases, $row, $selectedExpense, $selectedBank);
                } catch (DomainException $exception) {
                    throw new DomainException($row['number'].' · '.$exception->getMessage(), previous: $exception);
                }
            }

            return $created;
        }, attempts: 3);
    }

    /** @param ExpenseDocumentRow $row */
    private function confirmRow(string $source, array $aliases, array $row, string $selectedExpense, string $selectedBank): bool
    {
        $expenseId = $selectedExpense === 'unlinked' ? null : $this->candidateId($selectedExpense);
        $bankId = $selectedBank === '' ? null : $this->candidateId($selectedBank);

        if ($bankId !== null && $expenseId === null) {
            throw new DomainException('Para associar um movimento, escolhe também uma despesa.');
        }

        $sourceKeys = $this->sourceKeys($aliases, $row);
        $sourceKey = $sourceKeys[0];
        $rowHash = $this->rowHash($row);
        $matches = ExpenseDocumentImport::query()->whereIn('source_key', $sourceKeys)->with('fiscalDocument.links')->limit(2)->get();
        $import = $matches->first();

        if ($import !== null) {
            $link = $import->fiscalDocument->links;

            if ($matches->count() !== 1 || ! $this->sameDocument($import, $row)
                || (int) $link?->expense_id !== ($expenseId ?? 0)
                || ($bankId !== null && (int) $link?->expense?->bankLink?->bank_transaction_id !== $bankId)) {
                throw new DomainException('Esta referência já foi importada com outros dados ou associações.');
            }

            return false;
        }

        $origin = (isset($row['party_tax_id']) ? 'QR · ' : 'CSV · ').$source;

        if (FiscalDocument::query()
            ->where('direction', FiscalDocumentDirection::Received)
            ->where('number', $row['number'])
            ->where('origin', $origin)
            ->exists()) {
            throw new DomainException('Já existe um documento fiscal com esta origem e referência.');
        }

        $bank = null;
        if ($bankId !== null) {
            $bank = $this->banks($row)->lockForUpdate()->find($bankId);

            if ($bank === null || $bank->links()->exists()) {
                throw new DomainException('O movimento já não está disponível para conciliação.');
            }
        }

        if ($expenseId !== null) {
            $expense = $this->expenses($row)->lockForUpdate()->find($expenseId);

            if ($expense === null || $expense->vat_amount !== $row['vat_amount']
                || FiscalDocumentLink::query()->where('expense_id', $expenseId)->exists()) {
                throw new DomainException('A despesa escolhida já não está disponível para esta fatura.');
            }

            if ($bank !== null) {
                if ($expense->bankLink()->exists()) {
                    throw new DomainException('A despesa já está conciliada com outro movimento.');
                }

                $this->applyBankTransaction->linkRecords($bank, [$expense]);
            }
        }

        $document = $this->saveFiscalDocument->handle([
            ...$row,
            'direction' => FiscalDocumentDirection::Received,
            'type' => $row['type'] ?? FiscalDocumentType::Invoice,
            'origin' => $origin,
            'currency' => 'EUR',
            'expense_id' => $expenseId,
        ]);

        ExpenseDocumentImport::create([
            'source_key' => $sourceKey,
            'row_hash' => $rowHash,
            'fiscal_document_id' => $document->id,
        ]);

        return true;
    }

    /**
     * @param  ExpenseDocumentRow  $row
     * @return Builder<Expense>
     */
    private function expenses(array $row): Builder
    {
        return Expense::query()
            ->whereDate('spent_on', $row['document_date'])
            ->where('net_amount', $row['net_amount'])
            ->when($row['party_tax_id'] ?? null, fn (Builder $query, string $nif): Builder => $query
                ->whereHas('supplier', fn (Builder $supplier): Builder => $supplier->where(fn (Builder $identity): Builder => $identity
                    ->whereNull('vat_number')->orWhere('vat_number', '')
                    ->orWhereIn(DB::raw('upper(trim(vat_number))'), [$nif, 'PT'.$nif]))));
    }

    /**
     * @param  ExpenseDocumentRow  $row
     * @return Builder<BankTransaction>
     */
    private function banks(array $row): Builder
    {
        $date = CarbonImmutable::parse($row['document_date']);

        return BankTransaction::query()->pending()
            ->where('amount', '<', 0)
            ->where('amount', -$row['total_amount'])
            ->whereDate('booked_on', '>=', $date)
            ->whereDate('booked_on', '<=', $date->addDays(30))
            ->whereDoesntHave('links');
    }

    private function expenseLabel(Expense $expense): string
    {
        return implode(' · ', array_filter([
            "#{$expense->id}",
            $expense->description,
            $expense->supplier?->name,
            $expense->invoice_number,
            $expense->bankLink !== null ? 'Já conciliada' : null,
        ]));
    }

    private function matchLabel(int $count, bool $exact): string
    {
        return match (true) {
            $count === 0 => 'none',
            $count > self::CANDIDATE_LIMIT => 'limited',
            $exact => 'exact',
            default => 'ambiguous',
        };
    }

    private function source(string $source): string
    {
        $source = trim($source);
        if ($source === '' || ! mb_check_encoding($source, 'UTF-8') || mb_strlen($source) > 120 || preg_match('/\p{C}/u', $source)) {
            throw new DomainException('Indica uma origem válida, como o NIF do fornecedor.');
        }

        return $source;
    }

    /** @return list<string> The canonical identifier precedes historical aliases. */
    private function sourceAliases(string $source): array
    {
        $nameMatches = $this->suppliersNamed($source)->limit(2)->get();
        $nif = $this->nif($source) ?? ($nameMatches->count() === 1 ? $this->nif($nameMatches->first()->vat_number) : null);
        if ($nif === null) {
            return [$source];
        }

        $supplier = $this->supplierFor($nif);
        $aliases = [$nif, 'PT'.$nif, $source];
        if ($supplier !== null && $this->suppliersNamed($supplier->name)->count() === 1) {
            $aliases[] = $supplier->name;
        }

        return array_values(array_unique($aliases));
    }

    public function supplierFor(string $nif): ?Supplier
    {
        $suppliers = Supplier::query()->whereIn(DB::raw('upper(trim(vat_number))'), [$nif, 'PT'.$nif])->limit(2)->get();

        return $suppliers->count() === 1 ? $suppliers->first() : null;
    }

    /** @return Builder<Supplier> */
    private function suppliersNamed(string $name): Builder
    {
        return Supplier::query()->where(fn (Builder $query): Builder => $query
            ->where('name', $name)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]));
    }

    private function nif(?string $source): ?string
    {
        return $source !== null && preg_match('/\A(?:PT)?([1-9][0-9]{8})\z/i', trim($source), $parts)
            && $parts[1] !== '999999990' ? $parts[1] : null;
    }

    /** @param list<string> $aliases */
    private function sourceKeys(array $aliases, array $row): array
    {
        return array_map(fn (string $source): string => hash('sha256', mb_strtolower($source)."\0".mb_strtolower($row['number'])), $aliases);
    }

    private function sameDocument(ExpenseDocumentImport $import, array $row): bool
    {
        $knownAtcud = $import->fiscalDocument->atcud;

        return $import->row_hash === $this->rowHash($row)
            && ($knownAtcud === null || ! isset($row['atcud']) || $knownAtcud === $row['atcud']);
    }

    /** @param ExpenseDocumentRow $row */
    private function rowHash(array $row): string
    {
        return hash('sha256', json_encode(array_intersect_key($row, array_flip([
            'document_date', 'number', 'net_amount', 'vat_amount', 'total_amount',
        ])), JSON_THROW_ON_ERROR));
    }

    private function candidateId(string $id): int
    {
        if (! preg_match('/\A[1-9][0-9]{0,17}\z/', $id)) {
            throw new DomainException('Escolhe uma despesa ou movimento válido.');
        }

        return (int) $id;
    }
}
