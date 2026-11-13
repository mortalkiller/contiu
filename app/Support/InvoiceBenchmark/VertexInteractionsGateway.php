<?php

namespace App\Support\InvoiceBenchmark;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use RuntimeException;
use Throwable;

class VertexInteractionsGateway extends GeminiGateway
{
    public function __construct(Dispatcher $events, private GoogleAccessTokenProvider $tokens)
    {
        parent::__construct($events);
    }

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $project = (string) config('invoice-benchmark.project');

        if (! config('invoice-benchmark.enabled') || ! config('invoice-benchmark.live')) {
            throw new RuntimeException('Live invoice AI processing is disabled.');
        }

        if (config('invoice-benchmark.provider') !== 'vertex' || config('invoice-benchmark.location') !== 'eu' || ! preg_match('/\A[a-z][a-z0-9-]{4,28}[a-z0-9]\z/', $project)) {
            throw new RuntimeException('Vertex requires a valid Google Cloud project ID and the EU multi-region.');
        }

        $client = $this->createClient(
            "https://aiplatform.eu.rep.googleapis.com/v1/projects/{$project}/locations/eu",
            ['Authorization' => 'Bearer '.$this->tokens->token($project), 'x-goog-user-project' => $project],
            timeout: min($timeout ?? 25, 25),
        )->connectTimeout(5)->withoutRedirecting();

        $runId = Context::getHidden('invoice_benchmark_run_id');
        if (is_int($runId)) {
            $client->beforeSending(function () use ($runId): void {
                Context::addHidden('invoice_benchmark_request', InvoiceRequestMetrics::start($runId));
            })->afterResponse(function (Response $response): void {
                $this->finishAttempt($response);
            });
        }

        return $client;
    }

    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        if ($model !== VertexAiProvider::MODEL || config('invoice-benchmark.model') !== $model
            || count($messages) !== 1 || $tools !== [] || $stepContext->stepNumber > 1) {
            throw new RuntimeException('Vertex invoice extraction requires the approved model and a single stateless prompt.');
        }

        $body = parent::buildStepBody($provider, $model, $instructions, $messages, [], $schema, $options, $stepContext);
        $body['generation_config'] = ['max_output_tokens' => 8192, 'thinking_level' => 'minimal'];
        $body['store'] = false;

        return $body;
    }

    protected function withErrorHandling(string $providerName, Closure $callback): mixed
    {
        try {
            return parent::withErrorHandling($providerName, $callback);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && in_array($exception->getMessage(), [
                'Live invoice AI processing is disabled.',
                'Vertex requires a valid Google Cloud project ID and the EU multi-region.',
                'Google Application Default Credentials could not provide a Vertex access token.',
            ], true)) {
                throw $exception;
            }

            throw new RuntimeException(match ($exception->getCode()) {
                401, 403 => 'Vertex rejected the configured Google Cloud identity.',
                404 => 'The configured Gemini model or Interactions endpoint is unavailable in the EU.',
                429 => 'Vertex reached its request quota or rate limit.',
                default => 'The EU Vertex request failed. Verify the project, ADC and Vertex API configuration.',
            });
        } finally {
            $this->finishAttempt(null);
        }
    }

    private function finishAttempt(?Response $response): void
    {
        $attempt = Context::getHidden('invoice_benchmark_request');
        Context::forgetHidden('invoice_benchmark_request');
        if ($attempt instanceof InvoiceRequestMetrics) {
            $attempt->finish($response);
        }
    }

    protected function validateTextResponse(array $data): void
    {
        if (($data['status'] ?? null) !== 'completed') {
            throw new RuntimeException('The EU Vertex interaction did not complete.');
        }
        try {
            parent::validateTextResponse($data);
        } catch (Throwable) {
            throw new RuntimeException('The EU Vertex interaction returned an invalid response.');
        }
    }

    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        // A timeout or server error can follow a billable generation. Only retry an explicit throttling response.
        $delay = $exception->getCode() === 429 ? parent::retryAfterSeconds($exception) : null;

        return $delay !== null && $delay <= 3 ? $delay : null;
    }
}
