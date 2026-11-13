<?php

namespace Database\Factories;

use App\Enums\FiscalDocumentType;
use App\Enums\InvoiceImportStatus;
use App\Models\InvoiceImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @extends Factory<InvoiceImport> */
class InvoiceImportFactory extends Factory
{
    /** A PDF invoice already read: 10,00 € + 23% from a supplier with a valid tax ID. */
    public function definition(): array
    {
        return [
            'file_id' => Str::uuid()->toString(),
            'filename' => 'fatura.pdf',
            'mime' => 'application/pdf',
            'bytes' => 1024,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'dimensions' => ['pages' => 1],
            'status' => InvoiceImportStatus::Read,
            'data' => [
                'type' => FiscalDocumentType::InvoiceReceipt->value,
                'number' => 'FR 5512',
                'date' => '2026-11-03',
                'due_date' => null,
                'atcud' => 'JJ37MN8X-5512',
                'supplier_name' => 'Papelaria Demo, Lda',
                'supplier_nif' => '509876544',
                'customer_name' => null,
                'customer_nif' => null,
                'net' => 1000,
                'vat' => 230,
                'total' => 1230,
                'rates' => [['rate' => '0.2300', 'net' => 1000, 'vat' => 230]],
                'lines' => [],
                'paid' => true,
                'exact' => ['number', 'date', 'supplier_nif', 'total', 'vat'],
            ],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (InvoiceImport $import): bool => Storage::disk(InvoiceImport::DISK)
            ->put($import->storagePath(), "%PDF-1.4\n% {$import->file_id}\n%%EOF\n", 'private'));
    }

    /** @param array<string, mixed> $data */
    public function read(array $data): static
    {
        return $this->state(fn (array $attributes): array => ['data' => [...$attributes['data'], ...$data]]);
    }

    public function reading(): static
    {
        return $this->state(['status' => InvoiceImportStatus::Queued, 'data' => null]);
    }

    public function unreadable(): static
    {
        return $this->state(['status' => InvoiceImportStatus::Unreadable, 'data' => null, 'error' => 'unreadable']);
    }
}
