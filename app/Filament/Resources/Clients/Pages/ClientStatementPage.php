<?php

namespace App\Filament\Resources\Clients\Pages;

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

    protected static ?string $title = 'Extrato';

    protected static ?string $navigationLabel = 'Extrato';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Scroll;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getBreadcrumb(): string
    {
        return 'Extrato';
    }

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make()->after(fn () => $this->resetTable()),
            SendPaymentReminderAction::make(),
            ViewClient::addClientEmailAction(),
            ActionGroup::make([
                Action::make('exportExcel')
                    ->label('Exportar Excel')
                    ->icon(Phosphor::DownloadSimple)
                    ->action(fn () => Excel::download(new ClientStatementExport($this->statement()), ClientStatementExport::filename($this->statement()))),
                SendStatementEmailAction::make()
                    ->statement(fn () => $this->statement()),
            ])
                ->label('Extrato')
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
            ->heading('Movimentos')
            ->description(fn (): string => $this->summary())
            ->records(fn (?array $filters): array => $this->statement($filters)->entries()->keyBy('key')->all())
            ->paginated(false)
            ->columns([
                TextColumn::make('date')
                    ->label('Data')
                    ->date()
                    ->color(fn (array $record) => $record['is_future'] ? 'gray' : null),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->weight(fn (array $record) => $record['kind'] === 'opening' ? FontWeight::SemiBold : null)
                    ->color(fn (array $record) => $record['is_future'] ? 'gray' : null)
                    ->description(fn (array $record) => $record['is_future'] ? 'a vencer' : null),
                TextColumn::make('debit')
                    ->label('Débito')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state) => $state > 0 ? Money::format($state) : '')
                    ->color(fn (array $record) => $record['is_future'] ? 'gray' : null),
                TextColumn::make('credit')
                    ->label('Crédito')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state) => $state > 0 ? Money::format($state) : '')
                    ->color('success'),
                TextColumn::make('balance')
                    ->label('Saldo')
                    ->alignEnd()
                    ->weight(FontWeight::Medium)
                    ->formatStateUsing(fn (float $state) => Money::format($state))
                    ->color(fn (float $state) => $state > 0.009 ? 'warning' : null),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')
                            ->label('De')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(today()->startOfYear()),
                        DatePicker::make('until')
                            ->label('Até')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                    ])
                    ->columns(2)
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? 'De '.Carbon::parse($data['from'])->format('d/m/Y') : null,
                        filled($data['until'] ?? null) ? 'Até '.Carbon::parse($data['until'])->format('d/m/Y') : null,
                    ]))),
            ], layout: FiltersLayout::AboveContent)
            ->emptyStateHeading('Sem movimentos neste período');
    }

    private function statement(?array $filters = null): ClientStatement
    {
        $period = ($filters ?? $this->tableFilters ?? [])['period'] ?? [];

        return new ClientStatement(
            $this->getRecord(),
            from: filled($period['from'] ?? null) ? Carbon::parse($period['from']) : null,
            to: filled($period['until'] ?? null) ? Carbon::parse($period['until']) : null,
        );
    }

    private function summary(): string
    {
        $statement = $this->statement();

        return 'Cobrado '.Money::format($statement->charged())
            .' · Recebido '.Money::format($statement->received())
            .' · Saldo '.Money::format($statement->balance())
            .' · Vencido '.Money::format($statement->overdueBalance());
    }
}
