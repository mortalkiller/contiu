<?php

namespace App\Actions;

use App\Enums\CategoryType;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Enums\Seat;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Period;
use App\Support\TaxObligation;
use Carbon\CarbonImmutable;

/**
 * A tax obligation paid from the account (the reverse-charge VAT, the Segurança Social) recorded
 * as a paid expense today, so the bank statement line and the company account balance match. In
 * the art. 53.º exemption the self-assessed VAT is not deductible: it is a cost.
 */
final class RecordTaxPayment
{
    public const CATEGORY = 'Impostos e contribuições';

    /** Whether an obligation is a payment that can be recorded as an expense. */
    public static function applies(TaxObligation $obligation): bool
    {
        return in_array($obligation->kind, ['ss', 'vat-reverse'], true) && ($obligation->amount ?? 0) > 0;
    }

    public function handle(TaxObligation $obligation): Expense
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', substr($obligation->key, strpos($obligation->key, ':') + 1));
        $monthLabel = fn (CarbonImmutable $date) => mb_strtolower(Period::MONTHS[$date->month - 1]).' '.$date->year;

        [$supplier, $description] = match ($obligation->kind) {
            'vat-reverse' => ['Autoridade Tributária e Aduaneira', 'IVA autoliquidado · compras de '.$monthLabel($month)],
            default => ['Segurança Social', 'Segurança Social · contribuição de '.$monthLabel($month->subMonth())],
        };

        return Expense::create([
            'supplier_id' => Supplier::query()->firstOrCreate(['name' => $supplier], ['seat' => Seat::Portugal])->id,
            'category_id' => Category::query()->firstOrCreate(['type' => CategoryType::Expense, 'name' => self::CATEGORY])->id,
            'kind' => ExpenseKind::Overhead,
            'description' => $description,
            'spent_on' => today(),
            'net_amount' => (int) $obligation->amount,
            'vat_rate' => 0,
            'is_paid' => true,
            'paid_on' => today(),
            'method' => PaymentMethod::Transfer,
        ]);
    }
}
