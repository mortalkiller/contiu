<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Receivables\Widgets\ReceivablesSummary;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    public function getSubheading(): ?string
    {
        $open = Receivable::query()->open()->excludingCancelled()->withOutstandingBalance()->get();
        $overdue = $open->filter(fn (Receivable $receivable) => $receivable->due_on->isBefore(today()));

        return __('Outstanding: :outstanding · Past due: :overdue', [
            'outstanding' => Money::format($open->sum(fn (Receivable $receivable) => $receivable->projectedOutstanding()), locale: app()->getLocale()),
            'overdue' => Money::format($overdue->sum(fn (Receivable $receivable) => $receivable->projectedOutstanding()), locale: app()->getLocale()),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [ReceivablesSummary::class];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }

    public function getTabs(): array
    {
        $count = fn (callable $scope) => $scope(Receivable::query()->excludingCancelled())->count() ?: null;

        $overdue = fn (Builder $query) => $query->open()->whereDate('due_on', '<', today());
        $next30 = fn (Builder $query) => $query->open()->whereBetween('due_on', [today(), today()->addDays(30)]);
        $open = fn (Builder $query) => $query->open();

        return [
            'overdue' => Tab::make(__('Overdue receivables'))->modifyQueryUsing($overdue)->badge($count($overdue))->badgeColor('danger'),
            'next30' => Tab::make(__('Next 30 days'))->modifyQueryUsing($next30)->badge($count($next30))->badgeColor('warning'),
            'open' => Tab::make(__('Outstanding'))->modifyQueryUsing($open)->badge($count($open)),
            'paid' => Tab::make(__('Paid receivables'))->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('receivables.id', Receivable::query()->open()->select('id'))),
            'all' => Tab::make(__('All')),
        ];
    }
}
