<?php

namespace Database\Factories;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalDocument> */
class FiscalDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => FiscalDocumentType::Invoice,
            'direction' => FiscalDocumentDirection::Issued,
            'number' => fake()->unique()->numerify('FT 2026/#####'),
            'origin' => 'Registo manual',
            'document_date' => today(),
            'currency' => 'EUR',
            'net_amount' => 10000,
            'vat_amount' => 2300,
            'total_amount' => 12300,
        ];
    }

    /** Still being written: no number, and it counts for nothing until it is issued. */
    public function draft(): static
    {
        return $this->state(['status' => FiscalDocumentStatus::Draft, 'number' => null]);
    }
}
