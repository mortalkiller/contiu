<?php

namespace App\Filament\Support;

use App\Enums\Language;
use App\Support\Money;
use App\Support\TaxProfileDeduction;
use App\Support\YearlyParameter;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
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

    /** "Já abriste atividade nas Finanças?" as two cards. */
    public static function activityOpened(): Radio
    {
        return Radio::make('activity_opened')
            ->hiddenLabel()
            ->options(['yes' => __('Yes, I have'), 'no' => __('Not yet')])
            ->descriptions([
                'yes' => __('I have the declaration of start of activity.'),
                'no' => __('Keep my account. I will answer the rest when I open it.'),
            ])
            ->default('yes')
            ->required()
            ->columns(2)
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
                        ->belowLabel(__('With the CGA, the exemption has to be requested from Social Security.'))
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
                    self::date('birth_date')
                        ->label(__('Date of birth'))
                        ->maxDate(today())
                        ->required(),
                    self::when(self::yesNo('irs_jovem')
                        ->label(__('Did you ask for IRS Jovem on your last IRS return?'))
                        ->required(), $young, $isYoung),
                    self::when(TextInput::make('irs_jovem_year_number')
                        ->label(__('In :year, your year number is', ['year' => $year]))
                        ->belowLabel(__('Count the years with work income.'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(10)
                        ->extraInputAttributes(['inputmode' => 'numeric'])
                        ->inlineLabel()
                        ->required(), $irsJovem, $hasIrsJovem),
                ])->extraAttributes(['class' => 'contiu-signup-lines']),
            ])->extraAttributes(['class' => 'contiu-signup-block']),
        ];
    }

    /**
     * "O que fazes?": the kind of work (coefficient), whether a sale of goods is only commercial
     * (the sole trader's rate), the turnover expected until the end of the year and the VAT.
     *
     * @return array<int, Component>
     */
    public static function work(): array
    {
        $year = (int) now()->year;
        $goods = "\$get('work_kind') === 'goods'";
        $sellsGoods = fn (Get $get): bool => $get('work_kind') === 'goods';

        return [
            Radio::make('work_kind')
                ->label(__('What do you do?'))
                ->hiddenLabel()
                ->options([
                    'listed' => __('An occupation on the list'),
                    'other' => __('Other services'),
                    'goods' => __('I sell products'),
                ])
                ->descriptions([
                    'listed' => __('Programmer, designer, consultant, architect…'),
                    'other' => __('Services that are not on the list.'),
                    'goods' => __('Shop, crafts, restaurants, lodging…'),
                ])
                ->required()
                ->columns(3)
                ->extraAttributes(['class' => 'fap-radio-cards']),
            TextInput::make('profession')
                ->label(__('Your occupation'))
                ->helperText(__('As in your activity declaration, with its code if you have it. Optional.'))
                ->placeholder(__('1332 · Computer programmers'))
                ->maxLength(120),
            Group::make([
                self::when(self::yesNo('commercial_only')
                    ->label(__('Is your activity only commercial or industrial?'))
                    ->belowLabel(__('Then, as a sole trader, the Social Security rate is 25.2%. In Social Security Direct, under “Forma de atividade (taxa)”.'))
                    ->required(), $goods, $sellsGoods),
            ])
                ->visibleJs($goods)
                ->extraAttributes(['class' => 'contiu-signup-lines']),
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

        return $answers;
    }

    /**
     * What the tax profile screen (signup-profile) shows: who, the year in one sentence, the three
     * values with their reasons and the dates. $stepNames gives the wizard position of each step,
     * for "Alterar".
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
        $profession = trim((string) ($state['profession'] ?? ''));

        return [
            'eyebrow' => $eyebrow,
            'name' => $name,
            'initials' => Str::upper(collect(preg_split('/\s+/', $name) ?: [])->filter()->map(fn (string $part): string => Str::substr($part, 0, 1))->take(2)->implode('')),
            'subtitle' => implode(' · ', array_filter([
                $profession !== '' ? $profession : null,
                $startedOn ? __('self-employed since :month', ['month' => Carbon::parse($startedOn)->locale(app()->getLocale())->translatedFormat(__('F Y'))]) : null,
            ])),
            'chips' => array_values(array_filter([
                ($state['accounting_regime'] ?? null) === 'unknown' ? __('Simplified regime (probable)') : __('Simplified regime'),
                filled($state['nif'] ?? null) ? __('Tax ID :nif', ['nif' => $state['nif']]) : null,
                match (true) {
                    in_array('employment', $situation, true) => __('Also employed'),
                    in_array('pension', $situation, true) => __('With a pension'),
                    default => __('Only self-employed'),
                },
                $deduction->irsJovemPercent() !== null ? __('IRS Jovem · year :number', ['number' => (int) ($state['irs_jovem_year_number'] ?? 0)]) : null,
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
