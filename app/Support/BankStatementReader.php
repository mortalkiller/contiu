<?php

namespace App\Support;

use DomainException;
use JsonException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class BankStatementReader
{
    /** @param array<string, int>|null $mapping */
    public function read(string $path, ?array $mapping = null): BankCsvResult
    {
        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false || ! function_exists('proc_open')) {
            throw new DomainException(BankCsvParser::READ_ERROR);
        }

        try {
            $process = new Process([
                $php,
                '-d', 'memory_limit=128M',
                '-d', 'display_errors=0',
                '-d', 'log_errors=0',
                base_path('bin/read-bank-statement.php'),
                $path,
                json_encode($mapping, JSON_THROW_ON_ERROR),
            ], timeout: 8);
            $process->run();
            $result = $process->isSuccessful()
                ? json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (ProcessTimedOutException) {
            throw new DomainException(BankCsvParser::LIMIT_ERROR);
        } catch (ExceptionInterface|JsonException) {
            throw new DomainException(BankCsvParser::READ_ERROR);
        }

        if (in_array($result['error'] ?? null, [BankCsvParser::LIMIT_ERROR, BankCsvParser::FORMULA_ERROR], true)) {
            throw new DomainException($result['error']);
        }
        if (! isset($result['rows'], $result['headers'], $result['delimiter'], $result['needs_mapping'])) {
            throw new DomainException(BankCsvParser::READ_ERROR);
        }

        return new BankCsvResult($result['rows'], $result['headers'], $result['delimiter'], $result['needs_mapping']);
    }
}
