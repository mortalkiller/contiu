<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\InvoiceEmailAttachment;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentQr;
use DomainException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class IntakeInvoiceEmail
{
    public const MAX_PDF_KILOBYTES = 10 * 1024;

    private const MAX_MESSAGE_BYTES = 20 * 1024 * 1024;

    private const MAX_ATTACHMENTS = 20;

    public static function canSimulate(): bool
    {
        $account = CurrentAccount::get();

        return config('contiu.invoice_email_inbox_enabled')
            && app()->environment(['local', 'testing'])
            && $account !== null
            && AccountantAccess::canManage($account)
            && ! CurrentAccount::readOnly();
    }

    public function currentRecipient(): ?string
    {
        abort_unless(self::canSimulate(), 403);

        return DB::table('invoice_email_aliases')->where('account_id', CurrentAccount::id())->value('recipient');
    }

    public function recipient(bool $rotate = false): string
    {
        abort_unless(self::canSimulate(), 403);

        return DB::transaction(function () use ($rotate): string {
            $account = Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $recipient = $this->currentRecipient();
            if ($recipient !== null && ! $rotate) {
                return $recipient;
            }

            $recipient = 'bills-'.bin2hex(random_bytes(16)).'@inbound.example.test';
            DB::table('invoice_email_aliases')->updateOrInsert(['account_id' => $account->id], ['recipient' => $recipient]);

            return $recipient;
        });
    }

    /**
     * @param  list<string>  $envelopeRecipients  SMTP envelope recipients supplied by the simulator.
     * @param  list<array{name: string, bytes: string, qr?: ?string}>  $attachments  Decoded synthetic attachments.
     * @return int Number of PDFs queued or restored.
     */
    public function receive(string $messageId, array $envelopeRecipients, array $attachments): int
    {
        abort_unless(self::canSimulate(), 403);

        $messageId = trim($messageId);
        if ($messageId === '' || strlen($messageId) > 255 || count($envelopeRecipients) > 100) {
            throw new DomainException(__('Invalid simulated message.'));
        }

        $messageHash = hash('sha256', $messageId);
        $recipients = array_unique(array_map(fn (string $address): string => strtolower(trim($address)), $envelopeRecipients));
        $newPaths = [];
        $disk = Storage::disk(InvoiceEmailAttachment::DISK);

        try {
            return DB::transaction(function () use ($messageHash, $recipients, $attachments, $disk, &$newPaths): int {
                $accountId = CurrentAccount::id();
                Account::query()->lockForUpdate()->findOrFail($accountId);
                $accountIds = DB::table('invoice_email_aliases')->whereIn('recipient', $recipients)->pluck('account_id');
                if ($accountIds->count() !== 1 || (int) $accountIds->first() !== $accountId) {
                    throw new DomainException(__('The simulated message must target a single active alias for this account.'));
                }

                $deliveries = DB::table('invoice_email_deliveries');
                $delivery = ['account_id' => $accountId, 'message_hash' => $messageHash];
                $this->validateLimits($attachments);
                $replay = (clone $deliveries)->where($delivery)->exists();
                if (! $replay) {
                    $deliveries->insert($delivery + ['received_at' => now()]);
                }

                $restored = 0;
                foreach ($attachments as $attachment) {
                    $bytes = $attachment['bytes'];
                    if (! str_starts_with($bytes, '%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'application/pdf') {
                        continue;
                    }

                    $hash = hash('sha256', $bytes);
                    $existing = InvoiceEmailAttachment::query()->where('pdf_hash', $hash)->first();
                    if ($existing !== null) {
                        if (! $disk->exists($existing->storagePath())) {
                            $this->storePdf($disk, $existing->storagePath(), $bytes);
                            $restored++;
                        }

                        continue;
                    }

                    if ($replay) {
                        continue;
                    }

                    $qr = $attachment['qr'] ?? null;
                    if ($qr !== null && strlen($qr) > ExpenseDocumentQr::MAX_LENGTH) {
                        throw new DomainException(__('The QR code is too long.'));
                    }

                    $filename = Str::limit(Str::ascii(pathinfo(basename(str_replace('\\', '/', $attachment['name'])), PATHINFO_FILENAME)), 120, '');
                    $filename = trim(preg_replace('/[^a-zA-Z0-9._ -]/', '_', $filename), '. ');
                    $record = InvoiceEmailAttachment::create([
                        'message_hash' => $messageHash,
                        'pdf_hash' => $hash,
                        'file_id' => Str::uuid()->toString(),
                        'filename' => ($filename ?: 'invoice').'.pdf',
                        'qr_text' => $qr,
                    ]);

                    $path = $record->storagePath();
                    $this->storePdf($disk, $path, $bytes);
                    $newPaths[] = $path;
                }

                return count($newPaths) + $restored;
            });
        } catch (Throwable $exception) {
            if ($newPaths !== []) {
                $disk->delete($newPaths);
            }

            throw $exception;
        }
    }

    private function storePdf(FilesystemAdapter $disk, string $path, string $bytes): void
    {
        $staged = $path.'.part';
        $moved = false;

        try {
            $moved = $disk->put($staged, $bytes, 'private') && $disk->move($staged, $path);
            if (! $moved) {
                throw new DomainException(__('Could not save the supporting document.'));
            }
        } finally {
            if (! $moved) {
                $disk->delete($staged);
            }
        }
    }

    /** @param list<array{name: string, bytes: string, qr?: ?string}> $attachments */
    private function validateLimits(array $attachments): void
    {
        $sizes = array_map(fn (array $attachment): int => strlen($attachment['bytes']), $attachments);

        if (count($attachments) > self::MAX_ATTACHMENTS || array_sum($sizes) > self::MAX_MESSAGE_BYTES) {
            throw new DomainException(__('Simulated messages can contain up to 20 attachments and 20 MiB of attachments.'));
        }

        if ($sizes !== [] && max($sizes) > self::MAX_PDF_KILOBYTES * 1024) {
            throw new DomainException(__('Each simulated attachment can be up to 10 MiB.'));
        }
    }
}
