<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\CurrentAccount;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * What you bought from a supplier, newest first, as in the mock: the expense with its category
 * and type, when, how much, whether it is paid and its supporting document (the invoice number,
 * or that it is missing). An unpaid one is marked as paid from its row.
 */
class SupplierExpensesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'expenses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Expenses');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->expenses()->count()) ?: null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->defaultSort('spent_on', 'desc')
            ->heading(null)
            ->searchPlaceholder(__('Description or invoice number'))
            ->recordUrl(fn (Expense $record): ?string => ExpenseResource::canView($record) ? ExpenseResource::getUrl('view', ['record' => $record]) : null)
            ->columns([
                TextColumn::make('description')
                    ->label(__('Expense'))
                    ->description(fn (Expense $record): string => collect([$record->category?->name, $record->kind?->getLabel()])->filter()->implode(' · '))
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable(['description', 'invoice_number']),
                TextColumn::make('spent_on')
                    ->label(__('Date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight(fn (Expense $record): ?FontWeight => $record->is_paid ? null : FontWeight::SemiBold)
                    ->summarize(Summarizer::make('sum')
                        ->money('EUR', divideBy: 100)
                        ->using(fn ($query) => (int) $query->get()->sum(fn ($row) => round((float) $row->net_amount * (1 + (float) $row->vat_rate))))),
                TextColumn::make('is_paid')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Expense paid') : __('Unpaid'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                TextColumn::make('proof')
                    ->label(__('Supporting document'))
                    ->state(fn (Expense $record): string => match (true) {
                        filled($record->invoice_number) => $record->invoice_number,
                        $record->hasProof() => __('Attached'),
                        default => __('Missing'),
                    })
                    ->color(fn (Expense $record): ?string => filled($record->invoice_number) || $record->hasProof() ? null : 'warning'),
            ])
            ->filters([
                SelectFilter::make('year')
                    ->label(__('Year'))
                    ->options(fn (): array => $this->getOwnerRecord()->expenses()->pluck('spent_on')->map->year->unique()->sortDesc()->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('spent_on', $year))),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label(__('Mark expense as paid'))
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    // Not behind a policy: the read-only gate cannot hide it.
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->tooltip(fn () => CurrentAccount::readOnly() ? __('The account is read-only') : null)
                    ->visible(fn (Expense $record) => ! $record->is_paid)
                    ->action(function (Expense $record): void {
                        $record->update(['is_paid' => true, 'paid_on' => today()]);

                        Notification::make()->title(__('Expense marked as paid'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No expenses'))
            ->emptyStateDescription(__('Expenses recorded with this supplier show up here.'));
    }
}
