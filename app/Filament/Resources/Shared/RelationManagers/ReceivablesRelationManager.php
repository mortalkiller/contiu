<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Filament\Actions\RecordPaymentAction;
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

/**
 * Prestações (projects) or periods (retainers).
 */
class ReceivablesRelationManager extends RelationManager
{
    protected static string $relationship = 'receivables';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof Project ? 'Plano de pagamento' : 'Períodos';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')->label('Descrição')->required(),
            TextInput::make('amount')->label('Valor')->numeric()->step(0.01)->suffix('€')->required(),
            DatePicker::make('due_on')->label('Vencimento')->native(false)->displayFormat('d/m/Y')->required(),
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
                TextColumn::make('description')->label('Descrição'),
                TextColumn::make('due_on')->label('Vencimento')->date()->sortable(),
                TextColumn::make('amount')->label('Valor')->money('EUR')->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('EUR')),
                TextColumn::make('received')->label('Recebido')->money('EUR')->alignEnd(),
                TextColumn::make('outstanding')->label('Em aberto')->money('EUR')->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('payments.invoice_number')->label('Fatura')->placeholder('—')->listWithLineBreaks()->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Adicionar')->modalHeading('Novo valor a receber'),
            ])
            ->recordActions([
                RecordPaymentAction::make()
                    ->iconButton()
                    ->tooltip('Registar recebimento')
                    ->visible(fn (Receivable $record) => $record->outstanding > 0),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton()->visible(fn (Receivable $record) => $record->payments->isEmpty()),
            ]);
    }
}
