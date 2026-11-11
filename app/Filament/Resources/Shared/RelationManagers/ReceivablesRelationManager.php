<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Actions\GenerateRetainerPeriods;
use App\Enums\Language;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Support\MoneyInput;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use App\Support\RelativeDue;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Prestações (projects) or periods (retainers).
 */
class ReceivablesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'receivables';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Project ? __('Payment plan') : __('Periods');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')->label(__('Description'))->required(),
            MoneyInput::make('amount')->label(__('Amount'))->required(),
            DatePicker::make('due_on')->label(__('Due date'))->native(false)->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())->required(),
        ])->columns(3);
    }

    /**
     * As in the project mock: no heading (the tab already says it), a line on the plan instead,
     * and "Add" at the end; the description, the due date (with how far off it is while still
     * open), the amounts, the state and the invoice, with "Receive" on an open one and the totals
     * at the foot. The description opens the edit form, which also deletes.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn ($query) => $query->with('payments'))
            ->defaultSort('due_on')
            ->paginated([12, 24, 'all'])
            ->heading(null)
            ->description(fn (): ?string => match (true) {
                $this->getOwnerRecord() instanceof Project => trans_choice('{0} No payment plan yet|{1} Payment plan in a single payment|[2,*] Payment plan in :count parts', $this->getOwnerRecord()->receivables()->count()),
                // As in the mock: nobody adds a retainer's amounts by hand.
                $this->getOwnerRecord() instanceof Retainer && $this->getOwnerRecord()->is_active => ($next = app(GenerateRetainerPeriods::class)->upcoming($this->getOwnerRecord(), 1)[0] ?? null) === null
                    ? __('Contiu creates the amount for each period.')
                    : __('Contiu creates the amount for each period. The next is :period.', ['period' => $next['label']]),
                default => null,
            })
            ->columns([
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->wrap()
                    // The row keeps only "Receive", as in the mock: the description opens the edit form.
                    ->action(static::editAction()),
                TextColumn::make('due_on')
                    ->label(__('Due date'))
                    ->date()
                    ->description(fn (Receivable $record): ?string => static::dueIn($record)),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR', divideBy: 100)->alignEnd()
                    ->summarize(Sum::make()->label('')->money('EUR', divideBy: 100)),
                TextColumn::make('received')->label(__('Received'))->money('EUR', divideBy: 100)->alignEnd()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—')
                    ->color(fn (int $state): ?string => $state > 0 ? null : 'gray')
                    ->summarize(static::sumOf('received')),
                TextColumn::make('outstanding')->label(__('Outstanding'))->money('EUR', divideBy: 100)->alignEnd()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—')
                    ->weight(fn (int $state): ?FontWeight => $state > 0 ? FontWeight::SemiBold : null)
                    ->color(fn (int $state): ?string => $state > 0 ? null : 'gray')
                    ->summarize(static::sumOf('outstanding')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('payments.invoice_number')
                    ->label(__('Invoice'))
                    ->placeholder(fn (Receivable $record): string => $record->outstanding > 0 ? __('To issue') : '—')
                    ->listWithLineBreaks()
                    ->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add'))
                    ->icon(Phosphor::Plus)
                    ->color('gray')
                    ->size('sm')
                    ->modalHeading(__('New receivable')),
            ])
            ->recordActions([
                RecordPaymentAction::make()
                    ->button()
                    // The smallest button, so the eight columns fit beside the timeline.
                    ->size('xs')
                    ->color('gray')
                    ->icon(null)
                    ->label(__('Receive'))
                    ->visible(fn (Receivable $record) => $record->outstanding > 0),
            ]);
    }

    /** Editing an instalment, with "Delete" in the form's footer while nothing was paid on it. */
    private static function editAction(): EditAction
    {
        return EditAction::make()
            ->extraModalFooterActions(fn (EditAction $action): array => [
                DeleteAction::make()
                    // The instalment being edited, read once the form is open.
                    ->record(fn (): ?Model => $action->getRecord())
                    ->visible(fn (?Receivable $record): bool => $record?->payments->isEmpty() ?? false)
                    ->cancelParentActions(),
            ]);
    }

    /** "in 105 days" or "3 days ago" under the due date, while there is something to receive. */
    private static function dueIn(Receivable $receivable): ?string
    {
        return $receivable->outstanding > 0 ? RelativeDue::describe($receivable->due_on) : null;
    }

    /** The total of a computed amount (from the payments) over the rows the table shows. */
    private static function sumOf(string $attribute): Summarizer
    {
        return Summarizer::make()
            ->money('EUR', divideBy: 100)
            ->using(fn (QueryBuilder $query): int => (int) Receivable::query()
                ->whereIn('id', $query->pluck('receivables.id'))
                ->with('payments')
                ->get()
                ->sum($attribute));
    }
}
