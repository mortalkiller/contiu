<?php

namespace App\Actions;

use App\Enums\CategoryType;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\Category;
use App\Models\Expense;
use App\Models\OneOffIncome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Amounts in cents. Records a receita avulsa: the income at its gross amount (what the fatura-recibo says) and, when a
 * platform kept a commission, that commission as a paid expense — rendimento pelo bruto, comissão
 * como gasto.
 */
class RecordOneOffIncome
{
    public const COMMISSION_CATEGORY = 'Comissões';

    /**
     * @param  array{received_on: string|\DateTimeInterface, title: string, gross_amount: int, category_id?: ?int, commission?: ?int, commission_supplier_id?: ?int, invoice_number?: ?string, tax_exempt?: ?bool, method?: PaymentMethod|string|null, client_id?: ?int, notes?: ?string}  $data
     */
    public function handle(array $data): OneOffIncome
    {
        $receivedOn = Carbon::parse($data['received_on'])->startOfDay();
        $gross = (int) $data['gross_amount'];
        $commission = (int) ($data['commission'] ?? 0);

        return DB::transaction(function () use ($data, $receivedOn, $gross, $commission): OneOffIncome {
            $income = OneOffIncome::create([
                'code' => $this->nextCode($receivedOn),
                'client_id' => $data['client_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'business_line' => OneOffIncome::BUSINESS_LINE,
                'title' => $data['title'],
                'received_on' => $receivedOn,
                'net_amount' => $gross,
                'notes' => $data['notes'] ?? null,
            ]);

            $receivable = $income->receivables()->create([
                'description' => $data['title'],
                'amount' => $gross,
                'due_on' => $receivedOn,
            ]);

            $receivable->payments()->create([
                'paid_on' => $receivedOn,
                'amount' => $gross,
                'method' => $data['method'] ?? PaymentMethod::Transfer,
                'invoice_number' => $data['invoice_number'] ?? null,
                'tax_exempt' => $data['tax_exempt'] ?? null, // null → the default from the settings
            ]);

            if ($commission > 0) {
                $expense = Expense::create([
                    'supplier_id' => $data['commission_supplier_id'],
                    'category_id' => Category::firstOrCreate(['type' => CategoryType::Expense, 'name' => self::COMMISSION_CATEGORY])->id,
                    'kind' => ExpenseKind::Overhead,
                    'description' => 'Comissão · '.$data['title'],
                    'spent_on' => $receivedOn,
                    'net_amount' => $commission,
                    'vat_rate' => 0,
                    'is_paid' => true,
                    'paid_on' => $receivedOn,
                    'method' => $data['method'] ?? PaymentMethod::Transfer,
                ]);

                $income->update(['commission_expense_id' => $expense->id]);
            }

            return $income;
        });
    }

    /** Numbered within the current account (the query is scoped), so each account starts at 1. */
    private function nextCode(Carbon $date): string
    {
        $prefix = 'RA-'.$date->format('Ymd').'-';

        $last = OneOffIncome::query()->where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.($last + 1);
    }
}
