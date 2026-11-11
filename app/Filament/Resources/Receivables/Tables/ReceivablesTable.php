<?php

namespace App\Filament\Resources\Receivables\Tables;

use App\Enums\Language;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Filament\Support\OutstandingColumn;
use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\OneOffIncome;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use App\Support\RelativeDue;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\HtmlString;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class ReceivablesTable
{
    private const TYPES = [
        Project::class => 'Project',
        Retainer::class => 'Retainer',
        OneOffIncome::class => 'One-off income',
        DirectInvoiceSale::class => 'Direct invoice',
    ];

    /**
     * "A receber" as in the mock: the due date with how far off it is (in red once late), the
     * client with when they were last reminded, what the amount is for (and, under it, the
     * project or retainer and its amount), what is still open, the state, and "Receive" with a
     * reminder button on an open one. The total open sits at the foot; the export is in the page
     * header (ListReceivables).
     */
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            // Rows load right after the page; a skeleton shows meanwhile (filament-skeleton-loading).
            ->deferLoading()
            ->defaultSort('due_on')
            ->searchPlaceholder(__('Client or reference'))
            ->columns([
                TextColumn::make('due_on')
                    ->label(fn (): string => __('Due date'))
                    ->date()
                    ->sortable()
                    ->description(fn (Receivable $record): ?HtmlString => static::relativeDue($record)),
                TextColumn::make('client')
                    ->label(fn (): string => __('Client'))
                    ->state(fn (Receivable $record) => $record->client?->name)
                    ->description(fn (Receivable $record) => ($sentAt = $record->client?->lastPaymentReminderAt())
                        ? __('Reminder sent on :date', ['date' => $sentAt->format(__('m/d'))])
                        : null)
                    ->limit(24),
                TextColumn::make('description')
                    ->label(fn (): string => __('For'))
                    ->state(fn (Receivable $record): string => $record->description ?: $record->label)
                    ->description(fn (Receivable $record): string => static::billableLine($record))
                    ->url(fn (Receivable $record) => $record->billableUrl())
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->where('description', 'like', "%{$search}%")
                        ->orWhereHasMorph(
                            'billable',
                            array_keys(self::TYPES),
                            fn (Builder $query, string $type) => $query->whereRelation('client', 'name', 'like', "%{$search}%")
                                ->when($type !== DirectInvoiceSale::class, fn (Builder $query) => $query
                                    ->orWhere('code', 'like', "%{$search}%")
                                    ->orWhere('title', 'like', "%{$search}%")),
                        ))),
                OutstandingColumn::make('outstanding')
                    ->state(fn (Receivable $record): int => $record->projectedOutstanding())
                    ->summarize(Summarizer::make('sum')
                        ->money('EUR', divideBy: 100)
                        ->using(fn (QueryBuilder $query): int => (int) Receivable::query()
                            ->whereIn('id', $query->pluck('receivables.id'))
                            ->withOutstandingBalance()
                            ->get()
                            ->sum(fn (Receivable $receivable): int => $receivable->projectedOutstanding()))),
                TextColumn::make('status')
                    ->label(fn (): string => __('Status'))
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->label(__('Client'))
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $client) => $query->forClient((int) $client))),
                SelectFilter::make('billable_type')
                    ->label(__('Type'))
                    ->options(fn (): array => array_map(fn (string $label): string => __($label), self::TYPES)),
            ])
            ->recordActions([
                RecordPaymentAction::rowButton()
                    ->visible(fn (Receivable $record) => $record->projectedOutstanding() > 0),
                SendPaymentReminderAction::make()
                    ->iconButton()
                    ->color('gray')
                    ->tooltip(fn (Receivable $record) => blank($record->client?->email) ? __('The client has no email address') : __('Send reminder to client'))
                    ->visible(fn (Receivable $record) => $record->projectedOutstanding() > 0),
            ])
            ->emptyStateHeading(__('No receivables here'));
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->withFilename(fn () => str(__('To receive'))->slug().'-'.now()->format('Y-m-d'));
    }

    /**
     * "Retainer · €180.00", "Project DEMO-05 · €400.00" or, once part of it came in,
     * "Project DEMO-03 · €650.00 already received of €800.00".
     */
    protected static function billableLine(Receivable $record): string
    {
        $type = isset(self::TYPES[$record->billable_type]) ? __(self::TYPES[$record->billable_type]) : null;
        $what = trim($type.' '.($record->billable instanceof DirectInvoiceSale ? '' : ($record->billable?->code ?? '')));
        $received = $record->amount - $record->projectedOutstanding();

        $amount = $received > 0 && $record->projectedOutstanding() > 0
            ? __(':received already received of :amount', ['received' => Money::format($received), 'amount' => Money::format($record->amount)])
            : Money::format($record->amount);

        return implode(' · ', array_filter([$what, $amount]));
    }

    /** "in 5 days" or "13 days ago" under the due date while open, in red once it is late. */
    protected static function relativeDue(Receivable $record): ?HtmlString
    {
        if ($record->projectedOutstanding() <= 0) {
            return null;
        }

        $text = e(RelativeDue::describe($record->due_on));

        return new HtmlString(RelativeDue::isPast($record->due_on)
            ? '<span class="text-danger-600 dark:text-danger-400">'.$text.'</span>'
            : $text);
    }
}
