<?php

namespace App\Filament\Support;

use App\Enums\Language;
use App\Support\ActivityCatalogue;
use App\Support\Money;
use App\Support\TaxProfileDeduction;
use App\Support\YearlyParameter;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\GridDirection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The plain-language fiscal questions of the sign-up (RegisterAccount) and of "Já abri atividade"
 * (OpenActivity): whether and how the activity was opened, the year's situation and the work.
 * TaxProfileDeduction turns the answers into the fiscal settings. A line hidden by another answer
 * is not submitted nor validated.
 */
final class SignupQuestions
{
    /** Situation cards: several can apply, except "only self-employed". */
    public const SITUATIONS = ['employment', 'pension', 'self_only'];

    /** The youngest age that can open an activity on its own. */
    public const MINIMUM_AGE = 18;

    /** "Já abriste atividade nas Finanças?" as two cards. */
    public static function activityOpened(): Radio
    {
        return Radio::make('activity_opened')
            ->label(__('Have you opened your activity with the Tax Authority?'))
            ->hiddenLabel()
            ->options(['yes' => __('Yes, I have'), 'no' => __('Not yet')])
            ->descriptions([
                'yes' => __('I have the declaration of start of activity.'),
                'no' => __('Keep my account. I will answer the rest when I open it.'),
            ])
            ->default('yes')
            ->required()
            ->columns(2)
            ->gridDirection(GridDirection::Row)
            ->extraAttributes(['class' => 'fap-radio-cards']);
    }

    /**
     * When and how the activity started, and the regime. With $onlyIfOpened (the sign-up) the
     * lines show once the user says the activity is open.
     *
     * @return array<int, Component>
     */
    public static function activityDetails(bool $onlyIfOpened): array
    {
        $opened = $onlyIfOpened ? "\$get('activity_opened') === 'yes'" : 'true';
        $isOpened = fn (Get $get): bool => ! $onlyIfOpened || $get('activity_opened') === 'yes';
        $restart = "{$opened} && \$get('activity_start_kind') === 'restart'";
        $isRestart = fn (Get $get): bool => $isOpened($get) && $get('activity_start_kind') === 'restart';
        $asksSsDate = "{$restart} && \$get('accounting_regime') !== 'organised'";
        $needsSsDate = fn (Get $get): bool => $isRestart($get) && $get('accounting_regime') !== 'organised';

        return [
            self::when(self::date('activity_started_on')
                ->label(__('When did you open it?'))
                ->maxDate(today())
                ->required(), $opened, $isOpened),
            self::when(self::choice('activity_start_kind', ['first' => __('First time'), 'restart' => __('I had one before')])
                ->label(__('Is this the first time you open an activity?'))
                ->default('first')
                ->required(), $opened, $isOpened),
            self::when(self::date('previous_activity_ended_on')
                ->label(__('When did you close the previous one?'))
                ->belowLabel(__('Optional. Without it, the IRS reduction of the first years does not apply.'))
                ->maxDate(today()), $restart, $isRestart),
            self::when(self::date('ss_enrolled_from')
                ->label(__('Since when does Social Security count?'))
                ->belowLabel(__('In Social Security Direct, under “Enquadrado a partir de”.'))
                ->required(), $asksSsDate, $needsSsDate),
            self::when(self::choice('accounting_regime', [
                'simplified' => __('Simplified'),
                'organised' => __('Organised'),
                'unknown' => __('I don’t know'),
            ])
                ->label(__('Which regime do you have?'))
                ->belowLabel(__('In your activity declaration, under “Regime de tributação”.'))
                ->default('simplified')
                ->required(), $opened, $isOpened),
        ];
    }

    /**
     * "Como é o teu 2026?": salary, pension or neither, the job's details, and the age for IRS
     * Jovem.
     *
     * @return array<int, Component>
     */
    public static function situation(): array
    {
        $year = (int) now()->year;
        $hasJob = "(\$get('situation') ?? []).includes('employment')";
        $hasJobOnServer = fn (Get $get): bool => in_array('employment', (array) $get('situation'), true);
        $maxAge = TaxProfileDeduction::IRS_JOVEM_MAX_AGE;
        $young = "(() => { const date = \$get('birth_date'); return !! date && {$year} - parseInt(String(date).slice(0, 4)) <= {$maxAge} })()";
        $isYoung = fn (Get $get): bool => filled($get('birth_date')) && $year - (int) substr((string) $get('birth_date'), 0, 4) <= $maxAge;
        $irsJovem = "{$young} && \$get('irs_jovem') === 'yes'";
        $hasIrsJovem = fn (Get $get): bool => $isYoung($get) && $get('irs_jovem') === 'yes';

        return [
            CheckboxList::make('situation')
                ->label(__('Your situation in :year', ['year' => $year]))
                ->hiddenLabel()
                ->options([
                    'employment' => __('I have a job'),
                    'pension' => __('I receive a pension'),
                    'self_only' => __('Only self-employed'),
                ])
                ->descriptions([
                    'employment' => __('I receive a salary, even if only for one month.'),
                    'pension' => __('Old-age or disability pension.'),
                    'self_only' => __('No salary nor pension.'),
                ])
                ->required()
                ->columns(3)
                ->gridDirection(GridDirection::Row)
                // In the browser, "Only self-employed" and the other two disable each other, so the
                // rule below never has to speak.
                ->extraInputAttributes(fn (CheckboxList $component): array => [
                    // The state path is letters and dots only: safe between single quotes in the attribute.
                    'x-bind:disabled' => '(() => { const chosen = $wire.get(\''.$component->getStatePath().'\') ?? []; '
                        .'return $el.value === \'self_only\' ? chosen.some((value) => value !== \'self_only\') : chosen.includes(\'self_only\') })()',
                ])
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (in_array('self_only', (array) $value, true) && count((array) $value) > 1) {
                        $fail(__('“Only self-employed” does not go with a job or a pension.'));
                    }
                })
                ->extraAttributes(['class' => 'contiu-signup-cards']),
            Group::make([
                Text::make(__('About the job'))->extraAttributes(['class' => 'contiu-signup-question']),
                Group::make([
                    self::when(self::yesNo('employer_unrelated')
                        ->label(__('Is your employer another entity, with no link to your clients?'))
                        ->required(), $hasJob, $hasJobOnServer),
                    self::when(self::yesNo('salary_at_least_ias')
                        ->label(__('Is your gross salary at least :ias a month?', ['ias' => Money::format(YearlyParameter::ias($year))]))
                        ->belowLabel(__('On average over the last three months. It is the IAS in :year.', ['year' => $year]))
                        ->required(), $hasJob, $hasJobOnServer),
                    self::when(self::choice('employment_scheme', ['ss' => __('Social Security'), 'cga' => __('CGA')])
                        ->label(__('Where do your salary contributions go?'))
                        ->belowLabel(Text::make(__('With the CGA, the exemption has to be requested from Social Security.'))->visibleJs("\$get('employment_scheme') === 'cga'"))
                        ->default('ss')
                        ->required(), $hasJob, $hasJobOnServer),
                ])->extraAttributes(['class' => 'contiu-signup-lines']),
            ])
                ->visibleJs($hasJob)
                ->extraAttributes(['class' => 'contiu-signup-block']),
            Group::make([
                Text::make(__('And your age?'))->extraAttributes(['class' => 'contiu-signup-question']),
                Text::make(__('Up to 35 you can have IRS Jovem.'))->extraAttributes(['class' => 'contiu-signup-hint']),
                Group::make([
                    // Only an adult opens an activity on their own: the calendar ends 18 years ago.
                    self::date('birth_date')
                        ->label(__('Date of birth'))
                        ->belowLabel(__('The calendar only goes up to :age years ago.', ['age' => self::MINIMUM_AGE]))
                        ->maxDate(today()->subYears(self::MINIMUM_AGE))
                        ->defaultFocusedDate(today()->subYears(30))
                        ->validationMessages(['before_or_equal' => __('You must be at least :age years old.', ['age' => self::MINIMUM_AGE])])
                        ->required(),
                    self::when(self::yesNo('irs_jovem')
                        ->label(__('Did you ask for IRS Jovem on your last IRS return?'))
                        ->required(), $young, $isYoung),
                    self::when(TextInput::make('first_work_income_year')
                        ->label(__('In which year did you first have work income?'))
                        ->belowLabel(__('A salary or self-employed income, not counting the years you were your parents’ dependant.'))
                        ->integer()
                        ->minValue($year - 50)
                        ->maxValue($year)
                        ->extraInputAttributes(['inputmode' => 'numeric'])
                        ->inlineLabel()
                        ->afterStateUpdatedJs(self::irsJovemYearJs($year)), $irsJovem, $hasIrsJovem),
                    self::when(TextInput::make('years_without_work_income')
                        ->label(__('Since then, how many years without work income?'))
                        ->belowLabel(__('Or as a dependant. Those years do not count.'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(50)
                        ->default(0)
                        ->extraInputAttributes(['inputmode' => 'numeric'])
                        ->inlineLabel()
                        ->afterStateUpdatedJs(self::irsJovemYearJs($year)), $irsJovem, $hasIrsJovem),
                    self::when(TextInput::make('irs_jovem_year_number')
                        ->label(__('In :year, your year number is', ['year' => $year]))
                        ->belowLabel(__('Worked out from the two answers above. Check it on your last IRS return.'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(10)
                        ->validationMessages(['max' => __('IRS Jovem lasts 10 years: if yours are over, answer No above.')])
                        ->extraInputAttributes(['inputmode' => 'numeric'])
                        ->inlineLabel()
                        ->required(), $irsJovem, $hasIrsJovem),
                ])->extraAttributes(['class' => 'contiu-signup-lines']),
            ])->extraAttributes(['class' => 'contiu-signup-block']),
        ];
    }

    /**
     * "O que fazes?" as a small tree (ActivityCatalogue): the kind of activity, then the occupation
     * of the art. 151.º table (which gives the IRS coefficient) and the CAE, both searched in lists
     * the kind puts in order. Selling goods, restaurants and lodging have no occupation. They, and
     * a service that is not on the table, are asked whether the activity is only commercial or
     * industrial (the sole trader's rate, SS003). Then the turnover expected
     * until the end of the year and the VAT.
     *
     * @return array<int, Component>
     */
    public static function work(): array
    {
        $year = (int) now()->year;
        // Single quotes only: the expression goes inside an HTML attribute.
        $goodsKinds = "['".implode("', '", array_keys(array_filter(ActivityCatalogue::KINDS, fn (array $kind): bool => $kind['goods'])))."']";
        $goods = "{$goodsKinds}.includes(\$get('activity_kind'))";
        $sellsGoods = fn (Get $get): bool => ActivityCatalogue::sellsGoods($get('activity_kind'));
        $services = "!! \$get('activity_kind') && ! {$goods}";
        $providesServices = fn (Get $get): bool => filled($get('activity_kind')) && ! $sellsGoods($get);
        // A service off the art. 151.º table (building, transport…) may also be commercial (SS003).
        $otherOccupation = ActivityCatalogue::OTHER_OCCUPATION;
        $mayBeCommercial = "{$goods} || (!! \$get('activity_kind') && \$get('occupation') === '{$otherOccupation}')";
        $mayBeCommercialOnServer = fn (Get $get): bool => ActivityCatalogue::workKind($get('activity_kind'), $get('occupation') === null ? null : (string) $get('occupation')) !== 'listed'
            && filled($get('activity_kind'));

        return [
            Radio::make('activity_kind')
                ->label(__('What kind of activity?'))
                ->belowLabel(__('It orders the lists below. Selling products and restaurants do not ask for an occupation.'))
                ->options(ActivityCatalogue::kindOptions())
                ->descriptions(ActivityCatalogue::kindDescriptions())
                // The occupations and CAE below are ordered by it: the server answers.
                ->live()
                ->required()
                ->columns(3)
                // Row by row, so the cards of a row line up at the same height.
                ->gridDirection(GridDirection::Row)
                ->extraAttributes(['class' => 'fap-radio-cards']),
            Group::make([
                self::when(Select::make('occupation')
                    ->label(__('Your occupation'))
                    ->belowLabel(__('As on the table of Article 151, the code of your activity declaration. Not on it? Choose the last option (1519). On the table the coefficient is 0.75; off it, 0.35.'))
                    ->options(fn (Get $get): array => ActivityCatalogue::occupationOptions($get('activity_kind')))
                    ->searchable()
                    ->required(), $services, $providesServices),
                // SS003: a mixed activity keeps 21.4 % as a proposal, to confirm with the ISS.
                self::when(self::choice('commercial_only', ['yes' => __('Yes'), 'no' => __('No'), 'mixed' => __('Mixed / not sure')])
                    ->label(__('Is your activity only commercial or industrial?'))
                    ->belowLabel(__('Then, as a sole trader, the Social Security rate is 25.2%. In Social Security Direct, under “Forma de atividade (taxa)”.'))
                    ->required(), $mayBeCommercial, $mayBeCommercialOnServer),
                self::when(CaeSelect::main('cae', kind: fn (Get $get): ?string => $get('activity_kind'))
                    ->label(__('Your CAE'))
                    ->belowLabel(__('Optional. Search by number or name; those of your kind of activity come first. It does not change the coefficient.')),
                    '!! $get(\'activity_kind\')', fn (Get $get): bool => filled($get('activity_kind'))),
                self::when(CaeSelect::secondary('secondary_caes', 'cae', kind: fn (Get $get): ?string => $get('activity_kind'))
                    ->label(__('Secondary CAEs'))
                    ->belowLabel(__('Optional. Only if your activity declaration has more than one CAE.')),
                    '!! $get(\'activity_kind\')', fn (Get $get): bool => filled($get('activity_kind'))),
            ])->extraAttributes(['class' => 'contiu-signup-lines']),
            Group::make([
                Text::make(__('How much do you expect to invoice in :year?', ['year' => $year]))->extraAttributes(['class' => 'contiu-signup-question']),
                Text::make(__('Until the end of the year, only what you invoice in Portugal. It is for VAT.'))->extraAttributes(['class' => 'contiu-signup-hint']),
                Group::make([
                    MoneyInput::make('expected_turnover')
                        ->label(__('Expected turnover'))
                        ->minValue(0)
                        ->inlineLabel()
                        ->required(),
                    self::choice('vat_answer', [
                        'exempt' => __('Exempt from VAT'),
                        'normal' => __('Normal regime'),
                        'unknown' => __('I don’t know'),
                    ])
                        ->label(__('What does your declaration say about VAT?'))
                        ->belowLabel(__('Under “Enquadramento em IVA”. If you don’t know, we propose it.'))
                        ->required(),
                ])->extraAttributes(['class' => 'contiu-signup-lines']),
            ])->extraAttributes(['class' => 'contiu-signup-block']),
        ];
    }

    /** Organised accounting: only the VAT, for the invoices. */
    public static function organisedVat(): ToggleButtons
    {
        return self::choice('organised_vat_answer', ['normal' => __('Normal regime'), 'exempt' => __('Exempt from VAT')])
            ->label(__('What does your declaration say about VAT?'))
            ->belowLabel(__('Under “Enquadramento em IVA”, in your activity declaration.'))
            ->default('normal')
            ->required();
    }

    /**
     * The answers as TaxProfileDeduction takes them. From the form state the turnover is in euros
     * ($turnoverInEuros); after validation it is already in cents.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function answers(array $state, bool $turnoverInEuros): array
    {
        $answers = $state;

        // Organised accounting is only asked the VAT, on its own step.
        if (($state['accounting_regime'] ?? null) === 'organised') {
            $answers['vat_answer'] = $state['organised_vat_answer'] ?? null;
        }

        if ($turnoverInEuros) {
            $answers['expected_turnover'] = Money::toCents($state['expected_turnover'] ?? null);
        }

        // The coefficient's kind of work and the occupation's name come from the activity tree.
        if (array_key_exists('activity_kind', $state)) {
            $occupation = isset($state['occupation']) ? (string) $state['occupation'] : null;
            $answers['work_kind'] = ActivityCatalogue::workKind($state['activity_kind'], $occupation);
            $answers['profession'] = ActivityCatalogue::sellsGoods($state['activity_kind']) ? null : ActivityCatalogue::occupationLabel($occupation);
        }

        return $answers;
    }

    /**
     * What the tax profile screen (signup-profile) shows: a tax card (who, the regime and the key
     * codes), the year in one sentence, one seal per tax with its reason and the dates. $stepNames
     * gives the wizard position of each step, for "Alterar".
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $stepNames
     * @return array<string, mixed>
     */
    public static function profileViewData(TaxProfileDeduction $deduction, array $state, string $eyebrow, array $stepNames): array
    {
        $name = trim((string) ($state['name'] ?? ''));
        $startedOn = $deduction->settings()['activity_started_on'] ?? null;
        $situation = $deduction->situation();
        $occupation = isset($state['occupation']) ? (string) $state['occupation'] : null;
        $profession = trim((string) (ActivityCatalogue::occupationLabel($occupation) ?? $state['profession'] ?? ''));
        $secondary = count(ActivityCatalogue::secondaryCaes($state['secondary_caes'] ?? [], $state['cae'] ?? null));
        $codes = implode(' · ', array_filter([
            $occupation === ActivityCatalogue::OTHER_OCCUPATION ? ActivityCatalogue::RESIDUAL_OCCUPATION : $occupation,
            filled($state['cae'] ?? null) ? (string) $state['cae'].($secondary > 0 ? ' +'.$secondary : '') : null,
        ]));

        return [
            'eyebrow' => $eyebrow,
            'name' => $name,
            'initials' => Str::upper(collect(preg_split('/\s+/', $name) ?: [])->filter()->map(fn (string $part): string => Str::substr($part, 0, 1))->take(2)->implode('')),
            'logo' => asset('brand/contiu/contiu-logo-white.svg'),
            'regime' => ($state['accounting_regime'] ?? null) === 'unknown' ? __('Simplified regime (probable)') : __('Simplified regime'),
            'role' => implode(' · ', array_filter([
                $profession !== '' ? $profession : null,
                match (true) {
                    in_array('employment', $situation, true) => __('Also employed'),
                    in_array('pension', $situation, true) => __('With a pension'),
                    default => __('Only self-employed'),
                },
            ])),
            'facts' => array_values(array_filter([
                filled($state['nif'] ?? null) ? ['label' => __('Tax ID'), 'value' => (string) $state['nif']] : null,
                $startedOn ? ['label' => __('Since'), 'value' => Carbon::parse($startedOn)->format('m/Y')] : null,
                $codes !== '' ? ['label' => filled($state['cae'] ?? null) ? __('Occupation · CAE') : __('Occupation'), 'value' => $codes] : null,
            ])),
            'summary' => $deduction->summary(),
            'regimeNote' => $deduction->regimeNote(),
            'cards' => $deduction->cards(),
            'stepIndexes' => array_flip($stepNames),
            'dates' => $deduction->dates(),
            'canConfirmExemption' => $deduction->ssExemptionReason() !== null,
        ];
    }

    /**
     * Fills the IRS Jovem year number in the browser (JOV004, JOV006): the years since the first one
     * with work income, this one included, less the years without it or as a dependant. It stays
     * editable: the IRS return has the exact number.
     */
    private static function irsJovemYearJs(int $year): string
    {
        return <<<JS
            const first = parseInt(\$get('first_work_income_year'), 10);
            const gaps = parseInt(\$get('years_without_work_income'), 10) || 0;
            if (first > 0 && first <= {$year} && {$year} - first + 1 - gaps >= 1) {
                \$set('irs_jovem_year_number', {$year} - first + 1 - gaps);
            }
            JS;
    }

    /**
     * Shown in the browser when $js holds; on the server only submitted (and validated) when
     * $condition holds.
     *
     * @template T of Field
     *
     * @param  T  $field
     * @return T
     */
    private static function when(Field $field, string $js, Closure $condition): Field
    {
        return $field->visibleJs($js)
            ->dehydrated($condition)
            ->validatedWhenNotDehydrated(false);
    }

    private static function date(string $name): DatePicker
    {
        return DatePicker::make($name)
            ->native(false)
            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->inlineLabel();
    }

    /** @param array<string, string> $options */
    private static function choice(string $name, array $options): ToggleButtons
    {
        return ToggleButtons::make($name)
            ->options($options)
            ->inline()
            ->grouped()
            ->inlineLabel();
    }

    private static function yesNo(string $name): ToggleButtons
    {
        return self::choice($name, ['yes' => __('Yes'), 'no' => __('No')]);
    }
}
