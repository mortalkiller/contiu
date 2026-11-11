<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\Language;
use App\Exports\ClientStatementExport;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Filament\Actions\SendStatementEmailAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Support\ClientStatement;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Extrato: the client's charges and payments in date order with a running balance.
 * Built from App\Support\ClientStatement (array records, not an Eloquent query).
 */
class ClientStatementPage extends Page implements HasTable
{
    use HasPageHeader;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ClientResource::class;

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Scroll;

    /** @var array<string, ClientStatement> */
    private array $statements = [];

    public function getTitle(): string
    {
        return __('Statement');
    }

    public static function getNavigationLabel(): string
    {
        return __('Statement');
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getBreadcrumb(): string
    {
        return __('Statement');
    }

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make()->after(function (): void {
                $this->statements = [];
                $this->resetTable();
            }),
            SendPaymentReminderAction::make(),
            ViewClient::addClientEmailAction(),
            ActionGroup::make([
                Action::make('exportExcel')
                    ->label(__('Export to Excel'))
                    ->icon(Phosphor::DownloadSimple)
                    ->action(fn () => Excel::download(new ClientStatementExport($this->statement()), ClientStatementExport::filename($this->statement()))),
                SendStatementEmailAction::make()
                    ->statement(fn () => $this->statement()),
            ])
                ->label(__('Statement'))
                ->icon(Phosphor::Scroll)
                ->button()
                ->color('gray'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Transactions'))
            ->description(fn (): string => $this->summary())
            ->records(fn (?array $filters): array => $this->statement($filters)->entries()->keyBy('key')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('date')
                    ->label(__('Date'))
                    ->date()
                    ->color(fn (array $record) => $record['is_future'] ? 'gray' : null),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->weight(fn (array $record) => $record['kind'] === 'opening' ? FontWeight::SemiBold : null)
                    ->color(fn (array $record) => $record['is_future'] ? 'gray' : null)
                    ->description(fn (array $record) => $record['is_future'] ? __('not yet due') : null),
                TextColumn::make('debit')
                    ->label(__('Debit'))
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Money::format($state) : '')
                    ->color(fn (array $record) => $record['is_future'] ? 'gray' : null),
                TextColumn::make('credit')
                    ->label(__('Credit'))
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Money::format($state) : '')
                    ->color('success'),
                TextColumn::make('balance')
                    ->label(__('Balance'))
                    ->alignEnd()
                    ->weight(FontWeight::Medium)
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->color(fn (int $state) => $state > 0 ? 'warning' : null),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('From'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->default(today()->startOfYear()),
                        DatePicker::make('until')
                            ->label(__('Until'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat()),
                    ])
                    ->columns(2)
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? __('From :date', ['date' => Carbon::parse($data['from'])->format(Language::resolve(app()->getLocale())->dateFormat())]) : null,
                        filled($data['until'] ?? null) ? __('Until :date', ['date' => Carbon::parse($data['until'])->format(Language::resolve(app()->getLocale())->dateFormat())]) : null,
                    ]))),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading(__('No transactions in this period'));
    }

    private function statement(?array $filters = null): ClientStatement
    {
        $period = ($filters ?? $this->tableFilters ?? [])['period'] ?? [];

        $from = filled($period['from'] ?? null) ? Carbon::parse($period['from']) : null;
        $to = filled($period['until'] ?? null) ? Carbon::parse($period['until']) : null;
        $key = $from?->toIso8601String().'|'.$to?->toIso8601String();

        return $this->statements[$key] ??= new ClientStatement($this->getRecord(), from: $from, to: $to);
    }

    private function summary(): string
    {
        $statement = $this->statement();

        return __('Charged :charged · Received :received · Balance :balance · Past due :overdue', [
            'charged' => Money::format($statement->charged()),
            'received' => Money::format($statement->received()),
            'balance' => Money::format($statement->balance()),
            'overdue' => Money::format($statement->overdueBalance()),
        ]);
    }
}
