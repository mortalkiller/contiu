<?php

namespace App\Modules\InvoiceReader;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\StreamableAgentResponse;
use LogicException;

class VertexAiProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;

    public const string MODEL = 'gemini-3.1-flash-lite';

    public function defaultTextModel(): string
    {
        return (string) config('invoice-benchmark.model');
    }

    public function cheapestTextModel(): string
    {
        return $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->defaultTextModel();
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new LogicException('The invoice benchmark supports single-response extraction only.');
    }
}
