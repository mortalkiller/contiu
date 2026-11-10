<?php

namespace App\Support;

use App\Exceptions\AccountIsReadOnly;
use App\Models\Expense;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

final class ExpenseEvidence
{
    public const DISK = 'local';

    public static function path(Expense $expense): ?string
    {
        $path = $expense->proof_path;
        $directory = self::directory((int) $expense->account_id).'/';

        if (! is_string($path) || ! str_starts_with($path, $directory) || ! str_ends_with($path, '.pdf')) {
            return null;
        }

        return Str::isUuid(substr($path, strlen($directory), -4)) ? $path : null;
    }

    /** @param array<string, mixed> $data */
    public static function save(Expense $expense, array $data): Expense
    {
        $file = Arr::pull($data, 'proof_upload');
        $remove = Arr::pull($data, 'remove_proof');

        if ($file === null && ! $remove) {
            $expense->fill($data)->save();

            return $expense;
        }

        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        $accountId = CurrentAccount::id();
        abort_if($expense->exists && (int) $expense->account_id !== $accountId, 404);

        $path = $file === null ? null : self::store($file, $accountId);
        $connection = $expense->getConnection();

        try {
            [$record, $previousPath] = $connection->transaction(function () use ($expense, $data, $path): array {
                $record = $expense->exists
                    ? Expense::query()->lockForUpdate()->findOrFail($expense->getKey())
                    : $expense;

                $previousPath = self::path($record);
                $record->proof_path = $path;
                $record->fill($data)->save();

                return [$record, $previousPath];
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $exception;
        }

        if ($previousPath !== null) {
            $connection->afterCommit(fn () => Storage::disk(self::DISK)->delete($previousPath));
        }

        return $record;
    }

    private static function store(mixed $file, int $accountId): string
    {
        if (! $file instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages(['data.proof_upload' => 'Seleciona um ficheiro PDF.']);
        }

        $path = self::directory($accountId).'/'.Str::uuid().'.pdf';
        $stream = $file->readStream();

        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['data.proof_upload' => 'Não foi possível guardar o comprovativo.']);
        }

        try {
            if (! Storage::disk(self::DISK)->put($path, $stream, 'private')) {
                throw ValidationException::withMessages(['data.proof_upload' => 'Não foi possível guardar o comprovativo.']);
            }
        } catch (FilesystemException $exception) {
            report($exception);

            throw ValidationException::withMessages(['data.proof_upload' => 'Não foi possível guardar o comprovativo.']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    public static function directory(int $accountId): string
    {
        return 'expense-proofs/'.$accountId;
    }
}
