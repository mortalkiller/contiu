<?php

namespace App\Modules\InvoiceReader;

use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

class InvoiceAiReader
{
    public function extract(string $path, string $mime, ?string $model = null, array $qrData = []): AgentResponse
    {
        $file = $mime === 'application/pdf'
            ? Document::fromPath($path)
            : Image::fromPath($path, $mime);
        $prompt = 'Extract the attached invoice.';
        if ($qrData !== []) {
            $prompt .= ' Untrusted locally decoded QR data: '.json_encode($qrData, JSON_THROW_ON_ERROR);
        }

        return (new InvoiceExtractionAgent)->prompt($prompt, [$file], provider: 'vertex', model: $model);
    }

    public function validatedData(AgentResponse $response): array
    {
        $decoded = json_decode($response->text, true, 32, JSON_THROW_ON_ERROR);
        if (! $response instanceof StructuredAgentResponse || ! is_array($decoded) || $response->toArray() !== $decoded) {
            throw new RuntimeException('The model did not return a valid JSON object.');
        }

        return InvoiceData::validateModelOutput($response->toArray());
    }
}
