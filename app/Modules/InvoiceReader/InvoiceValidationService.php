<?php

namespace App\Modules\InvoiceReader;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class InvoiceValidationService
{
    public function review(array $result, array $ai, array $qr): array
    {
        $missing = array_values(array_filter(InvoiceData::REQUIRED, fn (string $key): bool => ! InvoiceData::available($result[$key] ?? null)));
        $warnings = $this->warnings($result);
        if ($ai !== [] && $ai !== $result) {
            $warnings = array_values(array_unique([...$warnings, ...$this->warnings($ai)]));
        }
        $differences = [];
        foreach ($qr as $key => $expected) {
            if ($key !== 'vat' && InvoiceData::available($expected) && InvoiceData::available($ai[$key] ?? null)
                && ! InvoiceData::compare($ai[$key], $expected, $key)) {
                $differences[$key] = ['qr' => $expected, 'ai' => $ai[$key]];
            }
        }

        if (! empty($qr['vat']) && ! empty($ai['vat'])) {
            $qrVat = $this->summedVat($qr['vat']);
            $aiVat = $this->summedVat($ai['vat']);
            foreach ($qrVat as $key => $amount) {
                if ($amount !== null && $aiVat[$key] !== null && ! $amount->isEqualTo($aiVat[$key])) {
                    $differences["vat.{$key}"] = ['qr' => (string) $amount, 'ai' => (string) $aiVat[$key]];
                }
            }

            foreach ($qr['vat'] as $qrGroup) {
                foreach ($ai['vat'] as $aiGroup) {
                    if (blank($qrGroup['region']) || ($aiGroup['region'] ?? null) !== $qrGroup['region']
                        || ($aiGroup['category'] ?? null) !== $qrGroup['category']) {
                        continue;
                    }

                    foreach (['taxable_amount', 'vat_amount'] as $key) {
                        if (InvoiceData::available($qrGroup[$key]) && InvoiceData::available($aiGroup[$key] ?? null)
                            && ! InvoiceData::compare($aiGroup[$key], $qrGroup[$key], $key)) {
                            $differences["vat.{$qrGroup['region']}.{$qrGroup['category']}.{$key}"] = ['qr' => $qrGroup[$key], 'ai' => $aiGroup[$key]];
                        }
                    }
                }
            }
        }

        return compact('missing', 'warnings', 'differences');
    }

    public static function warningLabel(string $warning): string
    {
        if (preg_match('/^Invalid date in (issue_date|due_date)\.$/', $warning, $matches)) {
            return __('Invalid date in :field.', ['field' => InvoiceData::fieldLabel($matches[1])]);
        }

        if (preg_match('/^Invalid decimal in (document|vat|line) (\d+) \(([a-z_]+)\)\.$/', $warning, $matches)) {
            $section = match ($matches[1]) {
                'document' => __('Document'),
                'vat' => __('VAT breakdown'),
                'line' => __('Invoice line'),
            };

            return __('Invalid number in :section :number (:field).', [
                'section' => $section,
                'number' => $matches[2],
                'field' => InvoiceData::fieldLabel($matches[3]),
            ]);
        }

        if (preg_match('/^VAT group (\d+) differs from its base multiplied by the rate\.$/', $warning, $matches)) {
            return __('VAT group :number differs from its base multiplied by the rate.', ['number' => $matches[1]]);
        }

        return __($warning);
    }

    public function warnings(array $data): array
    {
        $warnings = [];
        foreach (['supplier', 'customer'] as $party) {
            $nif = InvoiceData::normalizeNif($data[$party.'_nif'] ?? null);
            $country = $data[$party.'_country'] ?? 'PT';
            if ($nif !== null && $nif !== '' && $country === 'PT'
                && ! ($party === 'customer' && $nif === InvoiceData::FINAL_CONSUMER_NIF) && ! $this->validNif($nif)) {
                $warnings[] = "Invalid Portuguese {$party} NIF checksum.";
            }
        }

        foreach (['issue_date', 'due_date'] as $key) {
            if (! empty($data[$key]) && ! InvoiceData::validDate($data[$key])) {
                $warnings[] = "Invalid date in {$key}.";
            }
        }
        if (! empty($data['due_date']) && ! empty($data['issue_date']) && InvoiceData::validDate($data['due_date'])
            && InvoiceData::validDate($data['issue_date']) && $data['due_date'] < $data['issue_date']) {
            $warnings[] = 'The due date precedes the issue date.';
        }

        $sections = ['document' => [$data], 'vat' => $data['vat'] ?? [], 'line' => $data['lines'] ?? []];
        foreach ($sections as $section => $rows) {
            foreach ($rows as $index => $row) {
                foreach (array_intersect(array_keys($row), InvoiceData::decimalFields()) as $key) {
                    if (InvoiceData::available($row[$key]) && InvoiceData::decimal($row[$key]) === null) {
                        $warnings[] = "Invalid decimal in {$section} ".($index + 1)." ({$key}).";
                    }
                }
            }
        }

        $net = InvoiceData::decimal($data['net_amount'] ?? null);
        $tax = InvoiceData::decimal($data['total_tax'] ?? $data['vat_amount'] ?? null);
        $gross = InvoiceData::decimal($data['gross_total'] ?? null);
        if ($net !== null && $tax !== null && $gross !== null && $this->mismatch($net->plus($tax), $gross)) {
            $warnings[] = 'Net amount plus total tax differs from the gross total.';
        }

        $vat = InvoiceData::decimal($data['vat_amount'] ?? null);
        $taxable = InvoiceData::decimal($data['taxable_amount'] ?? null);
        $breakdown = $this->summedVat($data['vat'] ?? []);
        if ($vat !== null && $breakdown['vat_amount'] !== null && $this->mismatch($breakdown['vat_amount'], $vat)) {
            $warnings[] = 'VAT groups do not add up to the reported VAT amount.';
        }
        if ($taxable !== null && $breakdown['taxable_amount'] !== null && $this->mismatch($breakdown['taxable_amount'], $taxable)) {
            $warnings[] = 'VAT group bases do not add up to the reported taxable amount.';
        }

        foreach ($data['vat'] ?? [] as $index => $row) {
            $base = InvoiceData::decimal($row['taxable_amount'] ?? null);
            $rate = InvoiceData::decimal($row['rate'] ?? null);
            $amount = InvoiceData::decimal($row['vat_amount'] ?? null);
            if ($base !== null && $rate !== null && $amount !== null
                && $this->mismatch($base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp), $amount)) {
                $warnings[] = 'VAT group '.($index + 1).' differs from its base multiplied by the rate.';
            }
        }

        return $warnings;
    }

    private function mismatch(BigDecimal $actual, BigDecimal $expected): bool
    {
        return $actual->minus($expected)->abs()->isGreaterThan('0.01');
    }

    private function summedVat(array $rows): array
    {
        $totals = [];
        foreach (['taxable_amount', 'vat_amount'] as $key) {
            $totals[$key] = $rows === [] ? null : BigDecimal::zero();
            foreach ($rows as $row) {
                $value = InvoiceData::decimal($row[$key] ?? null);
                if ($key === 'vat_amount' && ($row['category'] ?? null) === 'exempt' && $value === null) {
                    continue;
                }
                if ($value === null) {
                    $totals[$key] = null;
                    break;
                }
                $totals[$key] = $totals[$key]->plus($value);
            }
        }

        return $totals;
    }

    private function validNif(string $nif): bool
    {
        if (! preg_match('/\A[1-9]\d{8}\z/', $nif)) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 8; $index++) {
            $sum += (int) $nif[$index] * (9 - $index);
        }

        $checkDigit = 11 - $sum % 11;

        return ($checkDigit >= 10 ? 0 : $checkDigit) === (int) $nif[8];
    }
}
