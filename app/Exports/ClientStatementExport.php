<?php

namespace App\Exports;

use App\Enums\Language;
use App\Support\ClientStatement;
use App\Support\Money;
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
    private readonly Language $language;

    private readonly ClientStatement $statement;

    public function __construct(ClientStatement $statement)
    {
        $this->language = $statement->client->preferredLanguage();
        $this->statement = new ClientStatement($statement->client, $statement->from, $statement->to, $this->language->locale());
    }

    public function headings(): array
    {
        $locale = $this->language->locale();

        return [
            __('Date', [], $locale), __('Description', [], $locale), __('Debit', [], $locale),
            __('Credit', [], $locale), __('Balance', [], $locale), __('Note', [], $locale),
        ];
    }

    public function array(): array
    {
        return $this->statement->entries()->map(fn (array $entry) => [
            $entry['date']->format($this->language->dateFormat()),
            $entry['description'],
            // The spreadsheet gets euros (the statement works in cents).
            $entry['debit'] > 0 ? Money::toEuros($entry['debit']) : null,
            $entry['credit'] > 0 ? Money::toEuros($entry['credit']) : null,
            Money::toEuros($entry['balance']),
            $entry['is_future'] ? __('not yet due', [], $this->language->locale()) : null,
        ])->all();
    }

    public function columnFormats(): array
    {
        $currency = $this->language === Language::EnglishUnitedStates ? '[$€-409]' : '[$€-816]';
        $euro = "#,##0.00 {$currency};-#,##0.00 {$currency}";

        return ['C' => $euro, 'D' => $euro, 'E' => $euro];
    }

    public static function filename(ClientStatement $statement): string
    {
        return __('statement', [], $statement->client->preferredLanguage()->locale())
            .'-'.str($statement->client->name)->slug().'-'.today()->format('Y-m-d').'.xlsx';
    }
}
