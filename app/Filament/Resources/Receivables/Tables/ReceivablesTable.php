<?php

namespace App\Filament\Resources\Receivables\Tables;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Models\Client;
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
    private const TYPES = [Project::class => 'Trabalho', Retainer::class => 'Avença', OneOffIncome::class => 'Receita avulsa'];

    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Excel')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'a-receber-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Exportar seleção')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'a-receber-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('due_on')
                    ->label('Vencimento')
                    ->date()
                    ->sortable()
                    ->description(fn (Receivable $record) => static::relativeDue($record)),
                TextColumn::make('client')
                    ->label('Cliente')
                    ->state(fn (Receivable $record) => $record->client?->name)
                    ->description(fn (Receivable $record) => ($sentAt = $record->client?->lastPaymentReminderAt())
                        ? 'Lembrete enviado a '.$sentAt->format('d/m')
                        : null)
                    ->limit(28),
                TextColumn::make('billable.code')
                    ->label('Referente a')
                    ->description(fn (Receivable $record) => $record->description)
                    ->url(fn (Receivable $record) => $record->billableUrl())
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHasMorph(
                        'billable',
                        [Project::class, Retainer::class, OneOffIncome::class],
                        fn (Builder $query) => $query->where('code', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%")
                            ->orWhereRelation('client', 'name', 'like', "%{$search}%"),
                    )),
                TextColumn::make('billable_type')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('outstanding')
                    ->label('Em aberto')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('medium')
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $client) => $query->forClient((int) $client))),
                SelectFilter::make('billable_type')
                    ->label('Tipo')
                    ->options(self::TYPES),
            ])
            ->recordActions([
                RecordPaymentAction::make()
                    ->button()
                    ->label('Receber')
                    ->visible(fn (Receivable $record) => $record->outstanding > 0),
                SendPaymentReminderAction::make()
                    ->iconButton()
                    ->tooltip(fn (Receivable $record) => blank($record->client?->email) ? 'O cliente não tem email' : 'Enviar lembrete ao cliente')
                    ->visible(fn (Receivable $record) => $record->outstanding > 0),
            ])
            ->emptyStateHeading('Nada por receber aqui')
            ->emptyStateDescription('Os valores a receber são criados a partir dos trabalhos e das avenças.');
    }

    protected static function relativeDue(Receivable $record): ?string
    {
        if ($record->outstanding <= 0) {
            return null;
        }

        $days = (int) today()->diffInDays($record->due_on, false);

        return match (true) {
            $days < 0 => 'há '.abs($days).' '.(abs($days) === 1 ? 'dia' : 'dias'),
            $days === 0 => 'hoje',
            default => 'daqui a '.$days.' '.($days === 1 ? 'dia' : 'dias'),
        };
    }
}
