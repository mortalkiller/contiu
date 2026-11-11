<?php

namespace App\Support;

use App\Enums\Language;
use Carbon\CarbonImmutable;

/**
 * A whole year, or one month of it. previous() is the period of the same length just before.
 */
final class Period
{
    public const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public const SHORT_MONTHS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    private const ENGLISH_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const ENGLISH_SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

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
        return $this->month === null ? (string) $this->year : __(':month :year', ['month' => self::monthName($this->month), 'year' => $this->year]);
    }

    public static function monthName(int $month, bool $short = false, bool $inSentence = false): string
    {
        $language = Language::resolve(app()->getLocale());
        $name = ($short ? self::ENGLISH_SHORT_MONTHS : self::ENGLISH_MONTHS)[$month - 1];

        if ($short && $month === 5 && $language === Language::PortuguesePortugal) {
            $name = __('May (short month)');
        } else {
            $name = __($name);
        }

        return $inSentence && $language === Language::PortuguesePortugal ? mb_strtolower($name) : $name;
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
