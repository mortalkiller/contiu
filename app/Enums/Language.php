<?php

namespace App\Enums;

enum Language: string
{
    case PortuguesePortugal = 'pt-PT';
    case EnglishUnitedStates = 'en-US';

    public static function resolve(?string $language): self
    {
        return match (strtolower(str_replace('_', '-', $language ?? ''))) {
            'en', 'en-us' => self::EnglishUnitedStates,
            default => self::PortuguesePortugal,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::PortuguesePortugal->value => 'Português (Portugal)',
            self::EnglishUnitedStates->value => 'English (United States)',
        ];
    }

    public function locale(): string
    {
        return str_replace('-', '_', $this->value);
    }

    public function dateFormat(): string
    {
        return match ($this) {
            self::PortuguesePortugal => 'd/m/Y',
            self::EnglishUnitedStates => 'm/d/Y',
        };
    }
}
