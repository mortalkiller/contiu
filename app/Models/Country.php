<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Locale;

class Country extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    public static function options(): array
    {
        return once(function (): array {
            $locale = app()->getLocale();
            $options = static::query()->pluck('name', 'code')
                ->map(fn (string $name, string $code): string => Locale::getDisplayRegion('und_'.$code, $locale) ?: $name)
                ->all();

            asort($options, SORT_NATURAL | SORT_FLAG_CASE);

            return $options;
        });
    }
}
