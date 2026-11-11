<?php

namespace Database\Factories;

use App\Models\FiscalDocumentDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FiscalDocumentDraft> */
class FiscalDocumentDraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'invoicexpress',
            'provider_account' => 'demo',
            'document_type' => 'invoice',
            'payload' => ['customerId' => 42, 'itemId' => 8, 'quantity' => 1],
            'preview' => ['totals' => ['net' => 10000, 'tax' => 2300, 'total' => 12300]],
            'revision' => fake()->sha256(),
        ];
    }
}
