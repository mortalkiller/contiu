<?php

namespace App\Actions;

use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\MonthReview;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportMonthCsv
{
    public function handle(MonthReview $review): StreamedResponse
    {
        $account = CurrentAccount::get();
        $actor = auth()->user();

        abort_unless($account !== null && $actor instanceof User && $actor->hasVerifiedEmail() && $actor->canAccessTenant($account), 403);

        return response()->streamDownload(function () use ($account, $review): void {
            CurrentAccount::run($account, function () use ($review): void {
                $output = fopen('php://output', 'wb');

                try {
                    fwrite($output, "\xEF\xBB\xBF");

                    foreach ($this->rows($review) as $row) {
                        fputcsv($output, $row, separator: ';', escape: '', eol: "\r\n");
                    }
                } finally {
                    fclose($output);
                }
            });
        }, 'fecho-'.$account->slug.'-'.$review->start->format('Y-m').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return Generator<int, list<int|string|null>> */
    private function rows(MonthReview $review): Generator
    {
        yield [
            'Tipo', 'ID', 'Referência', 'Descrição', 'Vencimento', 'Data da despesa', 'Data do movimento',
            'Valor (EUR)', 'Saldo atual em aberto (EUR)', 'Estado financeiro', 'Documento fiscal',
            'Referência anterior por confirmar', 'Banco', 'Dados por completar', 'Saldo no fim do mês (EUR)',
        ];

        foreach ($review->receivablesQuery(withFiscalReference: true)->get() as $receivable) {
            yield [
                'A receber', $receivable->id, $this->textCell($receivable->billable?->code), $this->textCell($receivable->description),
                $receivable->due_on->toDateString(), null, null, $this->amount($receivable->amount), $this->amount($receivable->outstanding),
                match (true) {
                    $receivable->outstanding === 0 => 'Recebido',
                    $review->isDueLater($receivable) => 'Vence após o mês',
                    default => 'Por receber',
                },
                $this->textCell($receivable->fiscal_reference), null, null, implode(' · ', $review->dataIssues($receivable)),
                $this->amount($review->outstandingAtClose($receivable)),
            ];
        }

        foreach ($review->paymentsQuery()->get() as $payment) {
            yield [
                'Recebimento', $payment->id, $this->textCell($payment->receivableLabels()), null, null, null,
                $payment->paid_on->toDateString(), $this->amount($payment->amount), null, 'Recebido',
                $this->textCell($payment->fiscal_reference), $this->textCell(MonthReview::unconfirmedLegacyReference($payment)),
                $review->reconciliationFor($payment)?->label(), implode(' · ', $review->dataIssues($payment)), null,
            ];
        }

        foreach ($review->expensesQuery(withFiscalReference: true)->get() as $expense) {
            yield [
                'Despesa', $expense->id, $this->textCell($expense->supplier?->name), $this->textCell($expense->description),
                null, $expense->spent_on->toDateString(), $expense->paid_on?->toDateString(),
                $this->amount($expense->total), $this->amount($expense->is_paid ? 0 : $expense->total), $expense->is_paid ? 'Paga' : 'Por pagar',
                $this->textCell($expense->fiscal_reference), $this->textCell(MonthReview::unconfirmedLegacyReference($expense)),
                $review->reconciliationFor($expense)?->label(), implode(' · ', $review->dataIssues($expense)), null,
            ];
        }

        foreach ($review->pendingBankLinesQuery()->orderBy('booked_on')->orderBy('id')->get() as $line) {
            yield [
                'Linha do extrato', $line->id, null, $this->textCell($line->description), null, null,
                $line->booked_on->toDateString(), $this->amount($line->amount), null,
                null, null, null, $line->status->getLabel(), null, null,
            ];
        }
    }

    private function amount(int $cents): string
    {
        return sprintf('%s%d,%02d', $cents < 0 ? '-' : '', abs(intdiv($cents, 100)), abs($cents % 100));
    }

    private function textCell(?string $text): ?string
    {
        if ($text !== null && preg_match('/\A(?:[\t\r\n]|[\s\p{Cc}\p{Cf}]*[=+\-@＝＋－＠])/u', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}
