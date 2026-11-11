<?php

namespace Database\Factories;

use App\Enums\IntegrationCategory;
use App\Models\IntegrationConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationConnection> */
class IntegrationConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => IntegrationCategory::Invoicing,
            'provider' => 'invoicexpress',
            'credentials' => ['subdomain' => 'contiu-test', 'api_key' => 'fake-key'],
            'settings' => [],
        ];
    }
}
