<?php

namespace App\Actions;

use App\Enums\ExpenseAttribution;
use App\Enums\FiscalDocumentDirection;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceImport;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use App\Support\InvoiceImportDraft;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out what an invoice read on "Importar faturas" will become, so that the person only has
 * to say "that's right": the supplier it belongs to, the expense it joins or creates, the bank
 * line that paid it. What the app cannot tell by itself comes back as a question.
 *
 * @phpstan-type Decision array{kind: 'expense'|'sale'|'duplicate', row: ?array<string, mixed>, supplier: ?Supplier, client: ?Client, expense: ?Expense, expense_chosen: bool, similar: ?Expense, adjust: bool, bank: ?BankTransaction, category_id: ?int, is_paid: bool, attribution: string, missing: list<string>, questions: list<string>, split: bool, links: bool}
 */
final class DecideInvoiceImport
{
    /** How far, in days, an expense typed by hand can be from the invoice's date and still be the same purchase. */
    private const int NEARBY_DAYS = 31;

    public function __construct(private ImportExpenseDocuments $imports) {}

    /**
     * @return Decision
     */
    public function handle(InvoiceImport $import): array
    {
        $draft = $import->data ?? [];
        $choices = $import->choices ?? [];
        $nif = $draft['supplier_nif'] ?? null;
        $rates = $draft['rates'] ?? [];
        $split = count($rates) > 1;
        $row = $this->row($draft);

        $decision = [
            'kind' => 'expense', 'row' => $row, 'supplier' => null, 'client' => null, 'expense' => null, 'expense_chosen' => false,
            'similar' => null, 'adjust' => false, 'bank' => null, 'category_id' => null, 'is_paid' => (bool) ($draft['paid'] ?? false),
            'attribution' => ExpenseAttribution::tryFrom((string) ($choices['attribution'] ?? ''))?->value ?? ExpenseAttribution::Professional->value,
            'missing' => $this->missing($draft), 'questions' => [], 'split' => $split,
            'links' => ! $split && $rates !== [] && InvoiceImportDraft::rateIsExact($rates[0]),
        ];

        if ($nif !== null && $nif === CurrentAccount::get()?->nif) {
            return [
                ...$decision,
                'kind' => $this->alreadyIssued($draft) ? 'duplicate' : 'sale',
                'client' => $this->client($draft),
                'missing' => array_values(array_diff($decision['missing'], ['supplier'])),
            ];
        }

        if ($row !== null && $this->alreadyImported($row)) {
            return [...$decision, 'kind' => 'duplicate'];
        }

        $decision['supplier'] = $this->supplier($draft);
        if (! InvoiceImportDraft::adds($draft) && isset($draft['net'], $draft['vat'], $draft['total'])) {
            $decision['questions'][] = 'total';
        }

        $chosen = $choices['expense'] ?? null;
        if (is_int($chosen)) {
            $decision['expense'] = $this->available()->find($chosen);
            $decision['expense_chosen'] = $decision['expense'] !== null;
        } elseif ($chosen !== 'new' && ! $split && $decision['missing'] === []) {
            $decision['expense'] = $row === null ? null : $this->sameExpense($row);
            if ($decision['expense'] === null && $decision['supplier'] !== null) {
                $decision['similar'] = $this->similarExpense($decision['supplier'], $draft);
            }
        }

        if ($decision['similar'] !== null) {
            $decision['questions'][] = 'expense';
        }

        $expense = $decision['expense'];
        $decision['adjust'] = $expense !== null && $decision['expense_chosen'] && ($choices['adjust'] ?? true)
            && ! $split && $expense->bankLink === null;
        $decision['bank'] = ($choices['bank'] ?? null) === 'none' || $expense?->bankLink !== null ? null : $this->bank($draft);
        $decision['is_paid'] = (bool) ($choices['is_paid'] ?? ($decision['is_paid'] || $decision['bank'] !== null));
        $decision['category_id'] = $this->category($choices, $expense, $decision['supplier']);

        return $decision;
    }

    /**
     * The expenses the invoice may belong to, for the person to pick: the supplier's own first,
     * then any other close in date and amount. A search looks at every expense still without an invoice.
     *
     * @return Collection<int, Expense>
     */
    public function expenseChoices(InvoiceImport $import, string $search = ''): Collection
    {
        $draft = $import->data ?? [];
        $supplier = $this->supplier($draft);
        $query = $this->available()->with('supplier:id,name')->orderByDesc('spent_on')->orderByDesc('id');
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';

            return $query->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(description) like ?', [$like])
                ->orWhereRaw('lower(invoice_number) like ?', [$like])
                ->orWhereHas('supplier', fn (Builder $supplier): Builder => $supplier->whereRaw('lower(name) like ?', [$like])))
                ->limit(8)->get();
        }

        $date = isset($draft['date']) ? CarbonImmutable::parse($draft['date']) : today()->toImmutable();
        $total = (int) ($draft['total'] ?? 0);

        return $query->whereDate('spent_on', '>=', $date->subDays(120))->whereDate('spent_on', '<=', $date->addDays(self::NEARBY_DAYS))
            ->limit(200)->get()
            ->filter(fn (Expense $expense): bool => ($supplier !== null && (int) $expense->supplier_id === (int) $supplier->id)
                || abs($expense->total - $total) <= $this->tolerance($total))
            ->sortBy(fn (Expense $expense): array => [
                $supplier !== null && (int) $expense->supplier_id === (int) $supplier->id ? 0 : 1,
                abs($expense->total - $total),
            ])
            ->take(6)->values();
    }

    /**
     * The invoice as the rest of the app imports received documents, or null while it cannot be
     * recorded as a fiscal document (no number, no supplier tax ID, amounts that do not add up).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function row(array $draft): ?array
    {
        if (! isset($draft['supplier_nif'], $draft['number'], $draft['date'], $draft['type']) || ! InvoiceImportDraft::adds($draft)) {
            return null;
        }

        return [
            'document_date' => $draft['date'],
            'number' => $draft['number'],
            'net_amount' => $draft['net'],
            'vat_amount' => $draft['vat'],
            'total_amount' => $draft['total'],
            'atcud' => $draft['atcud'] ?? null,
            'party_tax_id' => $draft['supplier_nif'],
            'type' => $draft['type'],
            'party_name' => $draft['supplier_name'] ?? null,
        ];
    }

    /**
     * What has to be filled in before the invoice can become an expense.
     *
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    private function missing(array $draft): array
    {
        return array_keys(array_filter([
            'supplier' => blank($draft['supplier_name'] ?? null) && $this->supplier($draft) === null,
            'date' => blank($draft['date'] ?? null),
            'amounts' => ! isset($draft['net'], $draft['vat'], $draft['total']) || ($draft['rates'] ?? []) === [] || $draft['total'] <= 0,
        ]));
    }

    /** @param array<string, mixed> $draft */
    private function supplier(array $draft): ?Supplier
    {
        if (filled($draft['supplier_nif'] ?? null)) {
            return $this->imports->supplierFor($draft['supplier_nif']);
        }

        $name = trim((string) ($draft['supplier_name'] ?? ''));
        $named = $name === '' ? collect() : Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->limit(2)->get();

        return $named->count() === 1 ? $named->first() : null;
    }

    /** @param array<string, mixed> $draft */
    private function client(array $draft): ?Client
    {
        $nif = $draft['customer_nif'] ?? null;

        return blank($nif) ? null : Client::query()->whereIn(DB::raw('upper(trim(vat_number))'), [$nif, 'PT'.$nif])->orderBy('id')->first();
    }

    /** @param array<string, mixed> $draft */
    private function alreadyIssued(array $draft): bool
    {
        return filled($draft['number'] ?? null) && FiscalDocument::query()
            ->where('direction', FiscalDocumentDirection::Issued)
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(trim(number)) = ?', [mb_strtolower($draft['number'])])
                ->when($draft['atcud'] ?? null, fn (Builder $query, string $atcud): Builder => $query->orWhere('atcud', $atcud)))
            ->exists();
    }

    /** @param array<string, mixed> $row */
    private function alreadyImported(array $row): bool
    {
        try {
            if ($this->imports->previousImport($row['party_tax_id'], $row) !== null) {
                return true;
            }
        } catch (DomainException) {
            // The same reference with other amounts: not to be imported again either.
            return true;
        }

        $parties = [$row['party_tax_id'], 'PT'.$row['party_tax_id']];

        return FiscalDocument::query()
            ->where('direction', FiscalDocumentDirection::Received)
            ->whereIn(DB::raw('upper(trim(party_tax_id))'), $parties)
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw('lower(trim(number)) = ?', [mb_strtolower($row['number'])])
                ->when($row['atcud'], fn (Builder $query, string $atcud): Builder => $query->orWhere('atcud', $atcud)))
            ->exists();
    }

    /** Expenses still without an invoice of their own. */
    private function available(): Builder
    {
        return Expense::query()
            ->whereNotIn('id', FiscalDocumentLink::query()->whereNotNull('expense_id')->select('expense_id'))
            ->with('bankLink');
    }

    /**
     * The expense already typed for this very invoice: same day, same amounts, and either the
     * invoice number or the supplier's tax ID to tie them.
     *
     * @param  array<string, mixed>  $row
     */
    private function sameExpense(array $row): ?Expense
    {
        $candidates = $this->imports->candidateExpenses($row);
        $ofThisSupplier = Expense::query()->whereKey($candidates->pluck('id'))
            ->whereHas('supplier', fn (Builder $supplier): Builder => $supplier
                ->whereIn(DB::raw('upper(trim(vat_number))'), [$row['party_tax_id'], 'PT'.$row['party_tax_id']]))
            ->pluck('id');
        $sure = $candidates->filter(fn (Expense $expense): bool => $expense->invoice_number === $row['number'] || $ofThisSupplier->contains($expense->id));

        return $sure->count() === 1 ? $sure->first() : null;
    }

    /**
     * An expense of the same supplier, close in date and in amount, with no supporting document
     * yet: probably this purchase, typed before the invoice arrived.
     *
     * @param  array<string, mixed>  $draft
     */
    private function similarExpense(Supplier $supplier, array $draft): ?Expense
    {
        $date = CarbonImmutable::parse($draft['date']);
        $total = (int) $draft['total'];

        return $this->available()
            ->where('supplier_id', $supplier->id)
            ->whereNull('proof_path')
            ->whereDate('spent_on', '>=', $date->subDays(self::NEARBY_DAYS))
            ->whereDate('spent_on', '<=', $date->addDays(self::NEARBY_DAYS))
            ->limit(50)->get()
            ->filter(fn (Expense $expense): bool => abs($expense->total - $total) <= $this->tolerance($total))
            ->sortBy(fn (Expense $expense): array => [abs($expense->total - $total), abs($date->diffInDays($expense->spent_on))])
            ->first();
    }

    /** How far apart two totals can be and still be the same purchase: a tenth, and never less than 1 €. */
    private function tolerance(int $total): int
    {
        return max(100, intdiv($total, 10));
    }

    /**
     * The one bank line that paid this invoice: money out, the invoice's total, on its day or in the month after.
     *
     * @param  array<string, mixed>  $draft
     */
    private function bank(array $draft): ?BankTransaction
    {
        if (! isset($draft['date'], $draft['total'])) {
            return null;
        }

        $date = CarbonImmutable::parse($draft['date']);
        $lines = BankTransaction::query()->pending()
            ->where('amount', -$draft['total'])
            ->whereDate('booked_on', '>=', $date)
            ->whereDate('booked_on', '<=', $date->addDays(30))
            ->whereDoesntHave('links')
            ->limit(2)->get();

        return $lines->count() === 1 ? $lines->first() : null;
    }

    /**
     * The category chosen, else the joined expense's own, else the one this supplier usually gets.
     *
     * @param  array<string, mixed>  $choices
     */
    private function category(array $choices, ?Expense $expense, ?Supplier $supplier): ?int
    {
        if (array_key_exists('category_id', $choices)) {
            return $choices['category_id'] === null ? null : (int) $choices['category_id'];
        }

        if ($expense !== null) {
            return $expense->category_id;
        }

        return $supplier === null ? null : Expense::query()->where('supplier_id', $supplier->id)->whereNotNull('category_id')
            ->orderByDesc('spent_on')->orderByDesc('id')->value('category_id');
    }
}
