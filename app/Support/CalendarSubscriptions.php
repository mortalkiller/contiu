<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class CalendarSubscriptions
{
    public function isConfigured(): bool
    {
        return $this->current()->exists();
    }

    /** @return array{url: string, includeReceivables: bool}|null */
    public function settings(): ?array
    {
        $row = $this->current()->first(['encrypted_token', 'include_receivables']);

        return $row === null ? null : [
            'url' => route('calendar-subscriptions.show', ['token' => Crypt::decryptString($row->encrypted_token)]),
            'includeReceivables' => (bool) $row->include_receivables,
        ];
    }

    public function create(): void
    {
        $token = bin2hex(random_bytes(32));

        DB::table('calendar_subscriptions')->insertOrIgnore([
            'account_id' => CurrentAccount::id(),
            'token_hash' => hash('sha256', $token),
            'encrypted_token' => Crypt::encryptString($token),
        ]);
    }

    public function setIncludeReceivables(bool $include): void
    {
        $this->current()->update(['include_receivables' => $include]);
    }

    public function revoke(): void
    {
        $this->current()->delete();
    }

    /** @return array{accountId: int, includeReceivables: bool}|null */
    public function findByToken(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $row = DB::table('calendar_subscriptions')
            ->where('token_hash', $hash)
            ->first(['account_id', 'token_hash', 'include_receivables']);

        if ($row === null || ! hash_equals($row->token_hash, $hash)) {
            return null;
        }

        return [
            'accountId' => (int) $row->account_id,
            'includeReceivables' => (bool) $row->include_receivables,
        ];
    }

    private function current(): Builder
    {
        return DB::table('calendar_subscriptions')->where('account_id', CurrentAccount::id());
    }
}
