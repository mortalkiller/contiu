<?php

namespace App\Filament\Support;

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
    public const START_KINDS = ['first' => 'Primeiro início', 'restart' => 'Reinício'];

    public const IRS_COEFFICIENTS = [
        '0.75' => 'Serviços da tabela do art. 151.º (coeficiente 0,75)',
        '0.35' => 'Outras prestações de serviços (coeficiente 0,35)',
    ];

    /** IRS Jovem lasts this many years (art. 12.º-B do CIRS). */
    public const IRS_JOVEM_YEARS = 10;

    public const SS_RATES = [
        '0.214' => 'Trabalhador independente (21,4 %)',
        '0.252' => 'Empresário em nome individual ou EIRL (25,2 %)',
    ];

    /** Where the Segurança Social shows the activity data (rate, enrolment, exemption). */
    public const SS_DIRECTA_ACTIVITY = 'Segurança Social Direta → Trabalho → Remunerações e contribuições → Trabalhadores independentes → Consultar e alterar os dados de atividade';

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
        return $field->hint('Onde ver?')->hintIcon(Phosphor::Question, tooltip: $where);
    }

    public static function activityStartedOn(): DatePicker
    {
        return self::whereToFind(
            DatePicker::make('activity_started_on')
                ->label('Início de atividade')
                ->helperText('A data em que abriste (ou reabriste) atividade nas Finanças.')
                ->native(false)
                ->displayFormat('d/m/Y'),
            'No Portal das Finanças, em Declarações de Atividade: a data da declaração de início (ou de reinício). Também está no comprovativo que recebeste.',
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
                    ->label('É o primeiro início ou um reinício?')
                    ->options(self::START_KINDS)
                    ->default('first')
                    ->required($requireKind)
                    ->descriptions([
                        'first' => 'Nunca tiveste atividade aberta nas Finanças.',
                        'restart' => 'Já tiveste atividade aberta, cessaste-a e voltaste a abrir.',
                    ])
                    ->live()
                    ->columnSpanFull(),
                'No comprovativo da declaração, o título diz «Início» ou «Reinício de Atividade». Se já tiveste atividade aberta e a fechaste, é um reinício (o Portal pode chamar-lhe «Início de atividade» na lista).',
            ),
            self::whereToFind(
                DatePicker::make('previous_activity_ended_on')
                    ->label('Cessação da atividade anterior')
                    ->helperText('Opcional. Sem esta data não aplicamos a redução do IRS dos primeiros anos (só existe se o reinício foi 5 ou mais anos depois da cessação).')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->visible(fn (Get $get): bool => $get('activity_start_kind') === 'restart')
                    ->columnSpanFull(),
                'No comprovativo da declaração de cessação da atividade anterior. O Portal das Finanças pode não a mostrar em Declarações de Atividade; nesse caso pede o histórico no e-balcão, ou deixa em branco.',
            ),
            self::whereToFind(
                DatePicker::make('ss_enrolled_from')
                    ->label('Enquadrado na Segurança Social a partir de')
                    ->helperText('Opcional. Em branco, a app calcula: 12 meses depois de um primeiro início, logo no mês de um reinício.')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->columnSpanFull(),
                self::SS_DIRECTA_ACTIVITY.': «Enquadrado a partir de».',
            ),
            self::whereToFind(
                Toggle::make('dependent_income_current_year')
                    ->label("Tive salário ou pensão em {$year}")
                    ->helperText('Trabalho por conta de outrem ou pensão. Num ano com salário ou pensão não há redução do IRS dos primeiros anos de atividade.')
                    ->columnSpanFull(),
                'Basta um mês desse ano com salário (trabalho por conta de outrem) ou pensão. Vês nos recibos de vencimento ou no anexo A da tua declaração de IRS.',
            ),
            $withPreviousYear ? Toggle::make('dependent_income_previous_year')
                ->label('Tive salário ou pensão em '.($year - 1))
                ->columnSpanFull() : null,
            $withPreviousYear ? self::whereToFind(
                DatePicker::make('obligations_tracked_from')
                    ->label('Acompanhar obrigações desde')
                    ->helperText('Opcional. As obrigações por cumprir desde esta data aparecem no painel como em atraso (até 12 meses para trás). Em branco: desde que criaste a conta na Contiu.')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->columnSpanFull(),
                'Põe a data em que começaste (ou reiniciaste) a atividade se havia obrigações antes de usares a Contiu, por exemplo IVA das compras ao estrangeiro por entregar.',
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

        return [
            self::whereToFind(
                Radio::make('irs_coefficient')
                    ->label('Que serviços prestas?')
                    ->options(self::IRS_COEFFICIENTS)
                    ->default('0.75')
                    ->descriptions([
                        '0.75' => 'As profissões da tabela do art. 151.º do CIRS, por exemplo programadores, designers, consultores.',
                        '0.35' => 'Serviços que não estão na tabela.',
                    ])
                    ->helperText('Conta a natureza do serviço que prestas, não o código CAE ou CIRS da atividade (Circular AT 2/2016). O coeficiente diz que parte do rendimento é tributada.')
                    ->required()
                    ->columnSpanFull(),
                'A tabela do art. 151.º do CIRS (Portaria 1011/2001) lista as profissões, por exemplo 1332 Programadores informáticos. Na dúvida, pergunta ao contabilista: a escolha muda muito o IRS.',
            ),
            self::whereToFind(
                Toggle::make('irs_jovem')
                    ->label('Aderiste ao IRS Jovem?')
                    ->helperText('Isenção de parte do rendimento nos primeiros 10 anos, até aos 35 anos (art. 12.º-B do CIRS). Não muda a Segurança Social.')
                    ->live()
                    ->columnSpanFull(),
                'Escolhe-se na declaração anual de IRS (modelo 3, anexo B). Se o pediste na última declaração, estás aderido.',
            ),
            DatePicker::make('birth_date')
                ->label('Data de nascimento')
                ->helperText('Para o limite dos 35 anos.')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->visible(fn (Get $get): bool => (bool) $get('irs_jovem'))
                ->required(fn (Get $get): bool => (bool) $get('irs_jovem')),
            self::whereToFind(TextInput::make('irs_jovem_year_number')
                ->label("Em {$year}, és no ano n.º")
                ->helperText($irsJovemEnded
                    ? 'O IRS Jovem dura 10 anos: já terminou. Desliga a opção acima.'
                    : 'Do IRS Jovem, de 1 a 10. Nos anos seguintes a app soma 1.')
                ->integer()
                ->minValue(1)
                ->maxValue(self::IRS_JOVEM_YEARS)
                ->visible(fn (Get $get): bool => (bool) $get('irs_jovem'))
                ->required(fn (Get $get): bool => (bool) $get('irs_jovem')),
                'Conta os anos com rendimentos de trabalho ou de atividade (categorias A ou B), desde o primeiro. Não contam os anos sem rendimentos nem os anos em que eras dependente dos teus pais.',
            ),
        ];
    }

    /** @return array<int, Component> */
    public static function socialSecurity(): array
    {
        return [
            self::whereToFind(
                Select::make('ss_rate')
                    ->label('Taxa contributiva')
                    ->options(self::SS_RATES)
                    ->default('0.214')
                    ->required(),
                self::SS_DIRECTA_ACTIVITY.': «Forma de atividade (taxa)».',
            ),
            // Stored as a fraction (0.70); typed as a percentage.
            self::whereToFind(
                TextInput::make('ss_relevant_income_share_percent')
                    ->label('Rendimento relevante')
                    ->helperText('70% para prestação de serviços.')
                    ->numeric()
                    ->step(1)
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(70)
                    ->required(),
                '70% para prestação de serviços e 20% para venda de bens (art. 162.º do Código Contributivo). Na dúvida, deixa 70%.',
            ),
            self::whereToFind(
                Toggle::make('ss_contribution_exempt')
                    ->label('A Segurança Social reconheceu-te isenção de contribuições?')
                    ->helperText('Isenção do art. 157.º do Código Contributivo, por exemplo se acumulas com trabalho por conta de outrem, se és pensionista, ou se no ano anterior só pagaste o mínimo.')
                    ->columnSpanFull(),
                self::SS_DIRECTA_ACTIVITY.': em «Situação atual», se disser «Não tem obrigação de contribuir», liga esta opção.',
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
