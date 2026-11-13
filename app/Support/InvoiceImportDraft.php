<?php

namespace App\Support;

use App\Enums\FiscalDocumentType;
use App\Modules\InvoiceReader\InvoiceData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What was read from an invoice, turned into the app's own terms: amounts in cents, VAT as the
 * rates an expense can carry, the document type as the app names it. This is what the person
 * confirms or corrects on "Importar faturas".
 *
 * @phpstan-type DraftRate array{rate: string, net: int, vat: int}
 * @phpstan-type DraftLine array{description: string, quantity: string, unit_price: ?int, rate: ?string, total: ?int}
 * @phpstan-type Draft array{type: ?string, number: ?string, date: ?string, due_date: ?string, atcud: ?string, supplier_name: ?string, supplier_nif: ?string, customer_name: ?string, customer_nif: ?string, net: ?int, vat: ?int, total: ?int, rates: list<DraftRate>, lines: list<DraftLine>, paid: bool, exact: list<string>}
 */
final class InvoiceImportDraft
{
    /** The documents that are a purchase: the others (credit notes, receipts, quotes) are not imported here. */
    private const array TYPES = [
        'FT' => FiscalDocumentType::Invoice,
        'FR' => FiscalDocumentType::InvoiceReceipt,
        'FS' => FiscalDocumentType::SimplifiedInvoice,
    ];

    /** Document codes and words that say the file is something other than an invoice. */
    private const string NOT_AN_INVOICE = '/\A(?:NC|ND|RC|RG|PF|OR|GT|GR|GA|GC|GD|FP|NE|CM|CC|FC|FO|OU)\z|nota de cr|nota de d|recibo(?! verde)|or[çc]amento|pr[óo][- ]?forma|guia de/iu';

    /**
     * @param  array<string, mixed>  $result  the reading, as InvoiceData names its fields
     * @param  array<string, mixed>  $qr  what the fiscal QR code said, exact by nature
     * @return Draft|null null when the document is not an invoice
     */
    public static function fromReading(array $result, array $qr): ?array
    {
        $type = self::type($result['document_type'] ?? null);
        if ($type === false) {
            return null;
        }

        $total = self::cents($result['gross_total'] ?? null);
        $vat = self::cents($result['total_tax'] ?? null) ?? self::cents($result['vat_amount'] ?? null);
        $net = self::cents($result['net_amount'] ?? null) ?? self::cents($result['taxable_amount'] ?? null);
        $rates = self::rates($result['vat'] ?? null);

        // The totals printed on the invoice win; what is missing comes from the VAT table or from the other two.
        $net ??= $rates !== [] ? array_sum(array_column($rates, 'net')) : null;
        $vat ??= $rates !== [] ? array_sum(array_column($rates, 'vat')) : null;
        [$net, $vat, $total] = self::complete($net, $vat, $total);

        // One rate, or a VAT table that does not add up to the totals: the totals stand, at the rate that fits them.
        if ($net !== null && $vat !== null && (count($rates) < 2
            || array_sum(array_column($rates, 'net')) !== $net || array_sum(array_column($rates, 'vat')) !== $vat)) {
            $rates = [['rate' => count($rates) === 1 ? $rates[0]['rate'] : self::rateFor($net, $vat), 'net' => $net, 'vat' => $vat]];
        }

        $date = self::date($result['issue_date'] ?? null);
        $paidAmount = self::cents($result['paid_amount'] ?? null);

        return [
            'type' => $type?->value,
            'number' => self::text($result['invoice_number'] ?? null, 100),
            'date' => $date,
            'due_date' => self::date($result['due_date'] ?? null),
            'atcud' => self::text($result['atcud'] ?? null, 70),
            'supplier_name' => self::text($result['supplier_name'] ?? null, 255),
            'supplier_nif' => FiscalTerms::nif(InvoiceData::normalizeNif($result['supplier_nif'] ?? null)),
            'customer_name' => self::text($result['customer_name'] ?? null, 255),
            'customer_nif' => FiscalTerms::nif(InvoiceData::normalizeNif($result['customer_nif'] ?? null)),
            'net' => $net,
            'vat' => $vat,
            'total' => $total,
            'rates' => $rates,
            'lines' => self::lines($result['lines'] ?? null),
            'paid' => ($type?->settlesOnIssue() ?? false) || ($paidAmount !== null && $total !== null && $paidAmount >= $total),
            'exact' => self::exact($qr),
        ];
    }

    /**
     * A draft as the person typed it: the same shape, held to the same rules.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $changes
     * @return Draft
     */
    public static function corrected(array $draft, array $changes): array
    {
        $draft = [...$draft, ...array_intersect_key($changes, array_flip(['number', 'date', 'supplier_name', 'supplier_nif', 'rates', 'lines', 'type']))];
        $draft['net'] = array_sum(array_column($draft['rates'], 'net'));
        $draft['vat'] = array_sum(array_column($draft['rates'], 'vat'));
        $draft['total'] = $draft['net'] + $draft['vat'];

        return $draft;
    }

    /** Whether the amounts read agree with each other: the base plus the VAT is the total. */
    public static function adds(array $draft): bool
    {
        return isset($draft['net'], $draft['vat'], $draft['total']) && $draft['total'] === $draft['net'] + $draft['vat'];
    }

    /** Whether an expense at this rate gives the VAT the invoice shows, to the cent. */
    public static function rateIsExact(array $rate): bool
    {
        return Money::vat((int) $rate['net'], $rate['rate']) === (int) $rate['vat'];
    }

    /** The official rate whose VAT on this base is the closest to the VAT read. */
    public static function rateFor(int $net, int $vat): string
    {
        $best = '0.0000';
        $distance = PHP_INT_MAX;

        foreach (array_keys(Money::vatOptions()) as $rate) {
            $gap = abs(Money::vat($net, $rate) - $vat);
            if ($gap < $distance) {
                [$best, $distance] = [$rate, $gap];
            }
        }

        return $best;
    }

    /** "23", "23.00" or "0.23" as one of the rates an expense can carry, or null. */
    public static function rate(mixed $value): ?string
    {
        $decimal = InvoiceData::decimal(is_string($value) ? rtrim(trim($value), '% ') : null);
        if ($decimal === null || $decimal->isNegative()) {
            return null;
        }

        $basisPoints = $decimal->isLessThan(1) && ! $decimal->isZero()
            ? $decimal->multipliedBy(10000)
            : $decimal->multipliedBy(100);
        $key = sprintf('0.%04d', $basisPoints->toScale(0, RoundingMode::HalfUp)->toInt());

        return array_key_exists($key, Money::vatOptions()) ? $key : null;
    }

    /** A decimal string as read ("12.30") in cents, or null. */
    public static function cents(mixed $value): ?int
    {
        $decimal = InvoiceData::decimal($value);

        return $decimal === null || $decimal->abs()->isGreaterThan(BigDecimal::of('9999999999'))
            ? null
            : $decimal->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** @return FiscalDocumentType|false|null false when it is another kind of document, null when unknown */
    private static function type(mixed $value): FiscalDocumentType|false|null
    {
        $value = is_string($value) ? trim($value) : '';
        $code = mb_strtoupper($value);

        if (isset(self::TYPES[$code])) {
            return self::TYPES[$code];
        }

        return match (true) {
            $value === '' => null,
            preg_match('/fatura[- ]recibo|factura[- ]recibo/iu', $value) === 1 => FiscalDocumentType::InvoiceReceipt,
            preg_match(self::NOT_AN_INVOICE, $value) === 1 => false,
            preg_match('/simplificada/iu', $value) === 1 => FiscalDocumentType::SimplifiedInvoice,
            preg_match('/\Afa[c]?tura|\Ainvoice/iu', $value) === 1 => FiscalDocumentType::Invoice,
            default => null,
        };
    }

    /**
     * @return array{?int, ?int, ?int} net, VAT and total, the third worked out from the other two
     */
    private static function complete(?int $net, ?int $vat, ?int $total): array
    {
        return match (true) {
            $total !== null && $vat !== null && $net === null => [$total - $vat, $vat, $total],
            $total !== null && $net !== null && $vat === null => [$net, $total - $net, $total],
            $net !== null && $vat !== null && $total === null => [$net, $vat, $net + $vat],
            default => [$net, $vat, $total],
        };
    }

    /**
     * The VAT table of the invoice, one entry per rate.
     *
     * @return list<DraftRate>
     */
    private static function rates(mixed $groups): array
    {
        $rates = [];

        foreach (is_array($groups) ? $groups : [] as $group) {
            $net = self::cents($group['taxable_amount'] ?? null);
            $vat = self::cents($group['vat_amount'] ?? null) ?? (($group['category'] ?? null) === 'exempt' ? 0 : null);
            if ($net === null || $vat === null || $net < 0 || $vat < 0) {
                continue;
            }

            $rate = self::rate($group['rate'] ?? null) ?? self::rateFor($net, $vat);
            $rates[$rate] = ['rate' => $rate, 'net' => ($rates[$rate]['net'] ?? 0) + $net, 'vat' => ($rates[$rate]['vat'] ?? 0) + $vat];
        }

        ksort($rates);

        return array_values($rates);
    }

    /**
     * @return list<DraftLine>
     */
    private static function lines(mixed $lines): array
    {
        $read = [];

        foreach (is_array($lines) ? array_slice($lines, 0, 100) : [] as $line) {
            $description = self::text($line['description'] ?? null, 255);
            if ($description === null) {
                continue;
            }

            $read[] = [
                'description' => $description,
                'quantity' => (string) (InvoiceData::decimal($line['quantity'] ?? null)?->strippedOfTrailingZeros() ?? '1'),
                'unit_price' => self::cents($line['unit_price'] ?? null),
                'rate' => self::rate($line['vat_rate'] ?? null),
                'total' => self::cents($line['line_total'] ?? null),
            ];
        }

        return $read;
    }

    /**
     * The fields the fiscal QR code gave: those need no checking against the paper.
     *
     * @return list<string>
     */
    private static function exact(array $qr): array
    {
        return array_keys(array_filter([
            'supplier_nif' => $qr['supplier_nif'] ?? null,
            'customer_nif' => $qr['customer_nif'] ?? null,
            'type' => $qr['document_type'] ?? null,
            'date' => $qr['issue_date'] ?? null,
            'number' => $qr['invoice_number'] ?? null,
            'atcud' => $qr['atcud'] ?? null,
            'vat' => $qr['total_tax'] ?? null,
            'total' => $qr['gross_total'] ?? null,
            'rates' => $qr['vat'] ?? null,
        ], InvoiceData::available(...)));
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && InvoiceData::validDate(trim($value)) ? trim($value) : null;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
