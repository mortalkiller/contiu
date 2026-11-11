<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalProfilePartyType;
use App\Enums\Seat;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\FiscalOperationEvidence;
use App\Models\FiscalProfile;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Retainer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

final class EuServicesRecap
{
    /** @var Collection<int, FiscalProfile>|null */
    private ?Collection $profiles = null;

    /** @return array<int, int> Documented base in cents, indexed by the number of months in the period. */
    public function basesDueIn(CarbonImmutable $month): array
    {
        $previous = $month->subMonth();
        $quarterEnded = $month->month % 3 === 1;
        $start = $quarterEnded ? $previous->startOfQuarter() : $previous;
        $bases = [];

        foreach ($this->activeOperations($start, $month)->get() as $operation) {
            if ($operation->fiscalDocument === null || ! $operation->matchesDocument($operation->fiscalDocument)) {
                continue;
            }

            $frequency = $this->frequency($operation);
            if (($frequency === 1 && $operation->operation_on->gte($previous)) || ($frequency === 3 && $quarterEnded)) {
                $bases[$frequency] = ($bases[$frequency] ?? 0) + $operation->net_amount;
            }
        }

        return $bases;
    }

    public function deadline(CarbonImmutable $month): CarbonImmutable
    {
        if ($month->month === 8) {
            return $month->endOfMonth()->startOfDay();
        }

        $deadline = $month->setDay(20);
        // The movable national holidays that can fall on or just after the 20th.
        $easter = CarbonImmutable::create($month->year, 3, 21)->addDays(easter_days($month->year));

        while ($deadline->isWeekend() || $deadline->isSameDay($easter->subDays(2)) || $deadline->isSameDay($easter->addDays(60))) {
            $deadline = $deadline->addDay();
        }

        return $deadline;
    }

    /** @return array{documents: int, payments: int, unknownFrequency: int, editedDocuments: int} */
    public function review(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $unknownFrequency = 0;
        $editedDocuments = 0;
        foreach ($this->activeOperations($from, $until->addDay())->get() as $operation) {
            if ($operation->fiscalDocument === null) {
                continue;
            }

            if (! $operation->matchesDocument($operation->fiscalDocument)) {
                $editedDocuments++;
            } elseif ($this->frequency($operation) === null) {
                $unknownFrequency++;
            }
        }

        $documents = FiscalDocument::query()
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->whereIn('type', FiscalDocumentType::invoices())
            ->where('currency', 'EUR')
            ->where('vat_amount', 0)
            ->where('net_amount', '>', 0)
            ->whereRaw('DATE(COALESCE(operation_date, document_date)) BETWEEN ? AND ?', [$from->toDateString(), $until->toDateString()])
            ->whereDoesntHave('fiscalOperationEvidence')
            ->where(function (Builder $query): void {
                $query->whereHas('links.receivable', self::forEuBusiness(...));

                foreach (EuServiceEvidence::vatPrefixes() as $prefix) {
                    $query->orWhereRaw('UPPER(TRIM(party_tax_id)) LIKE ?', [$prefix.'%']);
                }
            })
            ->count();

        // Legacy payment flags are review leads only. A verified linked invoice already supplies the operation.
        $payments = Payment::query()
            ->where('tax_exempt', false)
            ->whereBetween('paid_on', [$from->startOfDay(), $until->endOfDay()])
            ->whereNotIn('id', $this->coveredLinks('payment_id'))
            ->whereHas('allocations.receivable', function (Builder $query): void {
                $query->whereNotIn('id', $this->coveredLinks('receivable_id'));
                self::forEuBusiness($query);
            })
            ->count();

        return compact('documents', 'payments', 'unknownFrequency', 'editedDocuments');
    }

    /** @return Builder<FiscalOperationEvidence> */
    private function activeOperations(CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return FiscalOperationEvidence::query()
            ->where('operation_on', '>=', $from->toDateString())
            ->where('operation_on', '<', $until->toDateString())
            ->where('reason', EuServiceEvidence::REASON)
            ->withWhereHas('fiscalDocument', fn (Builder|BelongsTo $query) => $query->where('status', FiscalDocumentStatus::Active));
    }

    private function frequency(FiscalOperationEvidence $operation): ?int
    {
        $this->profiles ??= FiscalProfile::query()->inEffectiveOrder()->get();
        $profile = $this->profiles->first(fn (FiscalProfile $profile): bool => $profile->effective_from->lte($operation->operation_on));

        return $profile === null ? null : EuServiceEvidence::recapMonths($profile);
    }

    /** @return Builder<FiscalDocumentLink> */
    private function coveredLinks(string $column): Builder
    {
        return FiscalDocumentLink::query()->select($column)->whereNotNull($column)
            ->whereHas('fiscalDocument.fiscalOperationEvidence');
    }

    private static function forEuBusiness(Builder $receivable): void
    {
        $receivable->whereHasMorph('billable', [Project::class, Retainer::class, OneOffIncome::class],
            fn (Builder $billable) => $billable->whereHas('client', fn (Builder $client) => $client
                ->where(fn (Builder $legacy) => $legacy->where('seat', Seat::EuropeanUnion)->where('is_business', true))
                ->orWhere(fn (Builder $fiscal) => $fiscal->whereIn('fiscal_country_code', EuServiceEvidence::countryCodes())
                    ->where('fiscal_party_type', FiscalProfilePartyType::Business))));
    }
}
