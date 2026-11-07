<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Receivable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $modelLabel = 'recebimento';

    protected static ?string $pluralModelLabel = 'recebimentos';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::HandCoins;

    protected static string|UnitEnum|null $navigationGroup = 'Receitas';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['receivable.billable.client']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('paid_on')->label('Data')->native(false)->displayFormat('d/m/Y')->required(),
            TextInput::make('amount')->label('Montante')->numeric()->step(0.01)->suffix('€')->required(),
            ToggleButtons::make('method')->label('Método')->options(PaymentMethod::class)->inline()->required()->columnSpanFull(),
            Toggle::make('tax_exempt')
                ->label('Isento de impostos')
                ->helperText('Não conta para a estimativa de impostos (IVA, Segurança Social, IRS).')
                ->columnSpanFull(),
            TextInput::make('invoice_number')->label('N.º da fatura / recibo')->columnSpanFull(),
            Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Excel')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'recebimentos-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Exportar seleção')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'recebimentos-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('paid_on')->label('Data')->date()->sortable(),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->state(fn (Payment $record) => $record->receivable->client?->name)
                    ->limit(28),
                TextColumn::make('receivable.billable.code')
                    ->label('Referente a')
                    ->description(fn (Payment $record) => $record->receivable->description)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('receivable', fn (Builder $query) => $query->whereHasMorph(
                        'billable', '*', fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhereRelation('client', 'name', 'like', "%{$search}%"),
                    ))),
                TextColumn::make('method')->label('Método')->badge()->color('gray'),
                IconColumn::make('tax_exempt')
                    ->label('Isento')
                    ->boolean()
                    ->trueIcon(Phosphor::ShieldCheck)
                    ->falseIcon(null)
                    ->tooltip(fn (Payment $record) => $record->tax_exempt ? 'Não conta para a estimativa de impostos' : null)
                    ->toggleable(),
                TextColumn::make('invoice_number')->label('Fatura')->placeholder('—')->searchable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('medium')
                    ->summarize(Sum::make()->label('Total')->money('EUR')),
            ])
            ->filters([
                SelectFilter::make('method')->label('Método')->options(PaymentMethod::class)->multiple(),
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $client) => $query->whereIn('receivable_id', Receivable::query()->forClient((int) $client)->select('id')))),
                SelectFilter::make('year')
                    ->label('Ano')
                    ->options(fn () => Payment::query()->pluck('paid_on')->map->year->unique()->sortDesc()->mapWithKeys(fn ($year) => [$year => $year])->all())
                    ->default((string) today()->year)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('paid_on', $year))),
                SelectFilter::make('month')
                    ->label('Mês')
                    ->options(collect(range(1, 12))->mapWithKeys(fn ($month) => [$month => ucfirst(today()->month($month)->locale('pt')->translatedFormat('F'))])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $month) => $query->whereMonth('paid_on', $month))),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modalHeading('Editar recebimento'),
                    static::historyAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sem recebimentos')
            ->emptyStateDescription('Regista o que entrou com "Registar recebimento".');
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
        $labels = ['paid_on' => 'Data', 'amount' => 'Montante', 'method' => 'Método', 'invoice_number' => 'Fatura', 'notes' => 'Notas', 'receivable_id' => 'Valor a receber'];
        $events = ['created' => 'Criado', 'updated' => 'Alterado', 'deleted' => 'Apagado', 'restored' => 'Reposto'];

        return Action::make('history')
            ->label('Histórico')
            ->icon(Phosphor::ClockCounterClockwise)
            ->color('gray')
            ->slideOver()
            ->modalHeading('Histórico do recebimento')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->visible(fn (Payment $record): bool => (bool) auth()->user()?->can('audit', $record))
            ->schema(fn (Payment $record): array => [
                RepeatableEntry::make('history')
                    ->hiddenLabel()
                    ->state(fn () => $record->audits()->with('user')->latest()->get()->map(fn ($audit) => [
                        'when' => $audit->created_at->format('d/m/Y H:i'),
                        'who' => $audit->user?->name ?? 'Sistema',
                        'event' => $events[$audit->event] ?? $audit->event,
                        'changes' => collect($audit->new_values)
                            ->except(['created_at', 'updated_at', 'id'])
                            ->map(fn ($new, $field) => ($labels[$field] ?? $field).': '
                                .(array_key_exists($field, (array) $audit->old_values) ? ($audit->old_values[$field] ?? '—').' → ' : '')
                                .($new ?? '—'))
                            ->implode("\n"),
                    ])->all())
                    ->schema([
                        TextEntry::make('event')->hiddenLabel()->badge()->color('gray'),
                        TextEntry::make('who')->hiddenLabel()->icon(Phosphor::User),
                        TextEntry::make('when')->hiddenLabel()->icon(Phosphor::Clock),
                        TextEntry::make('changes')->hiddenLabel()->columnSpanFull()->prose()->formatStateUsing(fn (?string $state) => nl2br(e((string) $state)))->html(),
                    ])
                    ->columns(3)
                    ->placeholder('Sem alterações registadas.'),
            ]);
    }
}
