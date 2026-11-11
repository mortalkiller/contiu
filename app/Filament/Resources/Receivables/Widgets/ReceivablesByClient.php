<?php

namespace App\Filament\Resources\Receivables\Widgets;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\Widget;
use MortalKiller\FilamentAppearance\Support\BarList;

/**
 * "Quem deve", beside the "A receber" list as in the mock: every client with something open,
 * the biggest debt first, each with a bar relative to it and what is already late.
 */
class ReceivablesByClient extends Widget
{
    protected string $view = 'filament.widgets.bar-list';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{heading: string, list: BarList, emptyLabel: string, buttonUrl: string, buttonLabel: string}
     */
    protected function getViewData(): array
    {
        $byClient = [];

        foreach (Receivable::query()->open()->excludingCancelled()->withOutstandingBalance()->with('billable.client')->get() as $receivable) {
            $name = $receivable->client?->name ?? __('No client');
            $byClient[$name] ??= ['open' => 0, 'overdue' => 0];
            $byClient[$name]['open'] += $receivable->projectedOutstanding();
            $byClient[$name]['overdue'] += $receivable->due_on->isBefore(today()) ? $receivable->projectedOutstanding() : 0;
        }

        uasort($byClient, fn (array $a, array $b): int => $b['open'] <=> $a['open']);

        $list = BarList::make()->strongValues();

        foreach ($byClient as $name => $debt) {
            $list->item(
                label: $name,
                value: Money::format($debt['open'], locale: app()->getLocale()),
                amount: $debt['open'],
                note: $debt['overdue'] > 0 ? __(':amount overdue', ['amount' => Money::format($debt['overdue'], locale: app()->getLocale())]) : null,
                noteColor: 'danger',
            );
        }

        return [
            'heading' => __('Who owes money'),
            'list' => $list,
            'emptyLabel' => __('Nothing outstanding'),
            'buttonUrl' => ClientResource::getUrl(),
            'buttonLabel' => __('View a client\'s statement'),
        ];
    }
}
