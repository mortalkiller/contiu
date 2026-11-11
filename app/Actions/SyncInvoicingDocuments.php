<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Exceptions\InvoicingProviderException;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRecord;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingProvider;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Compares what the provider issued in a period with what the app holds. It never changes an
 * issued document: it records which ones match, which differ and which only exist at the
 * provider, and it completes drafts that were issued there instead of here.
 */
final class SyncInvoicingDocuments
{
    public const int MAX_DAYS = 366;

    public const int MAX_PAGES = 25;

    /** How many drafts are checked for having been issued at the provider, per run. */
    private const int DRAFTS_PER_RUN = 25;

    public function __construct(private readonly IssueFiscalDocument $issuing) {}

    /** @return array{in_sync: int, differs: int, remote_only: int, issued: int} */
    public function handle(?CarbonInterface $from = null, ?CarbonInterface $until = null): array
    {
        // A scheduled run has no user; someone at the screen has to be allowed to issue.
        abort_if(auth()->check() && ! Invoicing::canIssue(), 403);

        $connection = Invoicing::connection() ?? throw ValidationException::withMessages(['from' => __('Connect an invoicing provider first.')]);
        $provider = Invoicing::provider();
        $until ??= today();
        $from ??= $connection->last_synced_at?->subDays(2)->startOfDay() ?? today()->subDays(90);

        if ($from->greaterThan($until) || $from->diffInDays($until) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['until' => __('Choose a valid date range of up to 366 days.')]);
        }

        $counts = ['in_sync' => 0, 'differs' => 0, 'remote_only' => 0, 'issued' => 0];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $result = $provider->documents($from, $until, $page);
                $this->compare($connection, collect($result->documents), $counts);

                if (! $result->hasMore) {
                    break;
                }
            }

            $counts['issued'] += $this->completeDraftsIssuedAtProvider($connection, $provider);
        } catch (InvoicingProviderException $exception) {
            $connection->update(['last_error' => mb_substr($exception->getMessage(), 0, 255)]);
            InvoicingRecords::log('synced', IntegrationOperationStatus::Failed, __('Synchronisation failed'), detail: $exception->getMessage());

            throw $exception;
        }

        $connection->update(['last_synced_at' => now(), 'last_error' => null]);
        InvoicingRecords::log('synced', IntegrationOperationStatus::Done, $this->summary($counts));

        return $counts;
    }

    /**
     * @param  Collection<int, RemoteDocument>  $documents
     * @param  array{in_sync: int, differs: int, remote_only: int, issued: int}  $counts
     */
    private function compare(IntegrationConnection $connection, Collection $documents, array &$counts): void
    {
        $documents = $documents->reject(fn (RemoteDocument $remote): bool => $remote->state === RemoteDocumentState::Draft)->values();

        if ($documents->isEmpty()) {
            return;
        }

        // Everything the page needs in two queries: the records already known and the documents that share a number.
        $entries = IntegrationRecord::query()
            ->where('integration_connection_id', $connection->getKey())
            ->whereIn('remote_id', $documents->pluck('remoteId'))
            ->with('record')
            ->get()
            ->keyBy(fn (IntegrationRecord $entry): string => "{$entry->remote_type}:{$entry->remote_id}");
        $byNumber = FiscalDocument::query()
            ->where('direction', FiscalDocumentDirection::Issued)
            ->whereIn('number', $documents->pluck('number')->filter())
            ->doesntHave('integrationRecord')
            ->get()
            ->keyBy(fn (FiscalDocument $document): string => "{$document->type->value}:{$document->number}");

        foreach ($documents as $remote) {
            $entry = $entries->get("{$remote->remoteType}:{$remote->remoteId}");
            $local = $entry?->record instanceof FiscalDocument ? $entry->record : $byNumber->get("{$remote->type->value}:{$remote->number}");

            if ($local === null) {
                $this->remember($connection, $entry, $remote);
                $counts['remote_only']++;

                continue;
            }

            if ($local->isDraft() && $remote->isIssued()) {
                $local = $this->issuing->complete($local, $remote);
                $counts['issued']++;
            }

            $state = $this->state($local, $remote);
            InvoicingRecords::store($local, $remote, $state);
            $counts[$state === IntegrationRecordState::InSync ? 'in_sync' : 'differs']++;
        }
    }

    /** A draft issued at the provider itself never shows in its list of drafts, so each is asked for. */
    private function completeDraftsIssuedAtProvider(IntegrationConnection $connection, InvoicingProvider $provider): int
    {
        $issued = 0;

        IntegrationRecord::query()
            ->where('integration_connection_id', $connection->getKey())
            ->whereHasMorph('record', [FiscalDocument::class], fn ($query) => $query->where('status', FiscalDocumentStatus::Draft))
            ->with('record')
            ->oldest('synced_at')
            ->limit(self::DRAFTS_PER_RUN)
            ->get()
            ->each(function (IntegrationRecord $entry) use ($provider, &$issued): void {
                $remote = $provider->find($entry->remote_type, $entry->remote_id);

                if ($remote->isIssued() && $remote->number !== null) {
                    $this->issuing->complete($entry->record, $remote);
                    $issued++;
                } else {
                    $entry->update(['synced_at' => now()]);
                }
            });

        return $issued;
    }

    private function remember(IntegrationConnection $connection, ?IntegrationRecord $entry, RemoteDocument $remote): void
    {
        $entry ??= new IntegrationRecord(['integration_connection_id' => $connection->getKey(), 'remote_type' => $remote->remoteType, 'remote_id' => $remote->remoteId]);
        $entry->fill(['state' => IntegrationRecordState::RemoteOnly, 'remote' => $remote->summary(), 'remote_hash' => $remote->hash(), 'synced_at' => now()])->save();
    }

    private function state(FiscalDocument $local, RemoteDocument $remote): IntegrationRecordState
    {
        $same = $remote->matches($local->net_amount, $local->vat_amount, $local->total_amount)
            && $remote->number === $local->number
            && ($remote->state === RemoteDocumentState::Canceled) === ($local->status === FiscalDocumentStatus::Voided);

        return $same ? IntegrationRecordState::InSync : IntegrationRecordState::Differs;
    }

    /** @param array{in_sync: int, differs: int, remote_only: int, issued: int} $counts */
    private function summary(array $counts): string
    {
        return match (true) {
            $counts['remote_only'] > 0 => trans_choice('{1} Synchronised · 1 document to import|[2,*] Synchronised · :count documents to import', $counts['remote_only']),
            $counts['differs'] > 0 => trans_choice('{1} Synchronised · 1 difference|[2,*] Synchronised · :count differences', $counts['differs']),
            default => __('Synchronised · no changes'),
        };
    }
}
