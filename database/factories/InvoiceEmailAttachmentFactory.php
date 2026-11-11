<?php

namespace Database\Factories;

use App\Models\InvoiceEmailAttachment;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @extends Factory<InvoiceEmailAttachment> */
class InvoiceEmailAttachmentFactory extends Factory
{
    public function definition(): array
    {
        $fileId = Str::uuid()->toString();

        return [
            'message_hash' => hash('sha256', Str::uuid()->toString()),
            'pdf_hash' => hash('sha256', $this->fixture($fileId)),
            'file_id' => $fileId,
            'filename' => 'invoice-email-sample.pdf',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (InvoiceEmailAttachment $attachment): bool => Storage::disk(InvoiceEmailAttachment::DISK)
            ->put($attachment->storagePath(), $this->fixture($attachment->file_id), 'private'));
    }

    protected function store(Collection $results): void
    {
        DB::transaction(function () use ($results): void {
            foreach ($results as $attachment) {
                $attachment->account_id ??= CurrentAccount::id();

                DB::table('invoice_email_deliveries')->insert([
                    'account_id' => $attachment->account_id,
                    'message_hash' => $attachment->message_hash,
                    'received_at' => now(),
                ]);
            }

            parent::store($results);
        });
    }

    private function fixture(string $fileId): string
    {
        return file_get_contents(resource_path('fixtures/invoice-email-sample.pdf')).'% '.$fileId."\n";
    }
}
