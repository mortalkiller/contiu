<?php

namespace App\Modules\InvoiceReader;

use App\Enums\PortugueseVatRegion;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvoiceData
{
    public const string FINAL_CONSUMER_NIF = '999999990';

    public const array FIELDS = [
        'document_type', 'invoice_number', 'issue_date', 'due_date', 'atcud', 'currency',
        'supplier_name', 'supplier_nif', 'supplier_address', 'supplier_country',
        'customer_name', 'customer_nif', 'customer_address', 'customer_country',
        'net_amount', 'discount', 'taxable_amount', 'vat_amount', 'total_tax', 'gross_total', 'paid_amount', 'exemption_reason',
    ];

    public const array VAT_FIELDS = ['region', 'category', 'rate', 'taxable_amount', 'vat_amount', 'exemption_reason'];

    public const array ADDRESS_FIELDS = [
        'supplier' => ['supplier_street', 'supplier_door_number', 'supplier_postal_code', 'supplier_locality'],
        'customer' => ['customer_street', 'customer_door_number', 'customer_postal_code', 'customer_locality'],
    ];

    public const array LINE_FIELDS = ['description', 'reference', 'quantity', 'unit', 'unit_price', 'discount', 'vat_rate', 'line_total'];

    public const array REQUIRED = ['document_type', 'invoice_number', 'issue_date', 'supplier_name', 'supplier_nif', 'gross_total'];

    private const array DECIMALS = ['net_amount', 'discount', 'taxable_amount', 'vat_amount', 'total_tax', 'gross_total',
        'paid_amount', 'rate', 'quantity', 'unit_price', 'vat_rate', 'line_total'];

    public static function fieldLabel(string $field): string
    {
        $labels = [
            'document_type' => 'Document type',
            'invoice_number' => 'Invoice number',
            'issue_date' => 'Issue date',
            'due_date' => 'Due date',
            'atcud' => 'ATCUD',
            'currency' => 'Currency',
            'supplier_name' => 'Supplier name',
            'supplier_nif' => 'Supplier NIF',
            'supplier_address' => 'Supplier address',
            'supplier_street' => 'Street',
            'supplier_door_number' => 'Door number',
            'supplier_postal_code' => 'Postal code',
            'supplier_locality' => 'City',
            'supplier_country' => 'Supplier country',
            'customer_name' => 'Customer name',
            'customer_nif' => 'Customer NIF',
            'customer_address' => 'Customer address',
            'customer_street' => 'Street',
            'customer_door_number' => 'Door number',
            'customer_postal_code' => 'Postal code',
            'customer_locality' => 'City',
            'customer_country' => 'Customer country',
            'net_amount' => 'Net amount',
            'discount' => 'Discount amount',
            'taxable_amount' => 'Taxable amount',
            'vat_amount' => 'VAT amount',
            'total_tax' => 'Total tax',
            'gross_total' => 'Gross total',
            'paid_amount' => 'Paid amount',
            'exemption_reason' => 'Exemption reason',
            'vat' => 'VAT breakdown',
            'lines' => 'Line items',
            'region' => 'Region',
            'category' => 'Category',
            'rate' => 'Rate (%)',
            'description' => 'Description',
            'reference' => 'Reference',
            'quantity' => 'Quantity',
            'unit' => 'Unit',
            'unit_price' => 'Unit price',
            'vat_rate' => 'VAT rate',
            'line_total' => 'Line total',
            'standard' => 'Standard VAT rate',
            'reduced' => 'Reduced VAT rate',
            'intermediate' => 'Intermediate VAT rate',
            'exempt' => 'VAT exempt',
        ];

        return implode(' · ', array_map(
            fn (string $part): string => __($labels[$part] ?? $part),
            explode('.', $field),
        ));
    }

    /** IVA06. The QR issue date is the reference available to the benchmark. */
    public static function qrVatRate(?string $region, string $category, string $issueDate): ?string
    {
        if ($category === 'exempt') {
            return '0';
        }

        if (! self::validDate($issueDate) || $issueDate < '2021-01-01') {
            return null;
        }

        $territory = PortugueseVatRegion::tryFrom($region ?? '');
        $basisPoints = match (true) {
            $territory === PortugueseVatRegion::Madeira && $category === 'reduced' && $issueDate < '2024-10-01' => 500,
            $territory === PortugueseVatRegion::Azores && $category === 'standard' && $issueDate < '2021-07-01' => 1800,
            default => $territory?->basisPoints($category),
        };

        return $basisPoints === null ? null : (string) BigDecimal::ofUnscaledValue($basisPoints, 2)->strippedOfTrailingZeros();
    }

    public static function withQrVatRates(array $result, array $qr): array
    {
        if (empty($result['vat']) || empty($qr['vat'])) {
            return $result;
        }

        $rates = [];
        foreach ($qr['vat'] as $group) {
            $rates[$group['region'] ?? ''][$group['category']] = $group['rate']
                ?? self::qrVatRate($group['region'] ?? null, $group['category'], $qr['issue_date'] ?? '');
        }

        foreach ($result['vat'] as $index => $group) {
            $rate = $rates[$group['region'] ?? ''][$group['category'] ?? ''] ?? null;
            if ($rate !== null && ! self::available($group['rate'] ?? null)) {
                $result['vat'][$index]['rate'] = $rate;
            }
        }

        return $result;
    }

    public static function clean(array $data): array
    {
        $rules = [];
        foreach ([...self::FIELDS, ...self::ADDRESS_FIELDS['supplier'], ...self::ADDRESS_FIELDS['customer']] as $field) {
            $rules[$field] = ['nullable', 'string', 'max:500'];
        }
        foreach (['vat' => self::VAT_FIELDS, 'lines' => self::LINE_FIELDS] as $list => $fields) {
            $rules[$list] = ['nullable', 'array', 'max:200'];
            $rules[$list.'.*'] = ['array:'.implode(',', $fields)];
            foreach ($fields as $field) {
                $rules[$list.'.*.'.$field] = ['nullable', 'string', 'max:500'];
            }
        }

        $data = Validator::make($data, $rules)->validate();
        foreach (['vat', 'lines'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = array_values($data[$key]);
            }
        }

        return $data;
    }

    public static function verifiedValues(array $data): array
    {
        $data = array_filter(self::clean($data), self::available(...));

        foreach (['vat', 'lines'] as $key) {
            if (! isset($data[$key])) {
                continue;
            }
            $data[$key] = array_values(array_filter(array_map(
                fn (array $row): array => array_filter($row, self::available(...)),
                $data[$key],
            )));
            if ($data[$key] === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    public static function validateModelOutput(array $data): array
    {
        $keys = [...self::FIELDS, 'vat', 'lines'];
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw ValidationException::withMessages(['result' => 'The response is missing keys from the invoice schema.']);
        }

        foreach (['vat' => self::VAT_FIELDS, 'lines' => self::LINE_FIELDS] as $key => $fields) {
            if ($data[$key] === null) {
                continue;
            }
            if (! is_array($data[$key]) || ! array_is_list($data[$key])) {
                throw ValidationException::withMessages(['result' => 'The response does not match the invoice schema.']);
            }
            foreach ($data[$key] as $row) {
                if (! is_array($row) || count($row) !== count($fields) || array_diff($fields, array_keys($row)) !== []) {
                    throw ValidationException::withMessages(['result' => 'A VAT or line item entry does not match the invoice schema.']);
                }
            }
        }

        return self::clean($data);
    }

    public static function decimal(mixed $value): ?BigDecimal
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (! preg_match('/\A-?\d{1,14}(?:[.,]\d{1,8})?\z/', $value)) {
            return null;
        }

        return BigDecimal::of(str_replace(',', '.', $value));
    }

    public static function validDate(string $date): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function compare(mixed $actual, mixed $expected, string $field): bool
    {
        if (is_array($expected)) {
            if (! is_array($actual) || count($actual) !== count($expected)) {
                return false;
            }
            if ($field === 'vat') {
                // VAT groups can appear in any order. Match each group once.
                foreach ($expected as $group) {
                    $matched = false;
                    foreach ($actual as $index => $candidate) {
                        if (self::compare($candidate, $group, 'vat_group')) {
                            unset($actual[$index]);
                            $matched = true;
                            break;
                        }
                    }
                    if (! $matched) {
                        return false;
                    }
                }

                return true;
            }
            foreach ($expected as $key => $value) {
                if (self::available($value) && ! self::compare($actual[$key] ?? null, $value, (string) $key)) {
                    return false;
                }
            }

            return true;
        }

        if (in_array($field, self::DECIMALS, true)) {
            $expectedDecimal = self::decimal($expected);

            return $expectedDecimal !== null && (self::decimal($actual)?->isEqualTo($expectedDecimal) ?? false);
        }

        if (in_array($field, ['supplier_nif', 'customer_nif'], true)) {
            return self::normalizeNif($actual) === self::normalizeNif($expected);
        }

        return is_string($actual) && is_string($expected) && trim($actual) === trim($expected);
    }

    public static function available(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    public static function normalizeNif(mixed $value): ?string
    {
        return is_string($value) ? preg_replace('/^PT(?=[0-9])/i', '', preg_replace('/\s+/', '', $value)) : null;
    }

    public static function decimalFields(): array
    {
        return self::DECIMALS;
    }
}
