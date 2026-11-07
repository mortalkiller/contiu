<?php

namespace App\Actions;

use App\Enums\BankTransactionStatus;
use App\Models\BankTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores parsed statement lines as pending bank transactions. Each line gets a fingerprint
 * (date, amount, description, balance and its position among identical lines), so uploading the
 * same statement again — or one that overlaps — adds only the new lines.
 */
class ImportBankStatement
{
    /**
     * @param  array<int, array{booked_on: string, description: string, amount: float, balance: ?float}>  $rows
     * @return array{created: int, skipped: int}
     */
    public function store(array $rows, ?string $batch = null): array
    {
        $batch ??= (string) Str::ulid();
        $seen = [];
        $created = 0;

        DB::transaction(function () use ($rows, $batch, &$seen, &$created): void {
            foreach ($rows as $row) {
                $base = implode('|', [$row['booked_on'], number_format((float) $row['amount'], 2, '.', ''), Str::lower(trim($row['description'])), $row['balance'] === null ? '' : number_format((float) $row['balance'], 2, '.', '')]);
                $occurrence = $seen[$base] = ($seen[$base] ?? 0) + 1;
                $hash = sha1($base.'|'.$occurrence);

                if (BankTransaction::where('hash', $hash)->exists()) {
                    continue;
                }

                BankTransaction::create([
                    'hash' => $hash,
                    'booked_on' => $row['booked_on'],
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'balance' => $row['balance'],
                    'status' => BankTransactionStatus::Pending,
                    'import_batch' => $batch,
                ]);

                $created++;
            }
        });

        return ['created' => $created, 'skipped' => count($rows) - $created];
    }
}
