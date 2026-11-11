<?php

namespace App\Filament\Resources\Payments;

use App\Actions\ManageCustomerAdvance;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\Widgets\PaymentsSummary;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Phosphor::HandCoins;

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments');
    }

    public static function getNavigationGroup(): string
    {
        return __('Revenue');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['advanceClient', 'receivable.billable.client', 'allocations.receivable.billable']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(2)->components([
                DatePicker::make('paid_on')->label(__('Date'))->native(false)->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())->required(),
                MoneyInput::make('amount')->label(__('Payment amount'))->required()->disabled(fn (?Payment $record): bool => (bool) $record?->is_split),
                ToggleButtons::make('method')->label(__('Method'))->options(PaymentMethod::class)->inline()->required()->columnSpanFull(),
                Toggle::make('tax_exempt')
                    ->label(__('Tax exempt'))
                    ->helperText(__('Excluded from tax estimates (VAT, Social Security and personal income tax).'))
                    ->columnSpanFull(),
                TextInput::make('invoice_number')->label(__('Invoice / receipt no.'))->columnSpanFull(),
                Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
                ReconciliationColumns::section(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            // Rows load right after the page; a skeleton shows meanwhile (filament-skeleton-loading).
            ->deferLoading()
            ->searchPlaceholder(__('Client, reference or invoice'))
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('paid_on')->label(fn (): string => __('Date'))->date()->sortable(),
                TextColumn::make('client')
                    ->label(fn (): string => __('Client'))
                    ->state(fn (Payment $record) => $record->client?->name)
                    ->limit(28),
                TextColumn::make('receivable.billable.code')
                    ->label(fn (): string => __('For'))
                    // As in the mock: what the payment was for, and under it the project or retainer.
                    ->state(fn (Payment $record): ?string => match (true) {
                        $record->isAdvance() => __('Advance payment'),
                        $record->is_split => __('Split payment'),
                        default => $record->receivable?->description ?: $record->receivable?->billable?->code,
                    })
                    ->description(fn (Payment $record): ?string => $record->is_split || $record->isAdvance()
                        ? $record->receivableLabels()
                        : collect([$record->receivable?->billable?->code, $record->receivable?->billable?->title])->filter()->implode(' · '))
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->whereRelation('advanceClient', 'name', 'like', "%{$search}%")
                        ->orWhereHas('allocations.receivable', fn (Builder $query) => $query->whereHasMorph(
                            'billable', '*', fn (Builder $query, string $type) => $query
                                ->whereRelation('client', 'name', 'like', "%{$search}%")
                                ->when($type !== DirectInvoiceSale::class, fn (Builder $query) => $query->orWhere('code', 'like', "%{$search}%")),
                        )))),
                TextColumn::make('method')->label(fn (): string => __('Method'))->badge()->color('gray'),
                IconColumn::make('tax_exempt')
                    ->label(fn (): string => __('Exempt payment'))
                    ->boolean()
                    ->trueIcon(Phosphor::ShieldCheck)
                    ->falseIcon(null)
                    ->tooltip(fn (Payment $record) => $record->tax_exempt ? __('Excluded from tax estimates') : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('invoice_number')->label(fn (): string => __('Invoice'))->placeholder('—')->searchable(),
                TextColumn::make('amount')
                    ->label(fn (): string => __('Amount'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('medium')
                    ->summarize(Sum::make()->money('EUR', divideBy: 100)),
                ReconciliationColumns::paymentColumn()->label(fn (): string => __('Bank')),
            ])
            ->filters([
                ReconciliationColumns::paymentFilter(),
                SelectFilter::make('method')->label(__('Method'))->options(PaymentMethod::class)->multiple(),
                SelectFilter::make('client')
                    ->label(__('Client'))
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $client) => $query
                        ->where(fn (Builder $query) => $query->where('advance_client_id', $client)
                            ->orWhereHas('allocations', fn (Builder $query) => $query->whereIn('receivable_id', Receivable::query()->forClient((int) $client)->select('id')))))),
                SelectFilter::make('year')
                    ->label(__('Year'))
                    ->options(fn () => Payment::query()->pluck('paid_on')->map->year->unique()->sortDesc()->mapWithKeys(fn ($year) => [$year => $year])->all())
                    ->default((string) today()->year)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('paid_on', $year))),
                SelectFilter::make('month')
                    ->label(__('Month'))
                    ->options(collect(range(1, 12))->mapWithKeys(fn ($month) => [$month => ucfirst(today()->startOfMonth()->month($month)->locale(app()->getLocale())->translatedFormat('F'))])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $month) => $query->whereMonth('paid_on', $month))),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modalHeading(__('Edit payment'))->visible(fn (Payment $record): bool => ! $record->isAdvance()),
                    Action::make('applyCustomerAdvance')
                        ->label(__('Allocate advance to a charge'))
                        ->visible(fn (Payment $record): bool => $record->isAdvance() && $record->allocations->isEmpty())
                        ->authorize('update')
                        ->disabled(fn (): bool => CurrentAccount::readOnly())
                        ->schema([
                            Select::make('receivable_id')->label(__('Charge'))->searchable()->required()
                                ->options(fn (Payment $record): array => Receivable::query()->forClient($record->advance_client_id)
                                    ->excludingCancelled()->open()->with(['payments', 'billable'])->orderBy('due_on')->get()
                                    ->filter(fn (Receivable $receivable): bool => $receivable->outstanding >= $record->amount)
                                    ->mapWithKeys(fn (Receivable $receivable): array => [$receivable->id => $receivable->label.' · '.Money::format($receivable->outstanding, locale: app()->getLocale())])->all()),
                            DatePicker::make('allocated_on')->label(__('Allocation date'))->default(today())->maxDate(today())->required(),
                        ])
                        ->action(fn (Payment $record, array $data, ManageCustomerAdvance $service) => $service->apply($record->id, (int) $data['receivable_id'], $data['allocated_on'])),
                    ReconciliationColumns::detailsAction(),
                    static::historyAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No payments'))
            ->emptyStateDescription(__('Use "Record payment" to record incoming payments.'));
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->withFilename(fn () => __('payments').'-'.now()->format('Y-m-d'));
    }

    public static function getWidgets(): array
    {
        return [PaymentsSummary::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePayments::route('/'),
        ];
    }

    /**
     * Payments are edited in modals (no view page for a relation manager), so their audit trail
     * opens in a slide-over instead of a "Histórico" tab.
     */
    public static function historyAction(): Action
    {
        $labels = ['paid_on' => __('Date'), 'amount' => __('Payment amount'), 'method' => __('Method'), 'invoice_number' => __('Invoice'), 'notes' => __('Notes'), 'receivable_id' => __('Receivable')];
        $events = ['created' => __('Created'), 'updated' => __('Updated'), 'deleted' => __('Deleted'), 'restored' => __('Restored')];

        return Action::make('history')
            ->label(__('History'))
            ->icon(Phosphor::ClockCounterClockwise)
            ->color('gray')
            ->slideOver()
            ->modalHeading(__('Payment history'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->visible(fn (Payment $record): bool => (bool) auth()->user()?->can('audit', $record))
            ->schema(fn (Payment $record): array => [
                RepeatableEntry::make('history')
                    ->hiddenLabel()
                    ->state(fn () => $record->audits()->with('user')->latest()->get()->map(fn ($audit) => [
                        'when' => $audit->created_at->format(Language::resolve(app()->getLocale())->dateFormat().' H:i'),
                        'who' => $audit->user?->name ?? __('System'),
                        'event' => $events[$audit->event] ?? $audit->event,
                        'changes' => collect($audit->new_values)
                            ->except(['created_at', 'updated_at', 'id'])
                            ->map(function ($new, $field) use ($labels, $audit): string {
                                // Amounts are stored in cents; the history shows euros like the rest of the app.
                                $show = fn ($value) => $value === null ? '—' : ($field === 'amount' ? Money::format((int) $value, locale: app()->getLocale()) : $value);

                                return ($labels[$field] ?? $field).': '
                                    .(array_key_exists($field, (array) $audit->old_values) ? $show($audit->old_values[$field] ?? null).' → ' : '')
                                    .$show($new);
                            })
                            ->implode("\n"),
                    ])->all())
                    ->schema([
                        TextEntry::make('event')->hiddenLabel()->badge()->color('gray'),
                        TextEntry::make('who')->hiddenLabel()->icon(Phosphor::User),
                        TextEntry::make('when')->hiddenLabel()->icon(Phosphor::Clock),
                        TextEntry::make('changes')->hiddenLabel()->columnSpanFull()->prose()->formatStateUsing(fn (?string $state) => nl2br(e((string) $state)))->html(),
                    ])
                    ->columns(3)
                    ->placeholder(__('No changes recorded.')),
            ]);
    }
}
