<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Enums\Language;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Support\MoneyInput;
use App\Models\Project;
use App\Models\Receivable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn ($query) => $query->with('payments'))
            ->defaultSort('due_on')
            ->paginated([12, 24, 'all'])
            ->columns([
                TextColumn::make('description')->label(__('Description')),
                TextColumn::make('due_on')->label(__('Due date'))->date()->sortable(),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR', divideBy: 100)->alignEnd()
                    ->summarize(Sum::make()->label(__('Total'))->money('EUR', divideBy: 100)),
                TextColumn::make('received')->label(__('Received'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('outstanding')->label(__('Outstanding'))->money('EUR', divideBy: 100)->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('payments.invoice_number')->label(__('Invoice'))->placeholder('—')->listWithLineBreaks()->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add'))->modalHeading(__('New receivable')),
            ])
            ->recordActions([
                RecordPaymentAction::make()
                    ->iconButton()
                    ->tooltip(__('Record payment'))
                    ->visible(fn (Receivable $record) => $record->outstanding > 0),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton()->visible(fn (Receivable $record) => $record->payments->isEmpty()),
            ]);
    }
}
