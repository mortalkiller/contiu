<?php

namespace App\Exports;

use App\Support\ClientStatement;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Extrato do cliente as .xlsx: one row per statement entry, money as numbers (formatted as €).
 */
class ClientStatementExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison
{
    public function __construct(private readonly ClientStatement $statement) {}

    public function headings(): array
    {
        return ['Data', 'Descrição', 'Débito', 'Crédito', 'Saldo', 'Nota'];
    }

    public function array(): array
    {
        return $this->statement->entries()->map(fn (array $entry) => [
            $entry['date']->format('d/m/Y'),
            $entry['description'],
            $entry['debit'] > 0 ? $entry['debit'] : null,
            $entry['credit'] > 0 ? $entry['credit'] : null,
            $entry['balance'],
            $entry['is_future'] ? 'a vencer' : null,
        ])->all();
    }

    public function columnFormats(): array
    {
        $euro = '#,##0.00 [$€-816];-#,##0.00 [$€-816]';

        return ['C' => $euro, 'D' => $euro, 'E' => $euro];
    }

    public static function filename(ClientStatement $statement): string
    {
        return 'extrato-'.str($statement->client->name)->slug().'-'.today()->format('Y-m-d').'.xlsx';
    }
}
