<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Models\Project;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * "Documentos" tab of a project: the fiscal documents linked to its charges or to the payments
 * of those charges. Read-only; documents are registered and linked in "Documentos fiscais".
 */
class ProjectDocumentsRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'receivables';

    protected static ?string $title = 'Documentos';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) self::documents($ownerRecord)->count()) ?: null;
    }

    /** @return Builder<FiscalDocument> */
    public static function documents(Model $project): Builder
    {
        /** @var Project $project */
        $receivables = $project->receivables()->select('receivables.id');
        $payments = $project->payments()->select('payments.id');

        return FiscalDocument::query()->whereHas('links', fn (Builder $query) => $query
            ->whereIn('receivable_id', $receivables)
            ->orWhereIn('payment_id', $payments));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::documents($this->getOwnerRecord())->with('links.receivable'))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('Documento')
                    ->description(fn (FiscalDocument $record): string => $record->type->getLabel()),
                TextColumn::make('document_date')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('links.receivable.description')->label('Referente a')->placeholder('—'),
                TextColumn::make('net_amount')->label('Sem IVA')->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)->alignEnd(),
                TextColumn::make('total_amount')->label('Total')->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100)->alignEnd(),
                TextColumn::make('status')->label('Estado')->badge(),
            ])
            ->recordUrl(fn (FiscalDocument $record): ?string => FiscalDocumentResource::canEdit($record)
                ? FiscalDocumentResource::getUrl('edit', ['record' => $record])
                : null)
            ->emptyStateHeading('Sem documentos fiscais')
            ->emptyStateDescription('As faturas e os recibos ligados a este trabalho aparecem aqui.');
    }
}
