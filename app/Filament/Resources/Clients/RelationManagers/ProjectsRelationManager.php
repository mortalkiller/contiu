<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ProjectsRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'projects';

    protected static ?string $relatedResource = ProjectResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Projects');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->projects()->count()) ?: null;
    }

    /**
     * The client's projects as in the mock: the title with its code under it, its state, the
     * amounts and how it is being paid, with "Receive" on a project with something open and the
     * totals at the foot. No heading (the tab already says it), the search first and "New
     * project" for this client at the end of the toolbar.
     */
    public function table(Table $table): Table
    {
        return ProjectResource::table($table)
            ->heading(null)
            ->headerActions([])
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments', 'expenses']))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Project'))
                    ->description(fn (Project $record): string => (string) $record->code)
                    ->weight(FontWeight::Medium)
                    ->searchable(['code', 'title']),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(ProjectsTable::sumOf('total')),
                TextColumn::make('received_amount')
                    ->label(__('Received'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(ProjectsTable::sumOf('received_amount')),
                ProjectsTable::outstandingColumn(),
                TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge()
                    ->placeholder('—'),
            ])
            ->recordActions([
                RecordPaymentAction::rowButton(),
            ])
            ->toolbarActions([
                Action::make('newProject')
                    ->label(__('New project'))
                    ->icon(Phosphor::Plus)
                    ->color('gray')
                    ->size('sm')
                    ->url(fn (): string => ProjectResource::getUrl('create', ['client' => $this->getOwnerRecord()->getKey()])),
            ]);
    }
}
