<?php

namespace App\Support\Invoicing;

use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Models\Article;
use App\Models\Client;
use App\Models\IntegrationConnection;
use App\Models\IntegrationOperation;
use App\Models\IntegrationRecord;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the app keeps what the invoicing provider knows: the id each record has there and the
 * log of what was asked of it. Every action goes through here, so no one else touches the tables.
 */
final class InvoicingRecords
{
    public static function for(Model $record): ?IntegrationRecord
    {
        $connection = Invoicing::connection();

        return $connection === null ? null : IntegrationRecord::query()
            ->where('integration_connection_id', $connection->getKey())
            ->whereMorphedTo('record', $record)
            ->first();
    }

    public static function remoteId(Model $record): ?string
    {
        return self::for($record)?->remote_id;
    }

    /** Ties a local record to its counterpart at the provider, or updates what was last read of it. */
    public static function store(Model $record, RemoteDocument $remote, IntegrationRecordState $state): IntegrationRecord
    {
        $entry = IntegrationRecord::query()->firstOrNew([
            'integration_connection_id' => self::connection()->getKey(),
            'remote_type' => $remote->remoteType,
            'remote_id' => $remote->remoteId,
        ]);

        $entry->record()->associate($record);
        $entry->fill(['state' => $state, 'remote' => $remote->summary(), 'remote_hash' => $remote->hash(), 'synced_at' => now()])->save();

        return $entry;
    }

    /** The client's id at the provider, creating the client there the first time it is needed. */
    public static function clientId(Client $client): string
    {
        return self::ensure($client, 'clients', fn (): string => Invoicing::provider()->saveClient($client));
    }

    /** The article's id at the provider, creating the article there the first time it is billed. */
    public static function articleId(Article $article): string
    {
        return self::ensure($article, 'items', fn (): string => Invoicing::provider()->saveArticle($article));
    }

    /** @param Closure(): string $create */
    private static function ensure(Model $record, string $remoteType, Closure $create): string
    {
        $entry = self::for($record);

        if ($entry !== null) {
            return $entry->remote_id;
        }

        $remoteId = $create();

        IntegrationRecord::query()->forceCreate([
            'integration_connection_id' => self::connection()->getKey(),
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'remote_type' => $remoteType,
            'remote_id' => $remoteId,
            'state' => IntegrationRecordState::InSync,
            'synced_at' => now(),
        ]);

        return $remoteId;
    }

    public static function log(
        string $kind,
        IntegrationOperationStatus $status,
        string $summary,
        ?Model $subject = null,
        ?string $detail = null,
    ): IntegrationOperation {
        $operation = new IntegrationOperation([
            'integration_connection_id' => self::connection()->getKey(),
            'user_id' => auth()->id(),
            'kind' => $kind,
            'status' => $status,
            'summary' => $summary,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 1000),
        ]);

        if ($subject !== null) {
            $operation->subject()->associate($subject);
        }

        $operation->save();

        return $operation;
    }

    /**
     * Marks that something which cannot be repeated is about to be asked of the provider. Null
     * means an earlier attempt left its mark and never finished, so it may have gone through.
     */
    public static function pending(string $key, Model $subject): ?IntegrationOperation
    {
        $existing = IntegrationOperation::query()->where('key', $key)->first();

        if ($existing !== null) {
            return $existing->status === IntegrationOperationStatus::Failed ? tap($existing)->update(['status' => IntegrationOperationStatus::Pending]) : null;
        }

        $operation = new IntegrationOperation([
            'integration_connection_id' => self::connection()->getKey(),
            'user_id' => auth()->id(),
            'kind' => 'issued',
            'status' => IntegrationOperationStatus::Pending,
            'key' => $key,
            'summary' => __('Receipt sent to the provider'),
        ]);
        $operation->subject()->associate($subject);
        $operation->save();

        return $operation;
    }

    private static function connection(): IntegrationConnection
    {
        return Invoicing::connection() ?? abort(409, __('Connect an invoicing provider first.'));
    }
}
