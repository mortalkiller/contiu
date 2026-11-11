<?php

namespace App\Support;

use App\Enums\FiscalDocumentType;
use DomainException;
use Illuminate\Support\Str;

/**
 * The received-invoices file exported from the Portal das Finanças (e-Fatura, "Consultar faturas
 * › Adquirente › Exportar"): one row per invoice, from every supplier, each with the issuer's
 * tax ID. The export reads like "Nº Documento / ATCUD";"NIF Emitente";"Data Emissão";"Tipo do
 * Documento";"Total";"IVA";"Base Tributável";"Situação", with amounts as "255,50 €"; the issuer
 * may come as a bare tax ID or as "tax ID - name". Columns are found by their heading, so a reordered or slightly renamed export still
 * reads; the separator (";", "," or tab) and the encoding (UTF-8 or Windows-1252) are detected.
 *
 * Invoices, simplified invoices and invoice-receipts are read; other documents (credit notes,
 * receipts) and cancelled ones are counted as skipped.
 *
 * @phpstan-type EFaturaRow array{document_date: string, number: string, net_amount: int, vat_amount: int, total_amount: int, atcud: ?string, party_tax_id: string, party_name: ?string, type: string}
 */
final class EFaturaCsv
{
    public const MAX_KILOBYTES = 1024;

    public const MAX_ROWS = 500;

    /** Heading keywords (accents and case ignored) for each column, the most specific first. */
    private const COLUMNS = [
        'issuer' => ['nif emitente', 'emitente', 'fornecedor', 'comerciante'],
        'number' => ['n fatura', 'no fatura', 'numero fatura', 'fatura atcud', 'n documento', 'no documento', 'numero documento'],
        'atcud' => ['atcud'],
        'type' => ['tipo'],
        'date' => ['data emissao', 'data'],
        'total' => ['total'],
        'vat' => ['iva'],
        'net' => ['base tributavel', 'valor tributavel', 'base'],
        'status' => ['situacao', 'estado'],
    ];

    /** @return array{rows: list<EFaturaRow>, skipped: int} */
    public function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_KILOBYTES * 1024) {
            throw new DomainException(__('The e-Fatura file can be up to 1 MB.'));
        }

        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $csv = mb_check_encoding($csv, 'UTF-8') ? $csv : mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');

        if (str_contains($csv, "\0")) {
            throw new DomainException(__('Choose the CSV file exported from e-Fatura.'));
        }

        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $separator = $this->separator($lines[0] ?? '');
        $columns = $this->columns(str_getcsv(array_shift($lines) ?? '', $separator, escape: ''));

        $rows = [];
        $skipped = 0;
        $seen = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = array_map(fn (?string $field): string => trim((string) $field), str_getcsv($line, $separator, escape: ''));
            $row = $this->row($fields, $columns, $index + 2);

            if ($row === null) {
                $skipped++;

                continue;
            }

            $key = $row['party_tax_id'].'|'.mb_strtolower($row['number']);

            if (isset($seen[$key])) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new DomainException(__('Each e-Fatura file can contain up to :count invoices. Export a shorter period.', ['count' => self::MAX_ROWS]));
            }

            $seen[$key] = true;
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new DomainException(__('The file contains no invoices, simplified invoices or invoice-receipts.'));
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private function separator(string $header): string
    {
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  list<?string>  $headings
     * @return array<string, int>
     */
    private function columns(array $headings): array
    {
        $normalized = array_map(fn (?string $heading): string => trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $heading))) ?? ''), $headings);
        $columns = [];

        foreach (self::COLUMNS as $name => $keywords) {
            foreach ($keywords as $keyword) {
                foreach ($normalized as $position => $heading) {
                    if (! in_array($position, $columns, true) && ($heading === $keyword || str_contains($heading, $keyword))) {
                        $columns[$name] = $position;

                        continue 3;
                    }
                }
            }
        }

        // The issued-invoices export has the buyer's tax ID ("NIF Adquirente") instead of the issuer's:
        // the right portal, the wrong side.
        if (! isset($columns['issuer']) && in_array('nif adquirente', $normalized, true)) {
            throw new DomainException(__('This is the export of the invoices you issued (it lists the buyer\'s tax ID). For expenses, export the invoices you received: in e-Fatura, the Purchaser view.'));
        }

        $required = ['issuer' => __('Issuer'), 'number' => __('Invoice number'), 'date' => __('Issue date'), 'total' => __('Total')];

        foreach ($required as $name => $label) {
            if (! isset($columns[$name])) {
                throw new DomainException(__('This does not look like an e-Fatura export: the :column column is missing.', ['column' => $label]));
            }
        }

        return $columns;
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, int>  $columns
     * @return EFaturaRow|null null for a document that is not read (another type, or cancelled)
     */
    private function row(array $fields, array $columns, int $line): ?array
    {
        $field = fn (string $name): string => isset($columns[$name]) ? ($fields[$columns[$name]] ?? '') : '';

        $status = Str::lower(Str::ascii($field('status')));
        $type = $this->type($field('type'), $field('number'));

        if (str_contains($status, 'anulad') || $type === null) {
            return null;
        }

        if (! preg_match('/(\d{9})\s*(?:-\s*(.+))?/u', $field('issuer'), $issuer)) {
            throw new DomainException(__('Line :line: the supplier\'s tax ID is missing.', ['line' => $line]));
        }

        [$number, $atcud] = array_pad(array_map(trim(...), preg_split('#\s+/\s+#', $field('number'), 2) ?: []), 2, null);
        $atcud = $field('atcud') !== '' ? $field('atcud') : $atcud;
        $date = $this->date($field('date'));
        $total = $this->cents($field('total'));
        $vat = $field('vat') === '' ? 0 : $this->cents($field('vat'));
        $net = $field('net') === '' ? ($total === null || $vat === null ? null : $total - $vat) : $this->cents($field('net'));

        if ($date === null || blank($number) || mb_strlen($number) > 255) {
            throw new DomainException(__('Line :line: check the invoice date and number.', ['line' => $line]));
        }

        if ($total === null || $vat === null || $net === null || abs($net + $vat - $total) > 1) {
            throw new DomainException(__('Line :line: check the amounts: the taxable amount plus VAT must equal the total.', ['line' => $line]));
        }

        return [
            'document_date' => $date,
            'number' => $number,
            'net_amount' => $total - $vat,
            'vat_amount' => $vat,
            'total_amount' => $total,
            'atcud' => blank($atcud) || $atcud === '0' ? null : $atcud,
            'party_tax_id' => $issuer[1],
            'party_name' => isset($issuer[2]) ? trim($issuer[2]) : null,
            'type' => $type->value,
        ];
    }

    /** By the "Tipo" column or, without one, by the series in the number (FT, FS, FR). */
    private function type(string $type, string $number): ?FiscalDocumentType
    {
        $type = Str::lower(Str::ascii($type));

        return match (true) {
            str_contains($type, 'recibo') && str_contains($type, 'fatura') => FiscalDocumentType::InvoiceReceipt,
            str_contains($type, 'fatura') && ! str_contains($type, 'nota') => FiscalDocumentType::Invoice,
            $type === '' && preg_match('/\A(FT|FS)\b/i', $number) === 1 => FiscalDocumentType::Invoice,
            $type === '' && preg_match('/\AFR\b/i', $number) === 1 => FiscalDocumentType::InvoiceReceipt,
            default => null,
        };
    }

    private function date(string $value): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $value, $parts)) {
            [, $year, $month, $day] = $parts;
        } elseif (preg_match('#\A(\d{2})[/-](\d{2})[/-](\d{4})#', $value, $parts)) {
            [, $day, $month, $year] = $parts;
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year) ? "{$year}-{$month}-{$day}" : null;
    }

    /** "1.234,56 €", "1 234,56", "61,50" or "61.50" in cents. */
    private function cents(string $value): ?int
    {
        $value = str_replace(['€', ' ', "\u{00A0}", "\u{202F}"], '', $value);

        if (preg_match('/\A-?\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?\z/', $value)) {
            $value = str_replace('.', '', $value);
        }

        $value = str_replace(',', '.', $value);

        if (! preg_match('/\A(-?)(\d{1,10})(?:\.(\d{1,2}))?\z/', $value, $parts)) {
            return null;
        }

        $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return $parts[1] === '-' ? -$cents : $cents;
    }
}
