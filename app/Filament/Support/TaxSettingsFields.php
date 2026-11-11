<?php

namespace App\Filament\Support;

use App\Enums\Language;
use App\Support\FinanceSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * The activity, IRS and Segurança Social questions, shared by the fiscal settings page and the
 * sign-up wizard, and how their answers become FinanceSettings values. A field hidden by another
 * answer (the cessation date of a first start, the IRS Jovem fields without it) is not submitted,
 * so the stored value stays as it was.
 */
final class TaxSettingsFields
{
    public const START_KINDS = ['first' => 'First registration', 'restart' => 'Restart'];

    public const IRS_COEFFICIENTS = [
        '0.75' => 'Services listed in Article 151 (0.75 coefficient)',
        '0.35' => 'Other services (0.35 coefficient)',
    ];

    /** IRS Jovem lasts this many years (art. 12.º-B do CIRS). */
    public const IRS_JOVEM_YEARS = 10;

    public const SS_RATES = [
        '0.214' => 'Self-employed worker (21.4%)',
        '0.252' => 'Sole trader or EIRL (25.2%)',
    ];

    /** Where the Segurança Social shows the activity data (rate, enrolment, exemption). */
    public const SS_DIRECTA_ACTIVITY = 'Social Security Direct → Trabalho → Remunerações e contribuições → Trabalhadores independentes → Consultar e alterar os dados de atividade';

    /**
     * "Onde ver?" next to the label, with where the freelancer finds the answer in a tooltip: the
     * fiscal questions are facts from the Finanças or the Segurança Social, not choices.
     *
     * @template T of Field
     *
     * @param  T  $field
     * @return T
     */
    public static function whereToFind(Field $field, string $where): Field
    {
        return $field->hint(__('Where can I find this?'))->hintIcon(Phosphor::Question, tooltip: $where);
    }

    public static function activityStartedOn(): DatePicker
    {
        return self::whereToFind(
            DatePicker::make('activity_started_on')
                ->label(__('Start of activity'))
                ->helperText(__('The date you registered or restarted your activity with the Tax Authority.'))
                ->native(false)
                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat()),
            __('In the Portuguese Tax Portal, under “Declarações de Atividade”, look for the date of your start or restart declaration. It is also on the receipt you received.'),
        );
    }

    /**
     * Start kind, previous cessation, SS enrolment and the years with salary or pension: the
     * current year, and the previous one when $withPreviousYear (the settings page). The sign-up
     * requires the kind; the settings page lets an older account leave it for later.
     *
     * @return array<int, Component>
     */
    public static function activity(bool $withPreviousYear, bool $requireKind = false): array
    {
        $year = (int) now()->year;

        return array_values(array_filter([
            self::whereToFind(
                Radio::make('activity_start_kind')
                    ->label(__('Is this your first registration or a restart?'))
                    ->options(array_map(fn (string $label): string => __($label), self::START_KINDS))
                    ->default('first')
                    ->required($requireKind)
                    ->descriptions([
                        'first' => __('You have never registered an activity with the Tax Authority.'),
                        'restart' => __('You previously registered an activity, ended it and registered again.'),
                    ])
                    ->columnSpanFull(),
                __('The declaration receipt says “Início” or “Reinício de Atividade”. If you previously registered and ended an activity, this is a restart, even if the portal lists it as “Início de atividade”.'),
            ),
            self::whereToFind(
                DatePicker::make('previous_activity_ended_on')
                    ->label(__('End date of previous activity'))
                    ->helperText(__('Optional. Without this date, the income tax reduction for the first years is skipped. It only applies if you restarted at least five years after ending the previous activity.'))
                    ->native(false)
                    ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                    ->visibleJs("\$get('activity_start_kind') === 'restart'")
                    ->dehydrated(fn (Get $get): bool => $get('activity_start_kind') === 'restart')
                    ->validatedWhenNotDehydrated(false)
                    ->columnSpanFull(),
                __('Check the receipt for the declaration ending your previous activity. If the Portuguese Tax Portal does not show it under “Declarações de Atividade”, request the history through e-balcão or leave it blank.'),
            ),
            self::whereToFind(
                DatePicker::make('ss_enrolled_from')
                    ->label(__('Social Security coverage starts on'))
                    ->helperText(__('Optional. Leave blank to calculate it as 12 months after your first registration or the month of a restart.'))
                    ->native(false)
                    ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                    ->columnSpanFull(),
                __(':path: “Enquadrado a partir de”.', ['path' => __(self::SS_DIRECTA_ACTIVITY)]),
            ),
            self::whereToFind(
                Toggle::make('dependent_income_current_year')
                    ->label(__('I had salary or pension income in :year', ['year' => $year]))
                    ->helperText(__('Employment or pension. The income tax reduction for the first years of activity does not apply in a year with salary or pension income.'))
                    ->columnSpanFull(),
                __('One month of salary or pension in that year is enough. Check your payslips or Schedule A of your personal income tax return.'),
            ),
            $withPreviousYear ? Toggle::make('dependent_income_previous_year')
                ->label(__('I had salary or pension income in :year', ['year' => $year - 1]))
                ->columnSpanFull() : null,
            $withPreviousYear ? self::whereToFind(
                DatePicker::make('obligations_tracked_from')
                    ->label(__('Track obligations from'))
                    ->helperText(__('Optional. Outstanding obligations since this date appear as overdue on the dashboard, going back up to 12 months. Leave blank to start from when you created your Contiu account.'))
                    ->native(false)
                    ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                    ->maxDate(now())
                    ->columnSpanFull(),
                __('Use the date you started or restarted trading if you had obligations before using Contiu, such as VAT filings for purchases from abroad.'),
            ) : null,
        ]));
    }

    /**
     * With the $stored settings (the settings page), the IRS Jovem number says when its 10 years are
     * over (fill() then leaves it blank).
     *
     * @return array<int, Component>
     */
    public static function irs(?FinanceSettings $stored = null): array
    {
        $year = (int) now()->year;
        $irsJovemEnded = ($stored?->irsJovemYearNumber($year) ?? 0) > self::IRS_JOVEM_YEARS;
        $usesIrsJovem = fn (Get $get): bool => (bool) $get('irs_jovem');

        return [
            self::whereToFind(
                Radio::make('irs_coefficient')
                    ->label(__('What services do you provide?'))
                    ->options(array_map(fn (string $label): string => __($label), self::IRS_COEFFICIENTS))
                    ->default('0.75')
                    ->descriptions([
                        '0.75' => __('Occupations listed in Article 151 of the Portuguese Income Tax Code, such as programmers, designers and consultants.'),
                        '0.35' => __('Services not listed in the table.'),
                    ])
                    ->helperText(__('Classification depends on the service you provide, regardless of the registered CAE or CIRS code (Tax Authority Circular 2/2016). The coefficient determines the taxable share of income.'))
                    ->required()
                    ->columnSpanFull(),
                __('The table in Article 151 of the Portuguese Income Tax Code (Ordinance 1011/2001) lists occupations, such as 1332 for computer programmers. Ask your accountant if unsure, since this choice significantly affects income tax.'),
            ),
            self::whereToFind(
                Toggle::make('irs_jovem')
                    ->label(__('Have you opted into IRS Jovem?'))
                    ->helperText(__('Partial income exemption for the first 10 years, up to age 35 (Article 12-B of the Portuguese Income Tax Code). Social Security contributions are unaffected.'))
                    ->columnSpanFull(),
                __('You opt in on your annual personal income tax return (Form 3, Schedule B). You are opted in if you requested it on your last return.'),
            ),
            DatePicker::make('birth_date')
                ->label(__('Date of birth'))
                ->helperText(__('Used to check the age limit of 35.'))
                ->native(false)
                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                ->visibleJs("!! \$get('irs_jovem')")
                ->dehydrated($usesIrsJovem)
                ->validatedWhenNotDehydrated(false)
                ->required(),
            self::whereToFind(TextInput::make('irs_jovem_year_number')
                ->label(__('In :year, your year number is', ['year' => $year]))
                ->helperText($irsJovemEnded
                    ? __('IRS Jovem lasts 10 years and your eligibility has ended. Turn off the option above.')
                    : __('The IRS Jovem year, from 1 to 10. The app adds one each following year.'))
                ->integer()
                ->minValue(1)
                ->maxValue(self::IRS_JOVEM_YEARS)
                ->visibleJs("!! \$get('irs_jovem')")
                ->dehydrated($usesIrsJovem)
                ->validatedWhenNotDehydrated(false)
                ->required(),
                __('Count the years with employment or business income (categories A or B), starting from the first. Skip years with no income and years when you were your parents’ dependent.'),
            ),
        ];
    }

    /** @return array<int, Component> */
    public static function socialSecurity(): array
    {
        return [
            self::whereToFind(
                Select::make('ss_rate')
                    ->label(__('Contribution rate'))
                    ->options(array_map(fn (string $label): string => __($label), self::SS_RATES))
                    ->default('0.214')
                    ->required(),
                __(':path: “Forma de atividade (taxa)”.', ['path' => __(self::SS_DIRECTA_ACTIVITY)]),
            ),
            // Stored as a fraction (0.70); typed as a percentage.
            self::whereToFind(
                TextInput::make('ss_relevant_income_share_percent')
                    ->label(__('Relevant income'))
                    ->helperText(__('70% for services.'))
                    ->numeric()
                    ->step(1)
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(70)
                    ->required(),
                __('70% for services and 20% for sales of goods (Article 162 of the Social Security Contributions Code). Leave it at 70% if unsure.'),
            ),
            self::whereToFind(
                Toggle::make('ss_contribution_exempt')
                    ->label(__('Has Social Security granted you a contribution exemption?'))
                    ->helperText(__('Article 157 exemption under the Social Security Contributions Code, for example if you also have a job, receive a pension, or paid only the minimum in the previous year.'))
                    ->columnSpanFull(),
                __(':path: under “Situação atual”, enable this option if it says “Não tem obrigação de contribuir”.', ['path' => __(self::SS_DIRECTA_ACTIVITY)]),
            ),
        ];
    }

    /**
     * Form state of the stored settings.
     *
     * @return array<string, mixed>
     */
    public static function fill(FinanceSettings $settings): array
    {
        $year = (int) now()->year;

        return [
            'activity_started_on' => $settings->activityStartedOn()?->toDateString(),
            'activity_start_kind' => $settings->activityStartKind(),
            'previous_activity_ended_on' => $settings->previousActivityEndedOn()?->toDateString(),
            'ss_enrolled_from' => $settings->ssEnrolledFrom()?->toDateString(),
            'obligations_tracked_from' => $settings->obligationsTrackedFrom()?->toDateString(),
            'dependent_income_current_year' => in_array($year, $settings->dependentIncomeYears(), true),
            'dependent_income_previous_year' => in_array($year - 1, $settings->dependentIncomeYears(), true),
            'irs_coefficient' => (string) $settings->irsCoefficient(),
            'irs_jovem' => $settings->irsJovem(),
            'birth_date' => $settings->birthDate()?->toDateString(),
            // Outside 1–10 the field would not validate and block every save.
            'irs_jovem_year_number' => self::irsJovemYearNumberInRange($settings, $year),
            'ss_rate' => (string) $settings->ssRate(),
            'ss_relevant_income_share_percent' => round($settings->ssRelevantIncomeShare() * 100, 2),
            'ss_contribution_exempt' => $settings->ssContributionExempt(),
        ];
    }

    /**
     * Settings values of the submitted answers. The salary years not asked are kept from
     * $storedYears; a field not submitted (hidden) is left out.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $storedYears
     * @return array<string, mixed>
     */
    public static function toSettings(array $data, array $storedYears = []): array
    {
        $year = (int) now()->year;
        $asked = array_filter([
            $year => $data['dependent_income_current_year'] ?? null,
            $year - 1 => $data['dependent_income_previous_year'] ?? null,
        ], fn ($answer) => $answer !== null);

        $years = collect($storedYears)
            ->reject(fn (int $stored) => array_key_exists($stored, $asked))
            ->merge(array_keys(array_filter($asked)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $values = [
            'activity_started_on' => self::date($data['activity_started_on'] ?? null),
            'activity_start_kind' => $data['activity_start_kind'] ?? null,
            'ss_enrolled_from' => self::date($data['ss_enrolled_from'] ?? null),
            'dependent_income_years' => $years,
            'irs_coefficient' => (float) $data['irs_coefficient'],
            'irs_jovem' => (bool) ($data['irs_jovem'] ?? false),
            'ss_rate' => (float) $data['ss_rate'],
            'ss_relevant_income_share' => round((float) $data['ss_relevant_income_share_percent'] / 100, 4),
            'ss_contribution_exempt' => (bool) ($data['ss_contribution_exempt'] ?? false),
        ];

        if (array_key_exists('previous_activity_ended_on', $data)) {
            $values['previous_activity_ended_on'] = self::date($data['previous_activity_ended_on']);
        }

        if (array_key_exists('obligations_tracked_from', $data)) {
            $values['obligations_tracked_from'] = self::date($data['obligations_tracked_from']);
        }

        if (array_key_exists('birth_date', $data)) {
            $values['birth_date'] = self::date($data['birth_date']);
        }

        if (array_key_exists('irs_jovem_year_number', $data)) {
            $values['irs_jovem_year'] = filled($data['irs_jovem_year_number'])
                ? ['year' => $year, 'number' => (int) $data['irs_jovem_year_number']]
                : null;
        }

        return $values;
    }

    private static function irsJovemYearNumberInRange(FinanceSettings $settings, int $year): ?int
    {
        $number = $settings->irsJovemYearNumber($year);

        return $number !== null && $number >= 1 && $number <= self::IRS_JOVEM_YEARS ? $number : null;
    }

    private static function date(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse($value)->toDateString();
    }
}
