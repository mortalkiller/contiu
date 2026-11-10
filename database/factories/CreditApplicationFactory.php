<?php

namespace Database\Factories;

use App\Enums\FiscalDocumentType;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
use App\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditApplication> */
class CreditApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receivable_id' => Receivable::factory()->state(['amount' => 80000]),
            'invoice_id' => FiscalDocument::factory()->state(['net_amount' => 80000, 'vat_amount' => 0, 'total_amount' => 80000]),
            'credit_note_id' => FiscalDocument::factory()->state(['type' => FiscalDocumentType::CreditNote, 'net_amount' => -15000, 'vat_amount' => 0, 'total_amount' => -15000]),
            'amount' => 15000,
            'applied_on' => today(),
        ];
    }
}
