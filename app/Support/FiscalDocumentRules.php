<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PortugueseVatRegion;
use App\Models\Article;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Receivable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * What each kind of fiscal document must have before it is saved. One place for every screen and
 * import, so the rules are the same wherever a document comes from.
 */
final class FiscalDocumentRules
{
    public const int MAX_LINES = 100;

    /** What issuing a credit note or a debit note does to what the client owes. */
    public const array RECEIVABLE_EFFECTS = [
        'credit_note' => ['apply', 'refund'],
        'debit_note' => ['add', 'separate'],
    ];

    /**
     * The document's data, checked, with the amounts worked out from its lines.
     *
     * @param  array<string, mixed>  $data
     * @return array{data: array<string, mixed>, totals: FiscalDocumentTotals}
     */
    public static function validate(array $data, ?FiscalDocument $draft = null): array
    {
        $type = FiscalDocumentType::tryFrom((string) ($data['type'] ?? '')) ?? FiscalDocumentType::Invoice;
        $validator = Validator::make($data, self::rules($type));
        $totals = null;

        $validator->after(function (ValidatorInstance $validator) use ($type, $draft, &$totals): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $totals = self::totals($type, $data);

            foreach (self::problems($type, $data, $totals, $draft) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });

        return ['data' => [...$validator->validate(), 'type' => $type], 'totals' => $totals];
    }

    /** The VAT rates a line may carry: none, or the ones of the region the account sells from. */
    public static function vatRates(): array
    {
        $region = app(FinanceSettings::class)->vatRegion() ?? PortugueseVatRegion::Mainland;

        return [0, ...array_values($region->rates())];
    }

    /**
     * Every VAT rate of Portugal's three regions, lowest first: a document recorded by hand may
     * come from a supplier in any of them.
     *
     * @return list<int>
     */
    public static function portugueseVatRates(): array
    {
        $rates = [0];

        foreach (PortugueseVatRegion::cases() as $region) {
            array_push($rates, ...array_values($region->rates()));
        }

        $rates = array_values(array_unique($rates));
        sort($rates);

        return $rates;
    }

    /**
     * The lines of a document recorded by hand (SaveFiscalDocument), as the domain takes them:
     * thousandths, cents and basis points.
     *
     * @return array<string, list<mixed>>
     */
    public static function recordedLineRules(FiscalDocumentType $type): array
    {
        return [
            'lines' => [$type->hasLines() ? 'array' : 'prohibited', 'max:'.self::MAX_LINES],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'lines.*.unit' => ['required', 'string', 'max:16'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'lines.*.vat_basis_points' => ['required', 'integer', Rule::in(self::portugueseVatRates())],
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function rules(FiscalDocumentType $type): array
    {
        $owned = fn (string $model): Exists => Rule::exists((new $model)->getTable(), 'id')->where('account_id', CurrentAccount::id());

        return [
            'type' => ['required', Rule::enum(FiscalDocumentType::class)],
            'document_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'client_id' => [$type === FiscalDocumentType::SimplifiedInvoice ? 'nullable' : 'required', 'integer', $owned(Client::class)],
            'party_name' => ['nullable', 'string', 'max:255'],
            'party_tax_id' => ['nullable', 'string', 'max:64'],
            'receivable_id' => ['nullable', 'integer', $owned(Receivable::class)],
            'original_id' => [$type->requiresOriginal() ? 'required' : 'prohibited', 'integer', $owned(FiscalDocument::class)],
            'receivable_effect' => ['nullable', Rule::in(self::RECEIVABLE_EFFECTS[$type->value] ?? [])],
            'withholding_basis_points' => ['nullable', 'integer', Rule::in([0, ...array_keys(FiscalTerms::WITHHOLDING_RATES)])],
            'vat_exemption_reason' => ['nullable', 'string', 'regex:/\AM[0-9]{2}\z/'],
            'client_reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => [in_array($type, [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote], true) ? 'required' : 'nullable', 'string', 'max:255'],

            'lines' => $type->hasLines() ? ['required', 'array', 'min:1', 'max:'.self::MAX_LINES] : ['prohibited'],
            'lines.*.article_id' => ['nullable', 'integer', $owned(Article::class)],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'lines.*.unit' => ['required', 'string', 'max:16'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'lines.*.discount_basis_points' => ['nullable', 'integer', 'between:0,10000'],
            'lines.*.vat_basis_points' => ['nullable', 'integer', Rule::in(self::vatRates())],

            'amount' => [$type === FiscalDocumentType::Receipt ? 'required' : 'prohibited', 'integer', 'min:1'],
            'payment' => [$type->settlesOnIssue() || $type === FiscalDocumentType::Receipt ? 'required' : 'nullable', 'array'],
            'payment.method' => ['required_with:payment', Rule::enum(PaymentMethod::class)],
            'payment.paid_on' => ['required_with:payment', 'date', 'before_or_equal:today'],
            'payment.account' => ['nullable', 'string', 'max:100'],

            'transport' => [$type === FiscalDocumentType::TransportGuide ? 'required' : 'nullable', 'array'],
            'transport.load.address' => ['required_with:transport', 'string', 'max:255'],
            'transport.load.postal_code' => ['required_with:transport', 'string', 'max:16'],
            'transport.load.city' => ['required_with:transport', 'string', 'max:100'],
            'transport.unload.address' => ['required_with:transport', 'string', 'max:255'],
            'transport.unload.postal_code' => ['required_with:transport', 'string', 'max:16'],
            'transport.unload.city' => ['required_with:transport', 'string', 'max:100'],
            'transport.starts_at' => ['required_with:transport', 'date'],
            'transport.plate' => ['nullable', 'string', 'max:16'],
        ];
    }

    /** @param array<string, mixed> $data */
    private static function totals(FiscalDocumentType $type, array $data): FiscalDocumentTotals
    {
        return $type === FiscalDocumentType::Receipt
            ? FiscalDocumentTotals::of([['quantity' => 1000, 'unit_price' => (int) $data['amount']]])
            : FiscalDocumentTotals::of($data['lines'], (int) ($data['withholding_basis_points'] ?? 0));
    }

    /**
     * What is wrong with the document as a whole, by field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function problems(FiscalDocumentType $type, array $data, FiscalDocumentTotals $totals, ?FiscalDocument $draft): array
    {
        $problems = [];
        $withholding = (int) ($data['withholding_basis_points'] ?? 0);

        if ($totals->total <= 0 && $type !== FiscalDocumentType::TransportGuide) {
            $problems['lines'] = __('The document needs a total above zero.');
        }

        if ($type->hasLines() && $totals->vat === 0 && $totals->net > 0 && blank($data['vat_exemption_reason'] ?? null)) {
            $problems['vat_exemption_reason'] = __('Say why no VAT is charged.');
        }

        if ($withholding > 0 && ! $type->countsAsBilling()) {
            $problems['withholding_basis_points'] = __('Only invoices and their notes carry IRS withholding.');
        } elseif ($withholding > 0 && $totals->withholding < FiscalTerms::MINIMUM_WITHHOLDING) {
            $problems['withholding_basis_points'] = __('The calculated withholding is below €25. Review the withholding exemption for this document.');
        }

        if ($type === FiscalDocumentType::SimplifiedInvoice) {
            $limit = FiscalTerms::SIMPLIFIED_INVOICE_LIMITS[app(FinanceSettings::class)->sellsGoods() ? 'goods' : 'services'];

            if ($totals->total > $limit) {
                $problems['lines'] = __('A simplified invoice goes up to :limit. Use an invoice above that.', ['limit' => Money::format($limit)]);
            }
        }

        if ($type->requiresOriginal()) {
            $problems = [...$problems, ...self::originalProblems($type, $data, $totals, $draft)];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function originalProblems(FiscalDocumentType $type, array $data, FiscalDocumentTotals $totals, ?FiscalDocument $draft): array
    {
        $original = FiscalDocument::query()->find($data['original_id']);

        if ($original === null || $original->status !== FiscalDocumentStatus::Active
            || $original->direction !== FiscalDocumentDirection::Issued || ! $original->type->isInvoice()) {
            return ['original_id' => __('Choose an issued invoice of this account.')];
        }

        if ($type !== FiscalDocumentType::CreditNote) {
            return [];
        }

        $credited = (int) FiscalDocument::query()
            ->where('type', FiscalDocumentType::CreditNote)
            ->where('status', '!=', FiscalDocumentStatus::Voided)
            ->when($draft !== null, fn ($query) => $query->whereKeyNot($draft->getKey()))
            ->whereIn('id', FiscalDocumentLink::query()->where('related_fiscal_document_id', $original->getKey())->select('fiscal_document_id'))
            ->sum('total_amount');

        return $totals->total > $original->total_amount - $credited
            ? ['lines' => __('The credit goes beyond what is left of the invoice (:left).', ['left' => Money::format($original->total_amount - $credited)])]
            : [];
    }
}
