<?php

namespace App\Filament\Resources\Projects\Schemas;

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
                $project->status === ProjectStatus::Cancelled => 'Trabalho cancelado',
                $approved => 'Orçamento aprovado',
                default => 'Proposta enviada',
            },
            'meta' => $project->quoted_on === null ? 'Sem data de orçamento' : 'Orçamento de '.$project->quoted_on->format('d/m/Y'),
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
        $description = $receivable->description ?: 'Valor a receber';
        $outstanding = $receivable->outstanding;
        /** @var Payment|null $lastPayment */
        $lastPayment = $receivable->payments->sortBy('paid_on')->last();

        if ($outstanding <= 0 && $lastPayment !== null) {
            $days = (int) $receivable->due_on->diffInDays($lastPayment->paid_on, false);

            return [
                'title' => 'Recebido: '.$description,
                'meta' => $lastPayment->paid_on->format('d/m/Y').' · '.match (true) {
                    $days === 0 => 'no dia do vencimento',
                    $days > 0 => trans_choice('{1} 1 dia depois|[2,*] :count dias depois', $days),
                    default => trans_choice('{1} 1 dia antes|[2,*] :count dias antes', abs($days)),
                },
                'amount' => Money::format($receivable->amount),
                'state' => 'done',
            ];
        }

        $late = $receivable->due_on->isBefore(today());
        $meta = $late
            ? 'Venceu a '.$receivable->due_on->format('d/m/Y').' · há '.trans_choice('{1} 1 dia|[2,*] :count dias', (int) $receivable->due_on->diffInDays(today()))
            : 'Vence a '.$receivable->due_on->format('d/m/Y');

        if ($receivable->received > 0) {
            $meta .= ' · '.Money::format($receivable->received).' já recebidos';
        }

        return [
            'title' => $description,
            'meta' => $meta,
            'amount' => Money::format(max(0, $outstanding)),
            'state' => $late ? 'late' : 'pending',
        ];
    }
}
