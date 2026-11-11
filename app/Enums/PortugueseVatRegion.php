<?php

namespace App\Enums;

enum PortugueseVatRegion: string
{
    case Mainland = 'PT';
    case Azores = 'PT-AC';
    case Madeira = 'PT-MA';

    public function label(): string
    {
        return match ($this) {
            self::Mainland => __('Mainland Portugal'),
            self::Azores => __('Azores'),
            self::Madeira => __('Madeira'),
        };
    }

    /** @return array{reduced: int, intermediate: int, standard: int} */
    public function rates(): array
    {
        return match ($this) {
            self::Mainland => ['reduced' => 600, 'intermediate' => 1300, 'standard' => 2300],
            self::Azores => ['reduced' => 400, 'intermediate' => 900, 'standard' => 1600],
            self::Madeira => ['reduced' => 400, 'intermediate' => 1200, 'standard' => 2200],
        };
    }

    public function basisPoints(string $category): ?int
    {
        return $this->rates()[$category] ?? null;
    }

    public function categoryFor(int $basisPoints): ?string
    {
        $category = array_search($basisPoints, $this->rates(), true);

        return $category === false ? null : $category;
    }
}
