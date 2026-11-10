<?php

namespace App\Support;

use DomainException;

/**
 * @phpstan-type ExpenseDocumentRow array{document_date: string, number: string, net_amount: int, vat_amount: int, total_amount: int}
 */
final class ExpenseDocumentCsv
{
    public const MAX_KILOBYTES = 256;

    public const MAX_ROWS = 100;

    public const HEADER = 'data;referencia;liquido;iva;total';

    /** @return list<ExpenseDocumentRow> */
    public function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_KILOBYTES * 1024) {
            throw new DomainException('O ficheiro CSV pode ter até 256 KB.');
        }

        if (! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw new DomainException('Guarda o ficheiro CSV com a codificação UTF-8.');
        }

        $stream = fopen('php://memory', 'w+');
        fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        rewind($stream);

        try {
            if (fgetcsv($stream, separator: ';', escape: '') !== explode(';', self::HEADER)) {
                throw new DomainException('O cabeçalho deve ser '.self::HEADER.'.');
            }

            $rows = [];
            $references = [];
            $line = 1;

            while (($fields = fgetcsv($stream, separator: ';', escape: '')) !== false) {
                $line++;

                if ($fields === [null]) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new DomainException('Cada ficheiro CSV pode ter até 100 faturas.');
                }

                $row = $this->parseRow(array_map(trim(...), array_map(strval(...), $fields)), $line);
                $reference = mb_strtolower($row['number']);

                if (isset($references[$reference])) {
                    throw new DomainException("Linha {$line}: a referência está repetida no ficheiro.");
                }

                $references[$reference] = true;
                $rows[] = $row;
            }

            return $rows !== [] ? $rows : throw new DomainException('O ficheiro não tem faturas.');
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string>  $fields
     * @return ExpenseDocumentRow
     */
    private function parseRow(array $fields, int $line): array
    {
        if (count($fields) !== 5) {
            throw new DomainException("Linha {$line}: são esperadas cinco colunas.");
        }

        [$date, $reference, $net, $vat, $total] = $fields;
        $date = $this->date($date);
        $net = $this->cents($net);
        $vat = $this->cents($vat);
        $total = $this->cents($total);

        if ($date === null || $reference === '' || mb_strlen($reference) > 255 || preg_match('/\p{C}/u', $reference)) {
            throw new DomainException("Linha {$line}: confirma a data e a referência da fatura.");
        }

        if ($net === null || $vat === null || $total === null || $total !== $net + $vat) {
            throw new DomainException("Linha {$line}: confirma os valores em euros e verifica se o líquido mais o IVA corresponde ao total.");
        }

        return [
            'document_date' => $date,
            'number' => $reference,
            'net_amount' => $net,
            'vat_amount' => $vat,
            'total_amount' => $total,
        ];
    }

    private function date(string $value): ?string
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts)) {
            [, $year, $month, $day] = $parts;
        } elseif (preg_match('#\A(\d{2})/(\d{2})/(\d{4})\z#', $value, $parts)) {
            [, $day, $month, $year] = $parts;
        } else {
            return null;
        }

        return checkdate((int) $month, (int) $day, (int) $year) ? "{$year}-{$month}-{$day}" : null;
    }

    private function cents(string $value): ?int
    {
        if (! preg_match('/\A(\d{1,10}|\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3}){1,3})(?:,(\d{1,2}))?\z/u', $value, $parts)) {
            return null;
        }

        $euros = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $parts[1]);

        return strlen($euros) <= 10 ? (int) $euros * 100 + (int) str_pad($parts[2] ?? '', 2, '0') : null;
    }
}
