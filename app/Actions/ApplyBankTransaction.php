<?php

namespace App\Actions;

use App\Enums\BankTransactionStatus;
use App\Enums\CategoryType;
use App\Enums\DifferenceExplanation;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Support\BankCandidate;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns a pending bank line into what it is: a payment received, an expense paid, a new expense,
 * a payment or expense that was already registered, or nothing (ignored). Each call is one transaction.
 */
class ApplyBankTransaction
{
    public function accept(BankTransaction $transaction, BankCandidate $candidate): Model
    {
        return match ($candidate->type) {
            'receivable' => $this->toReceivable($transaction, $candidate->model),
            'expense' => $this->toExpense($transaction, $candidate->model),
            'template' => $this->fromTemplate($transaction, $candidate->model),
            'supplier' => $this->createExpense($transaction, ['supplier_id' => $candidate->model->getKey()]),
            'payment', 'paid' => $this->linkRecords($transaction, [$candidate->model])[0],
            'partial' => $this->toReceivable($transaction, $candidate->model),
            'own_transfer' => $this->markOwnTransfer($transaction),
        };
    }

    /**
     * Explains the line with records that are already in the app (one or several: a client paying
     * two invoices at once, a platform payout = income − commission). Nothing is created; an unpaid
     * expense becomes paid on the line date. The amounts may differ — the screen shows the
     * difference first. Each record explains one line at most.
     *
     * @param  array<int, Payment|Expense>  $records
     * @return array<int, Payment|Expense>
     */
    public function linkRecords(BankTransaction $transaction, array $records, ?DifferenceExplanation $explanation = null): array
    {
        $this->ensurePending($transaction);
        $records = array_values($records);

        if ($records === []) {
            throw new DomainException('Escolhe pelo menos um registo.');
        }

        $onlyPayments = collect($records)->every(fn ($record) => $record instanceof Payment);
        $onlyExpenses = collect($records)->every(fn ($record) => $record instanceof Expense);

        if ($onlyPayments && ! $transaction->is_credit) {
            throw new DomainException('Só uma entrada de dinheiro pode ser um recebimento.');
        }

        if ($onlyExpenses && $transaction->is_credit) {
            throw new DomainException('Só uma saída de dinheiro pode ser uma despesa.');
        }

        DB::transaction(function () use ($transaction, $records, $explanation): void {
            foreach ($records as $record) {
                $this->attach($transaction, $record);
            }

            $this->settleDifference($transaction, $explanation);
            $this->markMatched($transaction);
        });

        return $records;
    }

    /**
     * "Associar…" with one or several choices. Records that already exist count at their own amount
     * (payments +, expenses −); what has to be created takes what is left of the line — a receivable
     * at most what is still open on it. Returns the plan so the screen can show the difference first.
     *
     * @param  array<int, string>  $targets  keys like "payment:12", "receivable:3", "template:2"
     * @return array<int, array{type: string, model: Model, amount: int}>
     */
    public function planTargets(BankTransaction $transaction, array $targets): array
    {
        $existing = [];
        $toCreate = [];

        foreach (array_values(array_unique($targets)) as $target) {
            [$type, $id] = array_pad(explode(':', $target, 2), 2, null);

            $model = match ($type) {
                'payment' => Payment::findOrFail($id),
                'paid', 'expense' => Expense::findOrFail($id),
                'receivable', 'partial' => Receivable::with('payments')->findOrFail($id),
                'template' => RecurringExpense::findOrFail($id),
                'supplier' => Supplier::findOrFail($id),
                default => throw new DomainException('Escolha desconhecida.'),
            };

            $model instanceof Payment || $model instanceof Expense
                ? $existing[] = ['type' => $type, 'model' => $model, 'amount' => $model instanceof Payment ? $model->amount : $model->total]
                : $toCreate[] = ['type' => $type, 'model' => $model, 'amount' => 0];
        }

        $direction = $transaction->is_credit ? 1 : -1;
        $covered = collect($existing)->sum(fn (array $step) => ($step['model'] instanceof Payment ? 1 : -1) * $direction * $step['amount']);

        foreach ($toCreate as $index => $step) {
            $remaining = $transaction->absolute_amount - $covered;

            if ($remaining <= 0) {
                throw new DomainException('Os registos escolhidos já cobrem o valor da linha.');
            }

            $amount = $step['model'] instanceof Receivable ? min($remaining, $step['model']->outstanding) : $remaining;
            $toCreate[$index]['amount'] = (int) $amount;
            $covered += $amount;
        }

        return [...$existing, ...$toCreate];
    }

    /** Line amount minus what the planned records add up to — what the screen warns about. */
    public function plannedDifference(BankTransaction $transaction, array $targets): int
    {
        $sum = collect($this->planTargets($transaction, $targets))
            ->sum(fn (array $step) => ($step['model'] instanceof Payment || $step['model'] instanceof Receivable ? 1 : -1) * $step['amount']);

        return $transaction->amount - (int) $sum;
    }

    /** @param array<int, string> $targets */
    public function applyTargets(BankTransaction $transaction, array $targets, ?DifferenceExplanation $explanation = null): void
    {
        $this->ensurePending($transaction);
        $plan = $this->planTargets($transaction, $targets);

        if ($plan === []) {
            throw new DomainException('Escolhe pelo menos um registo.');
        }

        DB::transaction(function () use ($transaction, $plan, $explanation): void {
            foreach ($plan as $step) {
                match (true) {
                    $step['model'] instanceof Payment, $step['model'] instanceof Expense => $this->attach($transaction, $step['model']),
                    $step['model'] instanceof Receivable => $this->createPayment($transaction, $step['model'], $step['amount']),
                    $step['model'] instanceof RecurringExpense => $this->createFromTemplate($transaction, $step['model'], $step['amount']),
                    $step['model'] instanceof Supplier => $this->createExpenseFor($transaction, ['supplier_id' => $step['model']->getKey()], $step['amount']),
                };
            }

            $this->settleDifference($transaction, $explanation);
            $this->markMatched($transaction);
        });
    }

    public function toReceivable(BankTransaction $transaction, Receivable $receivable): Payment
    {
        $this->ensurePending($transaction);

        if (! $transaction->is_credit) {
            throw new DomainException('Só uma entrada de dinheiro pode ser um recebimento.');
        }

        $receivable->loadMissing('payments');

        if ($transaction->absolute_amount > $receivable->outstanding) {
            throw new DomainException('O valor do extrato ('.Money::format($transaction->absolute_amount).') é maior do que o que falta receber ('.Money::format($receivable->outstanding).').');
        }

        return DB::transaction(fn (): Payment => tap(
            $this->createPayment($transaction, $receivable, $transaction->absolute_amount),
            fn () => $this->markMatched($transaction),
        ));
    }

    public function toExpense(BankTransaction $transaction, Expense $expense): Expense
    {
        $this->ensureDebit($transaction);

        if ($expense->is_paid) {
            throw new DomainException('Esta despesa já está paga.');
        }

        return DB::transaction(fn (): Expense => tap($this->attach($transaction, $expense), fn () => $this->markMatched($transaction)));
    }

    public function fromTemplate(BankTransaction $transaction, RecurringExpense $template): Expense
    {
        $this->ensureDebit($transaction);

        return DB::transaction(fn (): Expense => tap(
            $this->createFromTemplate($transaction, $template, $transaction->absolute_amount),
            fn () => $this->markMatched($transaction),
        ));
    }

    private function createFromTemplate(BankTransaction $transaction, RecurringExpense $template, int $amount): Expense
    {
        $expense = $template->expenses()->create([
            'supplier_id' => $template->supplier_id,
            'category_id' => $template->category_id,
            'kind' => $template->kind,
            'project_id' => $template->project_id,
            'retainer_id' => $template->retainer_id,
            'description' => $template->description,
            'spent_on' => $transaction->booked_on,
            'net_amount' => Money::netFromGross($amount, (string) $template->vat_rate),
            'vat_rate' => $template->vat_rate,
            'is_paid' => true,
            'paid_on' => $transaction->booked_on,
            'method' => $template->method,
        ]);

        $this->link($transaction, $expense, createdFromLine: true);

        return $expense;
    }

    /**
     * @param  array{supplier_id: int, category_id?: ?int, description?: ?string, kind?: ?string, vat_rate?: string|float|null, project_id?: ?int, retainer_id?: ?int, method?: ?string}  $data
     */
    public function createExpense(BankTransaction $transaction, array $data): Expense
    {
        $this->ensureDebit($transaction);

        return DB::transaction(fn (): Expense => tap(
            $this->createExpenseFor($transaction, $data, $transaction->absolute_amount),
            fn () => $this->markMatched($transaction),
        ));
    }

    /** @param array<string, mixed> $data */
    private function createExpenseFor(BankTransaction $transaction, array $data, int $amount): Expense
    {
        $vat = (float) ($data['vat_rate'] ?? 0);
        $kind = ExpenseKind::from($data['kind'] ?? ExpenseKind::Overhead->value);

        $expense = Expense::create([
            'supplier_id' => $data['supplier_id'],
            'category_id' => $data['category_id'] ?? null,
            'kind' => $kind,
            'project_id' => $kind === ExpenseKind::Direct ? ($data['project_id'] ?? null) : null,
            'retainer_id' => $kind === ExpenseKind::Direct ? ($data['retainer_id'] ?? null) : null,
            'description' => filled($data['description'] ?? null) ? $data['description'] : (Supplier::find($data['supplier_id'])?->name ?? $transaction->description),
            'spent_on' => $transaction->booked_on,
            'net_amount' => Money::netFromGross($amount, $vat),
            'vat_rate' => $vat,
            'is_paid' => true,
            'paid_on' => $transaction->booked_on,
            'method' => $data['method'] ?? PaymentMethod::Transfer,
            'notes' => 'Extrato bancário: '.$transaction->description,
        ]);

        $this->link($transaction, $expense, createdFromLine: true);

        return $expense;
    }

    /** Money between Pedro's own accounts: neither income nor expense. */
    public function markOwnTransfer(BankTransaction $transaction): BankTransaction
    {
        $this->ensurePending($transaction);

        $transaction->update(['status' => BankTransactionStatus::OwnTransfer]);

        return $transaction;
    }

    /**
     * "Voltar a tratar": the line goes back to pending. Records created from it are deleted, an
     * expense it marked as paid goes back to unpaid, records that already existed stay as they were.
     */
    public function undo(BankTransaction $transaction): void
    {
        if ($transaction->status === BankTransactionStatus::Pending) {
            throw new DomainException('Esta linha ainda está por tratar.');
        }

        DB::transaction(function () use ($transaction): void {
            foreach ($transaction->links()->with('linkable')->get() as $link) {
                $record = $link->linkable;
                $link->delete();

                if ($record === null) {
                    continue;
                }

                if ($link->created_from_line) {
                    $this->deleteCreated($record);
                } elseif ($link->marked_paid && $record instanceof Expense) {
                    $record->update(['is_paid' => false, 'paid_on' => null]);
                }
            }

            $transaction->update(['status' => BankTransactionStatus::Pending, 'difference_explanation' => null]);
        });
    }

    /**
     * No money stays unexplained: when the linked records don't add up to the line, the difference
     * needs an explanation that fits it. A bank fee or another income is created so the line adds
     * up; the other explanations only say where the rest went.
     */
    private function settleDifference(BankTransaction $transaction, ?DifferenceExplanation $explanation): void
    {
        $difference = $transaction->difference();

        if ($difference === 0) {
            $transaction->update(['difference_explanation' => null]);

            return;
        }

        if ($explanation === null) {
            throw new DomainException('Os registos não dão o valor da linha: explica a diferença de '.Money::format(abs($difference)).'.');
        }

        $lineExceeds = ($difference > 0) === $transaction->is_credit;

        if (! in_array($explanation, DifferenceExplanation::optionsFor($transaction->is_credit, $lineExceeds), true)) {
            throw new DomainException('Essa explicação não serve para esta diferença.');
        }

        $amount = abs($difference);

        if ($explanation === DifferenceExplanation::BankFee) {
            $fee = Expense::create([
                'supplier_id' => Supplier::firstOrCreate(['name' => 'Banco'])->id,
                'category_id' => Category::firstOrCreate(['type' => CategoryType::Expense, 'name' => RecordOneOffIncome::COMMISSION_CATEGORY])->id,
                'kind' => ExpenseKind::Overhead,
                'description' => 'Comissão bancária · '.$transaction->description,
                'spent_on' => $transaction->booked_on,
                'net_amount' => $amount,
                'vat_rate' => 0,
                'is_paid' => true,
                'paid_on' => $transaction->booked_on,
                'method' => PaymentMethod::Transfer,
            ]);

            $this->link($transaction, $fee, createdFromLine: true);
        }

        if ($explanation === DifferenceExplanation::OtherIncome) {
            $income = app(RecordOneOffIncome::class)->handle([
                'received_on' => $transaction->booked_on,
                'title' => 'Diferença no extrato · '.$transaction->description,
                'gross_amount' => $amount,
            ]);

            foreach ($income->receivables()->with('payments')->get()->flatMap->payments as $payment) {
                $this->link($transaction, $payment, createdFromLine: true);
            }
        }

        $transaction->update(['difference_explanation' => $explanation]);
    }

    /** A payment of a one-off income takes the whole income with it (receivable and commission). */
    private function deleteCreated(Payment|Expense $record): void
    {
        $income = $record instanceof Payment ? $record->receivable?->billable : null;

        $income instanceof OneOffIncome ? $income->delete() : $record->fresh()?->delete();
    }

    /**
     * "Criar receita" from a credit line: the income at its gross amount on the line date, the
     * platform commission as an expense, both explaining the line (gross − commission = line).
     *
     * @param  array<string, mixed>  $data  RecordOneOffIncome::handle() keys, without received_on
     */
    public function createOneOffIncome(BankTransaction $transaction, array $data): OneOffIncome
    {
        $this->ensurePending($transaction);

        if (! $transaction->is_credit) {
            throw new DomainException('Só uma entrada de dinheiro pode ser uma receita.');
        }

        $net = (int) $data['gross_amount'] - (int) ($data['commission'] ?? 0);

        if ($net !== $transaction->absolute_amount) {
            throw new DomainException('Valor bruto menos comissão dá '.Money::format($net).', mas chegaram ao banco '.Money::format($transaction->absolute_amount).'.');
        }

        return DB::transaction(function () use ($transaction, $data): OneOffIncome {
            $income = app(RecordOneOffIncome::class)->handle([...$data, 'received_on' => $transaction->booked_on]);

            foreach ($income->receivables()->with('payments')->get()->flatMap->payments as $payment) {
                $this->link($transaction, $payment, createdFromLine: true);
            }

            if ($income->commissionExpense !== null) {
                $this->link($transaction, $income->commissionExpense, createdFromLine: true);
            }

            $this->markMatched($transaction);

            return $income;
        });
    }

    public function ignore(BankTransaction $transaction): void
    {
        $this->ensurePending($transaction);

        $transaction->update(['status' => BankTransactionStatus::Ignored]);
    }

    private function createPayment(BankTransaction $transaction, Receivable $receivable, int $amount): Payment
    {
        $payment = $receivable->payments()->create([
            'paid_on' => $transaction->booked_on,
            'amount' => $amount,
            'method' => PaymentMethod::Transfer,
            'notes' => 'Extrato bancário: '.$transaction->description,
        ]);

        $this->link($transaction, $payment, createdFromLine: true);

        return $payment;
    }

    /** Links a record that already exists; an unpaid expense becomes paid on the line date. */
    private function attach(BankTransaction $transaction, Payment|Expense $record): Payment|Expense
    {
        if ($record->bankLink()->exists()) {
            throw new DomainException('Este registo já está ligado a outra linha do extrato.');
        }

        $markedPaid = $record instanceof Expense && ! $record->is_paid;

        if ($markedPaid) {
            $record->update([
                'is_paid' => true,
                'paid_on' => $transaction->booked_on,
                'method' => $record->method ?? PaymentMethod::Transfer,
            ]);
        }

        $this->link($transaction, $record, markedPaid: $markedPaid);

        return $record;
    }

    private function markMatched(BankTransaction $transaction): void
    {
        $transaction->update(['status' => BankTransactionStatus::Matched]);
    }

    private function link(BankTransaction $transaction, Payment|Expense $record, bool $createdFromLine = false, bool $markedPaid = false): void
    {
        $transaction->links()->create([
            'linkable_type' => $record->getMorphClass(),
            'linkable_id' => $record->getKey(),
            'created_from_line' => $createdFromLine,
            'marked_paid' => $markedPaid,
        ]);
    }

    private function ensurePending(BankTransaction $transaction): void
    {
        if ($transaction->status !== BankTransactionStatus::Pending) {
            throw new DomainException('Esta linha do extrato já foi tratada.');
        }
    }

    private function ensureDebit(BankTransaction $transaction): void
    {
        $this->ensurePending($transaction);

        if ($transaction->is_credit) {
            throw new DomainException('Só uma saída de dinheiro pode ser uma despesa.');
        }
    }
}
