<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalDocumentLine> */
class FiscalDocumentLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fiscal_document_id' => FiscalDocument::factory(),
            'description' => fake()->sentence(3),
            'quantity' => FiscalDocumentLine::QUANTITY_SCALE,
            'unit' => 'un',
            'unit_price' => 10000,
            'net_amount' => 10000,
            'vat_amount' => 0,
            'total_amount' => 10000,
        ];
    }
}
