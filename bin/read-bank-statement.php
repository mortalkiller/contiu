<?php

use App\Support\BankCsvParser;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $mapping = json_decode($argv[2], associative: true, flags: JSON_THROW_ON_ERROR);
    $result = BankCsvParser::parseFile($argv[1], $mapping);
    echo json_encode([
        'rows' => $result->rows,
        'headers' => $result->headers,
        'delimiter' => $result->delimiter,
        'needs_mapping' => $result->needsMapping(),
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $exception) {
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}
