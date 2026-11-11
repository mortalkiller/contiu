<?php

namespace App\Filament\MonthClose\Columns;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\MonthReview;
use Filament\Tables\Columns\TextColumn;

final class FiscalDocumentColumn
{
    public static function make(): TextColumn
    {
        return TextColumn::make('fiscal_reference')
            ->label(__('Tax document'))
            ->state(fn (Expense|Payment|Receivable $record): string => MonthReview::hasCompleteFiscalReference($record)
                ? $record->fiscal_reference
                : MonthReview::missingDocumentLabel($record))
            ->badge()
            ->color(fn (Expense|Payment|Receivable $record): string => MonthReview::hasCompleteFiscalReference($record) ? 'success' : 'warning')
            ->description(fn (Expense|Payment|Receivable $record): ?string => $record instanceof Payment && MonthReview::unconfirmedLegacyReference($record) !== null
                ? __('Previous reference awaiting confirmation · :reference', ['reference' => $record->invoice_number])
                : null);
    }
}
