<?php

namespace App\Support;

/**
 * Variation of a figure against the previous period ("+12%", "−5%").
 */
final class Change
{
    private function __construct(public readonly float $current, public readonly float $previous, public readonly ?float $percent) {}

    public static function between(float $current, float $previous): self
    {
        $percent = match (true) {
            abs($previous) < 0.005 && abs($current) < 0.005 => 0.0,
            abs($previous) < 0.005 => null,
            default => round(($current - $previous) / abs($previous) * 100, 1),
        };

        return new self($current, $previous, $percent);
    }

    public function isUp(): bool
    {
        return ($this->percent ?? 1) > 0;
    }

    public function isFlat(): bool
    {
        return $this->percent === 0.0;
    }

    public function label(): string
    {
        if ($this->percent === null) {
            return 'sem valor no período anterior';
        }

        $value = rtrim(rtrim(number_format(abs($this->percent), 1, ',', ' '), '0'), ',');

        return ($this->percent > 0 ? '+' : ($this->percent < 0 ? '−' : '')).$value.'%';
    }
}
