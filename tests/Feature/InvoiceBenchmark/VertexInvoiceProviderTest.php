<?php

use App\Support\InvoiceBenchmark\GoogleAccessTokenProvider;
use App\Support\InvoiceBenchmark\InvoiceExtractionAgent;
use App\Support\InvoiceBenchmark\VertexAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;

it('uses the official agent and Gemini gateway on the EU Interactions endpoint', function (string $type) {
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => true, 'invoice-benchmark.project' => 'contiu-test']);
    $this->mock(GoogleAccessTokenProvider::class)->shouldReceive('token')->once()->andReturn('fake-token');
    $endpoint = 'https://aiplatform.eu.rep.googleapis.com/v1/projects/contiu-test/locations/eu/interactions';
    Http::preventStrayRequests();
    Http::fake([$endpoint => Http::response([
        'status' => 'completed',
        'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"invoice_number":"FT TEST/1"}']]]],
        'usage' => ['total_input_tokens' => 200, 'total_output_tokens' => 20, 'total_thought_tokens' => 2, 'total_cached_tokens' => 10, 'total_tokens' => 222],
    ])]);
    $file = $type === 'image' ? new Base64Image('dGVzdA==', 'image/png') : new Base64Document('dGVzdA==', 'application/pdf');

    $response = (new InvoiceExtractionAgent)->prompt('Extract invoice', [$file], provider: 'vertex');

    expect(Ai::textProvider('vertex'))->toBeInstanceOf(VertexAiProvider::class);
    expect($response['invoice_number'])->toBe('FT TEST/1');
    expect($response->usage->inputTokens)->toBe(200);
    Http::assertSent(fn (Request $request): bool => $request->url() === $endpoint
        && $request->hasHeader('Authorization', 'Bearer fake-token')
        && ! $request->hasHeader('x-goog-api-key')
        && $request['model'] === 'gemini-3.1-flash-lite'
        && $request['store'] === false
        && $request['response_format']['mime_type'] === 'application/json'
        && data_get($request->data(), 'input.0.content.1.type') === $type
        && data_get($request->data(), 'input.0.content.1.data') === 'dGVzdA==');
})->with(['image', 'document']);

it('rejects unsupported routing before resolving credentials or sending data', function (string $setting, string $value) {
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => true, 'invoice-benchmark.project' => 'contiu-test', "invoice-benchmark.{$setting}" => $value]);
    Http::preventStrayRequests();

    expect(fn () => (new InvoiceExtractionAgent)->prompt('Extract invoice', provider: 'vertex'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([['location', 'global'], ['location', 'us'], ['project', '../unsafe'], ['model', 'another-model']]);
