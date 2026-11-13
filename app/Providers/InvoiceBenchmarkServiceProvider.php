<?php

namespace App\Providers;

use App\Support\InvoiceBenchmark\GoogleAccessTokenProvider;
use App\Support\InvoiceBenchmark\VertexAiProvider;
use App\Support\InvoiceBenchmark\VertexInteractionsGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;

class InvoiceBenchmarkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config(['ai.providers.vertex' => ['driver' => 'vertex']]);

        Ai::extend('vertex', function ($app, array $config): VertexAiProvider {
            $events = $app->make(Dispatcher::class);

            return new VertexAiProvider(
                new VertexInteractionsGateway($events, $app->make(GoogleAccessTokenProvider::class)),
                $config,
                $events,
            );
        });
    }
}
