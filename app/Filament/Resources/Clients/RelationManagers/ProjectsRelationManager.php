<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        $sum = fn (string $attribute): Summarizer => Summarizer::make()
            ->money('EUR', divideBy: 100)
            ->using(fn (QueryBuilder $query): int => (int) Project::query()
                ->whereIn('id', $query->pluck('projects.id'))
                ->with(['receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments'])
                ->get()
                ->sum($attribute));

        return ProjectResource::table($table)
            ->heading(null)
            ->headerActions([])
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments', 'expenses']))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Project'))
                    ->description(fn (Project $record): string => (string) $record->code)
                    ->weight(FontWeight::Medium)
                    ->searchable(['code', 'title'])
                    ->summarize(Summarizer::make()->using(fn (): string => __('Total'))),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize($sum('total')),
                TextColumn::make('received_amount')
                    ->label(__('Received'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize($sum('received_amount')),
                TextColumn::make('outstanding_amount')
                    ->label(__('Outstanding'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight(fn (int $state): ?FontWeight => $state > 0 ? FontWeight::SemiBold : null)
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? Money::format($state) : '—')
                    ->summarize($sum('outstanding_amount')),
                TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge()
                    ->placeholder('—'),
            ])
            ->recordActions([
                RecordPaymentAction::make()->button()->size('sm')->color('gray')->icon(null)->label(__('Receive')),
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
