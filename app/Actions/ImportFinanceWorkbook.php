<?php

namespace App\Actions;

use App\Enums\CategoryType;
use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocumentLink;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Support\Money;
use App\Support\PeriodLabel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Imports Pedro's "controlo financeiro" workbook (sheets Receitas, Pagamentos, Despesas, Listas)
 * into the app's model:
 * - repeated "Manutenção" rows of the same client/description/ID prefix → one monthly retainer;
 *   a single "Manutenção" row → a yearly retainer;
 * - every other Receitas row → a project with one receivable ("Pagamento único");
 * - Pagamentos → payments on the receivable created from the row with that ID;
 * - Despesas → expenses; direct only when tied to a retainer, or to a project with a direct-cost
 *   category (tools and fees stay overhead).
 * Only input columns are read; the workbook's formula columns are recomputed by the app.
 */
class ImportFinanceWorkbook
{
    private const DIRECT_COST_CATEGORIES = ['Infraestrutura', 'Subcontratação', 'Material/Equipamento', 'Deslocações'];

    private const EXPENSE_CATEGORY_ALIASES = ['ChatGPT' => 'Software/SaaS'];

    private const STATUSES = [
        'orçamentado' => ProjectStatus::Proposal,
        'aprovado' => ProjectStatus::Approved,
        'em execução' => ProjectStatus::InProgress,
        'entregue' => ProjectStatus::Completed,
        'faturado' => ProjectStatus::Completed,
        'cancelado' => ProjectStatus::Cancelled,
    ];

    private const METHODS = [
        'transferência' => PaymentMethod::Transfer,
        'mb way' => PaymentMethod::MbWay,
        'cartão' => PaymentMethod::Card,
        'dinheiro' => PaymentMethod::Cash,
        'stripe' => PaymentMethod::Stripe,
        'moloni' => PaymentMethod::Other,
        'outro' => PaymentMethod::Other,
    ];

    private ImportResult $result;

    /** @var array<string, Category> keyed "type|name" */
    private array $categories = [];

    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var array<string, Receivable> receivable created from each Receitas ID */
    private array $receivablesById = [];

    /** @var array<string, Project|Retainer> owner created from each Receitas ID */
    private array $ownersById = [];

    public function run(string $path, bool $replace = false, bool $dryRun = false): ImportResult
    {
        $sheets = $this->readWorkbook($path);

        $this->result = new ImportResult(counts: array_fill_keys(array_keys(ImportResult::LABELS), 0), dryRun: $dryRun);
        $this->categories = $this->clients = $this->suppliers = $this->receivablesById = $this->ownersById = [];

        DB::beginTransaction();

        try {
            $plans = [];

            if ($this->hasFinanceData()) {
                if (! $replace) {
                    throw new ImportRefusedException('Já existem dados financeiros. Escolhe "Substituir os dados atuais" para os apagar e importar de novo.');
                }

                $plans = $this->instalmentPlans();
                $this->wipeFinanceData();
            }

            $this->importLists($sheets['Listas']);
            $this->importIncome($sheets['Receitas']);
            $this->importPayments($sheets['Pagamentos']);
            $this->restoreInstalmentPlans($plans);
            $this->importExpenses($sheets['Despesas']);
            $this->inferRecurringExpenses();
            $this->computeTotals();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $this->result;
    }

    // Reading ---------------------------------------------------------------------------------

    /**
     * @return array<string, list<array<string, mixed>>> sheet => rows keyed by header, with "_row" (Excel row number)
     */
    private function readWorkbook(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $exception) {
            throw new ImportRefusedException('Não foi possível ler o ficheiro: '.$exception->getMessage(), previous: $exception);
        }

        $required = [
            'Receitas' => ['ID Receita/Projeto', 'Cliente', 'Descrição', 'Categoria', 'Data orçamento', 'Valor contratado sem IVA', 'IVA %', 'Data limite pagamento', 'Status'],
            'Pagamentos' => ['Data pagamento', 'ID Receita/Projeto', 'Método', 'Valor recebido'],
            'Despesas' => ['Data despesa', 'Fornecedor', 'Categoria', 'Descrição', 'Valor sem IVA', 'IVA %', 'Pago?'],
            'Listas' => ['Clientes', 'Categorias Receita', 'Categorias Despesa'],
        ];

        $sheets = [];

        foreach ($required as $name => $columns) {
            $sheet = $spreadsheet->getSheetByName($name)
                ?? throw new ImportRefusedException("O ficheiro não tem a folha \"{$name}\".");

            $rows = $sheet->toArray(null, false, false, false);
            $headers = array_map(fn ($header) => trim((string) $header), array_shift($rows) ?? []);

            foreach ($columns as $column) {
                if (! in_array($column, $headers, true)) {
                    throw new ImportRefusedException("A folha \"{$name}\" não tem a coluna \"{$column}\".");
                }
            }

            $sheets[$name] = [];

            foreach ($rows as $index => $row) {
                $values = [];

                foreach ($headers as $position => $header) {
                    if ($header !== '') {
                        $values[$header] = $row[$position] ?? null;
                    }
                }

                // Lists are column-oriented; every other sheet only counts rows whose first column is filled.
                if ($name !== 'Listas' && blank($this->text($row[0] ?? null))) {
                    continue;
                }

                $sheets[$name][] = ['_row' => $index + 2, ...$values];
            }
        }

        return $sheets;
    }

    // Listas ----------------------------------------------------------------------------------

    private function importLists(array $rows): void
    {
        foreach ($rows as $sort => $row) {
            if (filled($name = $this->text($row['Clientes']))) {
                $this->client($name);
            }

            if (filled($name = $this->text($row['Categorias Receita']))) {
                $this->category(CategoryType::Income, $name, $sort);
            }

            if (filled($name = $this->text($row['Categorias Despesa']))) {
                $this->category(CategoryType::Expense, $name, $sort);
            }
        }
    }

    // Receitas --------------------------------------------------------------------------------

    private function importIncome(array $rows): void
    {
        $maintenance = collect($rows)->filter(fn (array $row) => Str::lower($this->text($row['Categoria'])) === 'manutenção');

        $groups = $maintenance->groupBy(fn (array $row) => implode('|', [
            $this->text($row['Cliente']),
            $this->text($row['Descrição']),
            $this->idPrefix($this->text($row['ID Receita/Projeto'])),
        ]));

        foreach ($groups as $group) {
            $group->count() > 1 ? $this->monthlyRetainer($group) : $this->yearlyRetainer($group->first());
        }

        foreach ($rows as $row) {
            if (! $maintenance->contains(fn (array $candidate) => $candidate['_row'] === $row['_row'])) {
                $this->project($row);
            }
        }
    }

    private function project(array $row): void
    {
        $id = $this->text($row['ID Receita/Projeto']);
        $description = trim(str_replace("\r", '', $this->text($row['Descrição'])));
        $title = trim(Str::before($description, "\n")) ?: $id;
        $quotedOn = $this->date($row['Data orçamento']);
        $dueOn = $this->date($row['Data limite pagamento']);
        $statusLabel = Str::lower($this->text($row['Status']));
        $status = self::STATUSES[$statusLabel] ?? null;

        if ($status === null) {
            $status = ProjectStatus::Approved;
            $this->warn($row, $id, "estado \"{$row['Status']}\" desconhecido, ficou como Aprovado.");
        }

        $this->checkDueDate($row, $id, $quotedOn, $dueOn);

        $project = Project::create([
            'code' => $id,
            'client_id' => $this->client($this->text($row['Cliente']))->id,
            'category_id' => $this->category(CategoryType::Income, $this->text($row['Categoria']))?->id,
            'business_line' => $this->businessLine($id),
            'title' => $title,
            'description' => str_contains($description, "\n") ? $description : null,
            'quoted_on' => $quotedOn,
            'net_amount' => $this->cents($row['Valor contratado sem IVA']),
            'vat_rate' => $this->vat($row['IVA %']),
            'status' => $status,
            'notes' => $this->text($row['Observações'] ?? null) ?: null,
        ]);

        if ($dueOn === null) {
            $dueOn = $quotedOn ?? today();
            $this->warn($row, $id, 'sem data limite de pagamento; usei a data do orçamento.');
        }

        $this->receivablesById[$id] = $project->receivables()->create([
            'description' => 'Pagamento único',
            'amount' => $project->total,
            'due_on' => $dueOn,
        ]);

        $this->ownersById[$id] = $project;
        $this->result->counts['projects']++;
        $this->result->counts['receivables']++;
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function monthlyRetainer(Collection $rows): void
    {
        $rows = $rows->sortBy(fn (array $row) => $this->date($row['Data orçamento'])?->timestamp ?? $row['_row'])->values();
        $first = $rows->first();
        $retainer = $this->retainer($first, $this->uniqueRetainerCode($this->idPrefix($this->text($first['ID Receita/Projeto']))), Frequency::Monthly);

        foreach ($rows as $sort => $row) {
            $this->retainerPeriod($retainer, $row, $sort);
        }
    }

    private function yearlyRetainer(array $row): void
    {
        $retainer = $this->retainer($row, $this->uniqueRetainerCode($this->text($row['ID Receita/Projeto'])), Frequency::Yearly);

        $this->retainerPeriod($retainer, $row, 0);
    }

    private function retainer(array $row, string $code, Frequency $frequency): Retainer
    {
        $startsOn = $this->date($row['Data orçamento']) ?? $this->date($row['Data limite pagamento']) ?? today();
        $dueDay = $this->date($row['Data limite pagamento'])?->day ?? $startsOn->day;

        $retainer = Retainer::create([
            'code' => $code,
            'client_id' => $this->client($this->text($row['Cliente']))->id,
            'category_id' => $this->category(CategoryType::Income, $this->text($row['Categoria']))?->id,
            'business_line' => $this->businessLine($this->text($row['ID Receita/Projeto'])),
            'title' => trim(Str::before($this->text($row['Descrição']), "\n")) ?: $code,
            'net_amount' => $this->cents($row['Valor contratado sem IVA']),
            'vat_rate' => $this->vat($row['IVA %']),
            'frequency' => $frequency,
            'due_day' => min(28, $dueDay),
            'starts_on' => $startsOn,
            'is_active' => true,
        ]);

        $this->result->counts['retainers']++;

        return $retainer;
    }

    private function retainerPeriod(Retainer $retainer, array $row, int $sort): void
    {
        $id = $this->text($row['ID Receita/Projeto']);
        $periodStart = $this->date($row['Data orçamento']) ?? $this->date($row['Data limite pagamento']) ?? today();
        $dueOn = $this->date($row['Data limite pagamento']) ?? $periodStart;
        $net = $this->cents($row['Valor contratado sem IVA']);

        // A period cannot fall due before it starts (a typo in the Excel): due on the retainer's
        // due day of the period's own month instead.
        if ($dueOn->lt($periodStart)) {
            $fixed = $periodStart->copy()->day(min($retainer->due_day, $periodStart->daysInMonth));
            $fixed = $fixed->lt($periodStart) ? $periodStart->copy() : $fixed;
            $this->warn($row, $id, 'data limite '.$dueOn->format('d/m/Y').' anterior ao início do período; corrigi para '.$fixed->format('d/m/Y').'. Corrige também o Excel.');
            $dueOn = $fixed;
        }

        if ($net !== $retainer->net_amount) {
            $this->warn($row, $id, 'valor diferente dos outros meses da avença '.$retainer->code.'; mantive o valor desta linha.');
        }

        $this->receivablesById[$id] = $retainer->receivables()->create([
            'description' => PeriodLabel::for($periodStart, $retainer->frequency),
            'amount' => $net + Money::vat($net, $this->vat($row['IVA %'])),
            'due_on' => $dueOn,
            'sort' => $sort,
        ]);

        $this->ownersById[$id] = $retainer;
        $this->result->counts['receivables']++;
    }

    // Pagamentos ------------------------------------------------------------------------------

    private function importPayments(array $rows): void
    {
        foreach ($rows as $row) {
            $id = $this->text($row['ID Receita/Projeto']);
            $receivable = $this->receivablesById[$id] ?? null;

            if ($receivable === null) {
                $this->warn($row, $id ?: '—', 'recebimento ignorado: não existe nenhuma receita com este ID.', 'Pagamentos');

                continue;
            }

            $amount = $this->cents($row['Valor recebido']);
            $outstanding = $receivable->amount - $receivable->received;

            if ($amount > $outstanding) {
                $this->warn($row, $id, 'o recebimento ('.Money::format($amount).') é maior do que o valor em aberto ('.Money::format($outstanding).').', 'Pagamentos');
            }

            Payment::create([
                'receivable_id' => $receivable->id,
                'paid_on' => $this->date($row['Data pagamento']) ?? today(),
                'amount' => $amount,
                'method' => $this->method($row['Método'], $row, $id, 'Pagamentos') ?? PaymentMethod::Other,
                'invoice_number' => $this->invoiceNumber($row['Referência/Fatura'] ?? null),
                'notes' => $this->text($row['Observações'] ?? null) ?: null,
            ]);

            $this->result->counts['payments']++;
        }
    }

    // Despesas --------------------------------------------------------------------------------

    private function importExpenses(array $rows): void
    {
        foreach ($rows as $row) {
            $id = $this->text($row['ID Receita/Projeto'] ?? null);
            $categoryName = $this->text($row['Categoria']);
            $categoryName = self::EXPENSE_CATEGORY_ALIASES[$categoryName] ?? $categoryName;
            $owner = filled($id) ? ($this->ownersById[$id] ?? null) : null;
            $spentOn = $this->date($row['Data despesa']) ?? today();
            $isPaid = Str::lower($this->text($row['Pago?'])) === 'sim';

            if (filled($id) && $owner === null) {
                $this->warn($row, $id, 'a despesa refere um ID de receita que não existe; ficou como custo de estrutura.', 'Despesas');
            }

            $direct = $owner instanceof Retainer
                || ($owner instanceof Project && in_array($categoryName, self::DIRECT_COST_CATEGORIES, true));

            Expense::create([
                'supplier_id' => $this->supplier($this->text($row['Fornecedor']) ?: 'Sem fornecedor')->id,
                'category_id' => filled($categoryName) ? $this->category(CategoryType::Expense, $categoryName)->id : null,
                'kind' => $direct ? ExpenseKind::Direct : ExpenseKind::Overhead,
                'project_id' => $direct && $owner instanceof Project ? $owner->id : null,
                'retainer_id' => $direct && $owner instanceof Retainer ? $owner->id : null,
                'description' => $this->text($row['Descrição']) ?: '—',
                'spent_on' => $spentOn,
                'net_amount' => $this->cents($row['Valor sem IVA']),
                'vat_rate' => $this->vat($row['IVA %']),
                'is_paid' => $isPaid,
                'paid_on' => $isPaid ? $spentOn : null,
                'method' => $this->method($row['Método'] ?? null, $row, $id ?: '—', 'Despesas'),
                'notes' => $this->text($row['Observações'] ?? null) ?: null,
            ]);

            $this->result->counts['expenses']++;
        }
    }

    // Planos de prestações -----------------------------------------------------------------------

    /**
     * Instalment plans made in the app (the Excel only knows a single due date), by project code.
     *
     * @return array<string, list<array{description: string, amount: int, due_on: string, sort: int}>>
     */
    private function instalmentPlans(): array
    {
        return Project::query()->with('receivables')->get()
            ->filter(fn (Project $project) => $project->receivables->count() > 1
                || ($project->receivables->count() === 1 && $project->receivables->first()->description !== 'Pagamento único'))
            ->mapWithKeys(fn (Project $project) => [$project->code => $project->receivables
                ->map(fn (Receivable $receivable) => [
                    'description' => $receivable->description,
                    'amount' => $receivable->amount,
                    'due_on' => $receivable->due_on->toDateString(),
                    'sort' => (int) $receivable->sort,
                ])->values()->all()])
            ->all();
    }

    /**
     * Re-creates each kept plan on the imported project and moves its payments onto the instalments,
     * oldest payment to the earliest instalment with money still open.
     *
     * @param  array<string, list<array{description: string, amount: int, due_on: string, sort: int}>>  $plans
     */
    private function restoreInstalmentPlans(array $plans): void
    {
        $kept = [];

        foreach ($plans as $code => $plan) {
            $project = $this->ownersById[$code] ?? null;

            if (! $project instanceof Project) {
                continue;
            }

            if (array_sum(array_column($plan, 'amount')) !== $project->total) {
                $this->result->warnings[] = "Receitas ({$code}): o plano de prestações feito na app já não soma o total importado (".Money::format($project->total).'); ficou um pagamento único.';

                continue;
            }

            $single = $this->receivablesById[$code];
            $payments = Payment::query()->where('receivable_id', $single->id)->orderBy('paid_on')->orderBy('id')->get();
            $instalments = collect($plan)->sortBy([['due_on', 'asc'], ['sort', 'asc']])
                ->map(fn (array $row) => $project->receivables()->create($row))
                ->values();

            $open = $instalments->mapWithKeys(fn (Receivable $receivable) => [$receivable->id => $receivable->amount])->all();

            foreach ($payments as $payment) {
                $target = collect($open)->filter(fn (int $left) => $left > 0)->keys()->first() ?? $instalments->last()->id;
                $payment->update(['receivable_id' => $target]);
                $open[$target] -= $payment->amount;
            }

            $single->delete();
            $this->receivablesById[$code] = $instalments->first();
            $this->result->counts['receivables'] += $instalments->count() - 1;
            $kept[] = $code;
        }

        if ($kept !== []) {
            $this->result->info[] = 'Mantive o plano de prestações de '.count($kept).' '.(count($kept) === 1 ? 'trabalho' : 'trabalhos').' ('.implode(', ', $kept).').';
        }
    }

    // Despesas recorrentes ---------------------------------------------------------------------

    /**
     * Expenses with the same supplier and description in three or more different months become a
     * monthly recurring expense (template from the latest one), linked to those expenses.
     */
    private function inferRecurringExpenses(): void
    {
        $groups = Expense::query()->whereNull('recurring_expense_id')->orderBy('spent_on')->get()
            ->groupBy(fn (Expense $expense) => $expense->supplier_id.'|'.Str::lower($expense->description))
            ->filter(fn (Collection $expenses) => $expenses->map(fn (Expense $expense) => $expense->spent_on->format('Y-m'))->unique()->count() >= 3);

        foreach ($groups as $expenses) {
            $latest = $expenses->last();

            $template = RecurringExpense::create([
                'supplier_id' => $latest->supplier_id,
                'category_id' => $latest->category_id,
                'kind' => $latest->kind,
                'project_id' => $latest->project_id,
                'retainer_id' => $latest->retainer_id,
                'description' => $latest->description,
                'net_amount' => $latest->net_amount,
                'vat_rate' => $latest->vat_rate,
                'frequency' => Frequency::Monthly,
                'day' => $latest->spent_on->day,
                'method' => $latest->method ?? PaymentMethod::Card,
                'starts_on' => $expenses->first()->spent_on,
                'is_active' => $latest->spent_on->gte(today()->subMonths(2)),
            ]);

            Expense::query()->whereKey($expenses->modelKeys())->update(['recurring_expense_id' => $template->id]);
            $this->result->counts['recurring_expenses']++;
        }

        $this->result->info[] = $this->result->counts['recurring_expenses'] > 0
            ? 'Criei '.$this->result->counts['recurring_expenses'].' despesa(s) recorrente(s) a partir de despesas que se repetem em 3 ou mais meses — confirma-as em Despesas → Recorrentes.'
            : 'Nenhuma despesa se repete em 3 ou mais meses; as despesas recorrentes criam-se em Despesas → Recorrentes.';
    }

    // Data ------------------------------------------------------------------------------------

    private function hasFinanceData(): bool
    {
        foreach ([Client::class, Supplier::class, Project::class, Retainer::class, Receivable::class, Payment::class, Expense::class, RecurringExpense::class] as $model) {
            if ($model::query()->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Query deletes on purpose: model events would (rightly) refuse to delete paid receivables one by one. */
    private function wipeFinanceData(): void
    {
        if (Payment::query()->where('is_split', true)->exists()) {
            throw new ImportRefusedException('Existem recebimentos repartidos que o Excel não repõe. A substituição foi interrompida para os preservar.');
        }

        if (FiscalDocumentLink::query()->where(fn ($query) => $query
            ->whereNotNull('payment_id')
            ->orWhereNotNull('receivable_id')
            ->orWhereNotNull('expense_id'))->exists()) {
            throw new ImportRefusedException('Existem documentos fiscais associados a estes dados. Revê as ligações antes de substituir os dados pelo Excel.');
        }

        $expenses = Expense::query()->lockForUpdate()->get(['id', 'proof_path', 'proof_reference', 'attribution', 'is_reviewed']);
        $clients = Client::query()->lockForUpdate()->get(['id', 'fiscal_country_code', 'fiscal_party_type', 'vat_validated_at']);

        if ($expenses->contains(fn (Expense $expense): bool => $expense->proof_path !== null)) {
            throw new ImportRefusedException('Existem despesas com comprovativos. Revê os comprovativos antes de substituir os dados pelo Excel.');
        }

        if ($expenses->contains(fn (Expense $expense): bool => $expense->proof_reference !== null || $expense->attribution !== null || $expense->is_reviewed)
            || $clients->contains(fn (Client $client): bool => $client->fiscal_country_code !== null || $client->fiscal_party_type !== null || $client->vat_validated_at !== null)) {
            throw new ImportRefusedException('Existem dados manuais em clientes ou despesas que o Excel não repõe. A substituição foi interrompida para os preservar.');
        }

        foreach ([Payment::class, Receivable::class, Expense::class, RecurringExpense::class, Project::class, Retainer::class, Client::class, Supplier::class, Category::class] as $model) {
            match ($model) {
                Expense::class => Expense::query()->whereKey($expenses->modelKeys())->delete(),
                Client::class => Client::query()->whereKey($clients->modelKeys())->delete(),
                default => $model::query()->delete(),
            };
        }

        $this->result->info[] = 'Os dados financeiros anteriores foram apagados antes da importação.';
    }

    private function computeTotals(): void
    {
        $this->result->contracted = (int) Receivable::query()->excludingCancelled()->sum('amount');
        $this->result->received = (int) Payment::query()->sum('amount');
        $this->result->expenses = (int) Expense::query()->get()->sum(fn (Expense $expense) => $expense->total);
    }

    private function client(string $name): Client
    {
        $name = $name ?: 'Sem cliente';

        if (! isset($this->clients[$name])) {
            $this->clients[$name] = Client::query()->firstOrCreate(['name' => $name]);
            $this->result->counts['clients'] += $this->clients[$name]->wasRecentlyCreated ? 1 : 0;
        }

        return $this->clients[$name];
    }

    private function supplier(string $name): Supplier
    {
        if (! isset($this->suppliers[$name])) {
            $this->suppliers[$name] = Supplier::query()->firstOrCreate(['name' => $name]);
            $this->result->counts['suppliers'] += $this->suppliers[$name]->wasRecentlyCreated ? 1 : 0;
        }

        return $this->suppliers[$name];
    }

    private function category(CategoryType $type, string $name, ?int $sort = null): ?Category
    {
        if (blank($name)) {
            return null;
        }

        if ($type === CategoryType::Expense) {
            $name = self::EXPENSE_CATEGORY_ALIASES[$name] ?? $name;
        }

        $key = $type->value.'|'.$name;

        if (! isset($this->categories[$key])) {
            $this->categories[$key] = Category::query()->firstOrCreate(
                ['type' => $type, 'name' => $name],
                ['sort' => $sort ?? Category::query()->where('type', $type)->count()],
            );
            $this->result->counts['categories'] += $this->categories[$key]->wasRecentlyCreated ? 1 : 0;
        }

        return $this->categories[$key];
    }

    private function uniqueRetainerCode(string $code): string
    {
        $candidate = $code;

        for ($suffix = 2; Retainer::query()->where('code', $candidate)->exists(); $suffix++) {
            $candidate = $code.'-'.$suffix;
        }

        return $candidate;
    }

    // Values ----------------------------------------------------------------------------------

    private function warn(array $row, string $id, string $message, string $sheet = 'Receitas'): void
    {
        $this->result->warnings[] = "{$sheet}, linha {$row['_row']} ({$id}): {$message}";
    }

    private function checkDueDate(array $row, string $id, ?Carbon $quotedOn, ?Carbon $dueOn): void
    {
        if ($quotedOn !== null && $dueOn !== null && $dueOn->lt($quotedOn)) {
            $this->warn($row, $id, 'a data limite ('.$dueOn->format('d/m/Y').') é anterior à data do orçamento ('.$quotedOn->format('d/m/Y').'). Corrige-a na app.');
        }
    }

    private function idPrefix(string $id): string
    {
        return rtrim(preg_replace('/\d+$/', '', $id) ?? '', ' -_') ?: $id;
    }

    private function businessLine(string $id): ?string
    {
        return match (Str::upper(Str::before(trim($id), ' '))) {
            'PRINTEE' => 'Printee',
            'PRESSIU' => 'Pressiu',
            default => null,
        };
    }

    private function method(mixed $value, array $row, string $id, string $sheet): ?PaymentMethod
    {
        $label = Str::lower($this->text($value));

        if ($label === '') {
            return null;
        }

        if (! isset(self::METHODS[$label])) {
            $this->warn($row, $id, "método \"{$value}\" desconhecido, ficou como Outro.", $sheet);

            return PaymentMethod::Other;
        }

        return self::METHODS[$label];
    }

    /** "Fatura <FT ATSIRE01FT/1>" → "FT ATSIRE01FT/1". */
    private function invoiceNumber(mixed $value): ?string
    {
        $text = $this->text($value);

        if (preg_match('/<([^>]+)>/', $text, $match)) {
            return trim($match[1]);
        }

        return $text ?: null;
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /** A money cell (number or "1.965,50 €") in cents. */
    private function cents(mixed $value): int
    {
        return Money::toCents($this->number($value)) ?? 0;
    }

    private function number(mixed $value): float
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $text = str_replace(['€', ' ', "\u{00A0}"], '', $this->text($value));

        if (str_contains($text, ',')) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }

        return is_numeric($text) ? round((float) $text, 2) : 0.0;
    }

    /** 0.23, 23, "23%" → 0.23 */
    private function vat(mixed $value): float
    {
        $number = $this->number(str_replace('%', '', $this->text($value)));

        return round($number > 1 ? $number / 100 : $number, 4);
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $this->text($value))?->startOfDay() ?: Carbon::parse($this->text($value))->startOfDay();
        } catch (Throwable) {
            try {
                return Carbon::parse($this->text($value))->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }
    }
}
