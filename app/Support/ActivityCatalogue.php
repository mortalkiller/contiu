<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What a freelancer does, for the sign-up's "O que fazes?": a kind of activity in plain words, then
 * the occupation of the art. 151.º CIRS table (database/data/irs-article-151.json, Portaria
 * 1011/2001 as amended) and the CAE-Rev.4 subclass (database/data/cae-rev4.json, DL 9/2025).
 *
 * The occupation decides the IRS coefficient (SIM001–SIM003): one specifically on the table is
 * 0.75; 1519 "Outros prestadores de serviços" and any service not on it are 0.35; selling goods,
 * restaurants and lodging is 0.15. The CAE never decides it: the coefficient follows the service
 * actually provided (Circular 2/2016, B008). The kind of activity only narrows the lists.
 */
final class ActivityCatalogue
{
    /** The residual code of the table: not "specifically listed", so 0.35. */
    public const RESIDUAL_OCCUPATION = '1519';

    /** The answer for a service that is not on the table. */
    public const OTHER_OCCUPATION = 'other';

    /**
     * Kinds of activity: their art. 151.º codes and the CAE divisions that usually go with them.
     * Goods kinds have no occupation: they are 0.15.
     *
     * @var array<string, array{label: string, description: string, goods: bool, occupations: list<string>, divisions: list<string>}>
     */
    public const KINDS = [
        'technology' => ['label' => 'Technology and IT', 'description' => 'Programming, systems, data.', 'goods' => false, 'occupations' => ['1332', '1313'], 'divisions' => ['62', '63', '58', '61', '95']],
        'design' => ['label' => 'Design, communication and media', 'description' => 'Design, advertising, journalism, translation, photography.', 'goods' => false, 'occupations' => ['1336', '1322', '1333', '1327', '1334', '1326', '1321'], 'divisions' => ['73', '74', '59', '60', '58', '82']],
        'engineering' => ['label' => 'Architecture and engineering', 'description' => 'Projects, technical drawing, surveying.', 'goods' => false, 'occupations' => ['1001', '1003', '1004', '1000', '1002', '1005', '1006'], 'divisions' => ['71', '74']],
        'business' => ['label' => 'Consulting, management and finance', 'description' => 'Consulting, accounting, audit, real estate, commissions.', 'goods' => false, 'occupations' => ['1320', '4013', '4015', '4014', '4012', '4011', '4010', '4016', '9010', '1310', '1319', '1330', '1331', '1328'], 'divisions' => ['70', '69', '66', '68', '78', '82']],
        'law' => ['label' => 'Law', 'description' => 'Lawyers, legal advisers, solicitors, notaries.', 'goods' => false, 'occupations' => ['6010', '6011', '6012', '9011'], 'divisions' => ['69']],
        'health' => ['label' => 'Health and wellbeing', 'description' => 'Doctors, nurses, therapists, psychologists, beauty.', 'goods' => false, 'occupations' => ['7014', '7015', '7010', '7011', '7012', '7013', '7016', '7017', '7018', '7019', '7020', '7021', '7022', '7023', '7024', '5010', '5012', '5013', '5014', '5015', '5016', '5019', '1010', '1335', '1329', '1325', '1410'], 'divisions' => ['86', '87', '88', '75', '96']],
        'education' => ['label' => 'Teaching and training', 'description' => 'Teachers, trainers, tutors.', 'goods' => false, 'occupations' => ['8012', '8011', '8010', '8013'], 'divisions' => ['85']],
        'arts' => ['label' => 'Arts, culture and sport', 'description' => 'Artists, musicians, actors, athletes, restorers.', 'goods' => false, 'occupations' => ['2015', '2013', '2010', '2019', '2014', '2012', '2011', '2016', '2017', '3010', '3019', '1323', '1337'], 'divisions' => ['90', '91', '93', '59']],
        'science' => ['label' => 'Science and research', 'description' => 'Research, laboratory analyses, biology, archaeology.', 'goods' => false, 'occupations' => ['1110', '1318', '1314', '1011', '1005'], 'divisions' => ['72', '71', '91']],
        'care' => ['label' => 'Personal and social care', 'description' => 'Family helpers, childminders, social work, religion.', 'goods' => false, 'occupations' => ['1315', '1311', '1312', '1324', '1210', '1316', '1317'], 'divisions' => ['88', '87', '96', '97', '94']],
        'other_services' => ['label' => 'Other services', 'description' => 'Repairs, cleaning, transport, events…', 'goods' => false, 'occupations' => [], 'divisions' => ['81', '95', '49', '79', '77', '80', '82', '93', '96', '43']],
        'goods' => ['label' => 'Selling products', 'description' => 'Shop, online shop, crafts, production.', 'goods' => true, 'occupations' => [], 'divisions' => ['47', '46', '32', '10', '13', '14', '15', '16', '23', '25', '31']],
        'food_lodging' => ['label' => 'Restaurants and lodging', 'description' => 'Restaurant, café, bar, local lodging.', 'goods' => true, 'occupations' => [], 'divisions' => ['56', '55']],
    ];

    /** @var array{sections: array<string, string>, activities: list<array{code: string, name: string, section: int}>}|null */
    private static ?array $table = null;

    /** @var array{sections: array<string, string>, divisions: array<string, string>, subclasses: list<array{code: string, name: string, section: string, division: string}>}|null */
    private static ?array $cae = null;

    /** @return array<string, string> kind => label */
    public static function kindOptions(): array
    {
        return array_map(fn (array $kind): string => __($kind['label']), self::KINDS);
    }

    /** @return array<string, string> kind => description */
    public static function kindDescriptions(): array
    {
        return array_map(fn (array $kind): string => __($kind['description']), self::KINDS);
    }

    public static function sellsGoods(?string $kind): bool
    {
        return $kind !== null && (bool) (self::KINDS[$kind]['goods'] ?? false);
    }

    /**
     * The occupations offered for a kind: its own first, then every other one on the table, then
     * "not on the list". Each as "1332 · Programadores informáticos".
     *
     * @return array<string, string>
     */
    public static function occupationOptions(?string $kind): array
    {
        $all = self::occupations();
        unset($all[self::RESIDUAL_OCCUPATION]);
        $own = array_values(array_filter($kind === null ? [] : (self::KINDS[$kind]['occupations'] ?? []), fn (string $code): bool => isset($all[$code])));
        $options = [];

        foreach ([...$own, ...array_diff(array_map('strval', array_keys($all)), $own)] as $code) {
            $options[(string) $code] = $code.' · '.$all[$code];
        }

        return $options + [self::OTHER_OCCUPATION => __('My service is not on the list (1519 · Outros prestadores de serviços)')];
    }

    /** "1332 · Programadores informáticos", or null for an unknown code. */
    public static function occupationLabel(?string $code): ?string
    {
        if ($code === self::OTHER_OCCUPATION) {
            return self::RESIDUAL_OCCUPATION.' · '.self::occupations()[self::RESIDUAL_OCCUPATION];
        }

        return isset(self::occupations()[$code]) ? $code.' · '.self::occupations()[$code] : null;
    }

    /**
     * The kind of work for the IRS coefficient: goods (0.15), listed (0.75, an occupation
     * specifically on the table) or other (0.35, including 1519). Null while nothing is chosen.
     */
    public static function workKind(?string $kind, ?string $occupation): ?string
    {
        if ($kind === null || ! isset(self::KINDS[$kind])) {
            return null;
        }

        if (self::sellsGoods($kind)) {
            return 'goods';
        }

        return $occupation !== null && isset(self::occupations()[$occupation]) && (string) $occupation !== self::RESIDUAL_OCCUPATION ? 'listed' : 'other';
    }

    /**
     * CAE subclasses matching a code (its start) or words of the name, those of the kind's divisions
     * first.
     *
     * @return array<string, string> code => "62100 · Atividades de programação informática"
     */
    public static function searchCae(string $search, ?string $kind = null, int $limit = 50): array
    {
        $search = trim($search);
        $words = array_filter(explode(' ', Str::lower(Str::ascii($search))));
        $divisions = $kind === null ? [] : (self::KINDS[$kind]['divisions'] ?? []);

        $matches = collect(self::cae()['subclasses'])
            ->filter(function (array $subclass) use ($search, $words): bool {
                if ($search === '') {
                    return true;
                }

                if (ctype_digit($search)) {
                    return str_starts_with($subclass['code'], $search);
                }

                $name = Str::lower(Str::ascii($subclass['name']));

                return collect($words)->every(fn (string $word): bool => str_contains($name, $word));
            })
            ->sortBy(fn (array $subclass): string => (in_array($subclass['division'], $divisions, true) ? '0' : '1').(string) array_search($subclass['division'], $divisions, true).$subclass['code'])
            ->take($limit);

        return $matches->mapWithKeys(fn (array $subclass): array => [$subclass['code'] => $subclass['code'].' · '.$subclass['name']])->all();
    }

    /** "62100 · Atividades de programação informática", or null for an unknown code. */
    public static function caeLabel(?string $code): ?string
    {
        $subclass = collect(self::cae()['subclasses'])->firstWhere('code', $code);

        return $subclass === null ? null : $subclass['code'].' · '.$subclass['name'];
    }

    public static function caeExists(?string $code): bool
    {
        return self::caeLabel($code) !== null;
    }

    /**
     * The secondary CAEs to store: trimmed, without repeats nor the main one.
     *
     * @return list<string>
     */
    public static function secondaryCaes(mixed $codes, mixed $main): array
    {
        return collect((array) $codes)
            ->map(fn (mixed $code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '' && $code !== trim((string) $main))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, string> code => name */
    public static function occupations(): array
    {
        self::$table ??= json_decode((string) file_get_contents(database_path('data/irs-article-151.json')), true, flags: JSON_THROW_ON_ERROR);

        return collect(self::$table['activities'])->mapWithKeys(fn (array $activity): array => [$activity['code'] => $activity['name']])->all();
    }

    /** @return array{sections: array<string, string>, divisions: array<string, string>, subclasses: list<array{code: string, name: string, section: string, division: string}>} */
    private static function cae(): array
    {
        return self::$cae ??= json_decode((string) file_get_contents(database_path('data/cae-rev4.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
