<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\Language;
use App\Enums\ProjectStatus;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;

/**
 * "Linha do tempo" of a project: the quote, then each instalment in due order, said as what
 * happened (received, and how far from the due date) or what is still to come.
 */
final class ProjectTimeline
{
    /**
     * @return list<array{title: string, meta: string, amount: ?string, state: 'done'|'late'|'pending'}>
     */
    public static function events(Project $project): array
    {
        $approved = ! in_array($project->status, [ProjectStatus::Proposal, ProjectStatus::Cancelled], true);

        $events = [[
            'title' => match (true) {
                $project->status === ProjectStatus::Cancelled => __('Project cancelled'),
                $approved => __('Quote approved'),
                default => __('Proposal sent'),
            },
            'meta' => $project->quoted_on === null ? __('No quote date') : __('Quote dated :date', ['date' => $project->quoted_on->format(Language::resolve(app()->getLocale())->dateFormat())]),
            'amount' => null,
            'state' => $approved ? 'done' : 'pending',
        ]];

        foreach ($project->receivables->sortBy('due_on') as $receivable) {
            $events[] = self::receivableEvent($receivable);
        }

        return $events;
    }

    /** @return array{title: string, meta: string, amount: ?string, state: 'done'|'late'|'pending'} */
    private static function receivableEvent(Receivable $receivable): array
    {
        $description = $receivable->description ?: __('Receivable');
        $outstanding = $receivable->projectedOutstanding();
        /** @var Payment|null $lastPayment */
        $lastPayment = $receivable->payments->sortBy('paid_on')->last();

        if ($outstanding <= 0 && $lastPayment !== null) {
            $days = (int) $receivable->due_on->diffInDays($lastPayment->paid_on, false);

            return [
                'title' => __('Received: :description', ['description' => $description]),
                'meta' => $lastPayment->paid_on->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.match (true) {
                    $days === 0 => __('on the due date'),
                    $days > 0 => trans_choice('{1} 1 day later|[2,*] :count days later', $days),
                    default => trans_choice('{1} 1 day earlier|[2,*] :count days earlier', abs($days)),
                },
                'amount' => Money::format($receivable->amount),
                'state' => 'done',
            ];
        }

        $late = $receivable->due_on->isBefore(today());
        $date = $receivable->due_on->format(Language::resolve(app()->getLocale())->dateFormat());
        $meta = $late
            ? trans_choice(
                '{1} Was due on :date · 1 day ago|[2,*] Was due on :date · :count days ago',
                (int) $receivable->due_on->diffInDays(today()),
                ['date' => $date],
            )
            : __('Due on :date', ['date' => $date]);

        if ($receivable->received > 0) {
            $meta .= ' · '.__(':amount already received', ['amount' => Money::format($receivable->received)]);
        }

        return [
            'title' => $description,
            'meta' => $meta,
            'amount' => Money::format(max(0, $outstanding)),
            'state' => $late ? 'late' : 'pending',
        ];
    }
}
