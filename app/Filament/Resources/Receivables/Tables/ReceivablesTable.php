<?php

namespace App\Filament\Resources\Receivables\Tables;

use App\Enums\Language;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\OneOffIncome;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
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

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            // Rows load right after the page; a skeleton shows meanwhile (filament-skeleton-loading).
            ->deferLoading()
            ->headerActions([
                ExportAction::make()
                    ->label(__('Export to Excel'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => str(__('To receive'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label(__('Export selected'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => str(__('To receive'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('due_on')
                    ->label(fn (): string => __('Due date'))
                    ->date()
                    ->sortable()
                    ->description(fn (Receivable $record) => static::relativeDue($record)),
                TextColumn::make('client')
                    ->label(fn (): string => __('Client'))
                    ->state(fn (Receivable $record) => $record->client?->name)
                    ->description(fn (Receivable $record) => ($sentAt = $record->client?->lastPaymentReminderAt())
                        ? __('Reminder sent on :date', ['date' => $sentAt->format(__('m/d'))])
                        : null)
                    ->limit(28),
                TextColumn::make('billable.code')
                    ->label(fn (): string => __('For'))
                    ->description(fn (Receivable $record) => $record->description)
                    ->url(fn (Receivable $record) => $record->billableUrl())
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHasMorph(
                        'billable',
                        array_keys(self::TYPES),
                        fn (Builder $query, string $type) => $query->whereRelation('client', 'name', 'like', "%{$search}%")
                            ->when($type !== DirectInvoiceSale::class, fn (Builder $query) => $query
                                ->orWhere('code', 'like', "%{$search}%")
                                ->orWhere('title', 'like', "%{$search}%")),
                    )),
                TextColumn::make('billable_type')
                    ->label(fn (): string => __('Type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => isset(self::TYPES[$state]) ? __(self::TYPES[$state]) : $state)
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label(fn (): string => __('Amount'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('outstanding')
                    ->state(fn (Receivable $record): int => $record->projectedOutstanding())
                    ->label(fn (): string => __('Outstanding'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('medium')
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
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
                RecordPaymentAction::make()
                    ->button()
                    ->label(__('Receive payment'))
                    ->visible(fn (Receivable $record) => $record->projectedOutstanding() > 0),
                SendPaymentReminderAction::make()
                    ->iconButton()
                    ->tooltip(fn (Receivable $record) => blank($record->client?->email) ? __('The client has no email address') : __('Send reminder to client'))
                    ->visible(fn (Receivable $record) => $record->projectedOutstanding() > 0),
            ])
            ->emptyStateHeading(__('No receivables here'));
    }

    protected static function relativeDue(Receivable $record): ?string
    {
        if ($record->projectedOutstanding() <= 0) {
            return null;
        }

        $days = (int) today()->diffInDays($record->due_on, false);

        return match (true) {
            $days < 0 => trans_choice('{1} :count day ago|[2,*] :count days ago', abs($days)),
            $days === 0 => __('today'),
            default => trans_choice('{1} in :count day|[2,*] in :count days', $days),
        };
    }
}
