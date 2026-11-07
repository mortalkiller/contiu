<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A whole year, or one month of it. previous() is the period of the same length just before.
 */
final class Period
{
    public const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public const SHORT_MONTHS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    private function __construct(public readonly int $year, public readonly ?int $month = null)
    {
        $this->start = $month === null
            ? CarbonImmutable::create($year, 1, 1)
            : CarbonImmutable::create($year, $month, 1);
        $this->end = $month === null ? $this->start->endOfYear() : $this->start->endOfMonth();
    }

    public static function of(int $year, ?int $month = null): self
    {
        return new self($year, $month ?: null);
    }

    public function isMonth(): bool
    {
        return $this->month !== null;
    }

    public function previous(): self
    {
        if ($this->month === null) {
            return new self($this->year - 1);
        }

        $previous = $this->start->subMonth();

        return new self($previous->year, $previous->month);
    }

    public function label(): string
    {
        return $this->month === null ? (string) $this->year : self::MONTHS[$this->month - 1].' '.$this->year;
    }

    /**
     * Bounds for whereBetween() on date columns. The end is the last second of the period because
     * SQLite stores dates as "Y-m-d H:i:s" strings, so a bare "Y-m-d" end would drop the last day.
     *
     * @return array{0: string, 1: string}
     */
    public function range(): array
    {
        return [$this->start->startOfDay()->toDateTimeString(), $this->end->endOfDay()->toDateTimeString()];
    }
}
