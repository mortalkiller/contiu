<?php

namespace App\Modules\InvoiceReader;

use App\Modules\InvoiceReader\Benchmark\Console\PruneInvoiceBenchmark;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;

class InvoiceReaderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'invoice-reader');
        $this->commands([PruneInvoiceBenchmark::class]);

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
