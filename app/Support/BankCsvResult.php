<?php

namespace App\Support;

final class BankCsvResult
{
    /**
     * @param  array<int, array{booked_on: string, description: string, amount: int, balance: ?int}>  $rows
     * @param  array<int, string>  $headers
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $headers,
        public readonly string $delimiter,
        private readonly bool $mappingNeeded,
    ) {}

    public function needsMapping(): bool
    {
        return $this->mappingNeeded;
    }
}
