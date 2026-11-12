<?php

use App\Support\Money;
use App\Support\TaxProfileDeduction;
use Carbon\CarbonImmutable;

/*
 * The sign-up answers in plain language and the fiscal values Contiu deduces from them, with the
 * rules of docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md (rule IDs in the test names).
 */

pest()->group('integration', 'fiscal');

/** A programmer who opened the activity on 10 March 2026, only self-employed, asked on 11 November 2026. */
function deduce(array $answers = [], string $today = '2026-11-11'): TaxProfileDeduction
{
    return TaxProfileDeduction::from([
        'activity_opened' => 'yes',
        'accounting_regime' => 'simplified',
        'activity_started_on' => '2026-03-10',
        'activity_start_kind' => 'first',
        'situation' => ['self_only'],
        'birth_date' => '1980-01-01',
        'work_kind' => 'listed',
        'expected_turnover' => 1200000,
        'vat_answer' => 'exempt',
        ...$answers,
    ], CarbonImmutable::parse($today));
}

/** With a job for an unrelated employer paying at least 1 IAS into the Segurança Social. */
function withJob(array $answers = []): array
{
    return ['situation' => ['employment'], 'employer_unrelated' => 'yes', 'salary_at_least_ias' => 'yes', 'employment_scheme' => 'ss', ...$answers];
}

it('keeps an account on hold with no fiscal values until the activity opens', function () {
    $deduction = deduce(['activity_opened' => 'no']);

    expect($deduction->path())->toBe('waiting')
        ->and($deduction->settings())->toBe(['activity_opened' => false]);
});

it('keeps only the activity and the VAT with organised accounting (REG002)', function () {
    $deduction = deduce(['accounting_regime' => 'organised', 'vat_answer' => 'normal']);

    expect($deduction->path())->toBe('organised')
        ->and($deduction->settings())->toMatchArray(['accounting_regime' => 'organised', 'activity_opened' => true, 'vat_exempt' => false])
        ->and($deduction->settings())->not->toHaveKeys(['ss_rate', 'irs_coefficient', 'ss_contribution_exempt']);
});

it('assumes the simplified regime when the user does not know it, and says why (REG001)', function () {
    $deduction = deduce(['accounting_regime' => 'unknown']);

    expect($deduction->path())->toBe('simplified')
        ->and($deduction->settings()['accounting_regime'])->toBe('simplified')
        ->and($deduction->regimeNote())->toContain(Money::format(TaxProfileDeduction::SIMPLIFIED_REGIME_LIMIT));
});

it('starts Social Security on the first day of the 12th month after a first start (SS011)', function () {
    $deduction = deduce();

    expect($deduction->ssStartsOn()?->toDateString())->toBe('2027-03-01')
        // The estimate works it out itself: only a date given by the Segurança Social is stored.
        ->and($deduction->settings()['ss_enrolled_from'])->toBeNull();
});

it('takes the Social Security Direct date after a restart (SS012)', function () {
    $deduction = deduce(['activity_start_kind' => 'restart', 'ss_enrolled_from' => '2026-04-15', 'previous_activity_ended_on' => '2018-01-31']);

    expect($deduction->ssStartsOn()?->toDateString())->toBe('2026-04-01')
        ->and($deduction->settings())->toMatchArray([
            'activity_start_kind' => 'restart',
            'ss_enrolled_from' => '2026-04-15',
            'previous_activity_ended_on' => '2018-01-31',
        ]);
});

it('deduces the accumulation exemption and no quarterly declaration as a proposal (SS040, SS051)', function () {
    $deduction = deduce(withJob());
    $card = collect($deduction->cards())->firstWhere('key', 'social_security');

    expect($deduction->settings())->toMatchArray(['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'accumulation', 'dependent_income_years' => [2026]])
        ->and($deduction->quarterlyDeclarationDue())->toBeFalse()
        ->and($card['status'])->toBe(TaxProfileDeduction::PROBABLE)
        ->and($card['confirm'])->toContain('Não tem obrigação de contribuir');
});

it('charges on the excess from exactly 4 IAS of relevant income a month (SS042)', function (int $turnover, bool $onExcess) {
    // Sales of goods count 20 %; an activity from before this year spreads over 12 months.
    $deduction = deduce(withJob(['activity_started_on' => '2024-01-01', 'work_kind' => 'goods', 'expected_turnover' => $turnover]));

    expect($deduction->ssPaysOnExcess())->toBe($onExcess)
        ->and($deduction->quarterlyDeclarationDue())->toBe($onExcess)
        // Still exempt by accumulation: the estimate charges only the excess.
        ->and($deduction->settings()['ss_exemption_reason'])->toBe('accumulation');
})->with([
    'exactly 4 IAS (2 148,52 €)' => [12891120, true],
    'just under 4 IAS' => [12891000, false],
]);

it('gives no exemption when the job does not meet the conditions (SS006, SS040, SS041)', function (array $job, string $blocker, string $status) {
    $deduction = deduce(withJob($job));

    expect($deduction->employmentBlocker())->toBe($blocker)
        ->and($deduction->settings()['ss_contribution_exempt'])->toBeFalse()
        ->and($deduction->quarterlyDeclarationDue())->toBeTrue()
        ->and(collect($deduction->cards())->firstWhere('key', 'social_security')['status'])->toBe($status);
})->with([
    'employer linked to the clients' => [['employer_unrelated' => 'no'], 'same_employer', TaxProfileDeduction::DEDUCED],
    'salary under 1 IAS' => [['salary_at_least_ias' => 'no'], 'low_salary', TaxProfileDeduction::DEDUCED],
    'contributions to the CGA' => [['employment_scheme' => 'cga'], 'cga', TaxProfileDeduction::PROBABLE],
]);

it('proposes the exemption of an old-age or disability pension (SS043)', function () {
    $deduction = deduce(['situation' => ['pension']]);

    expect($deduction->settings())->toMatchArray(['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'pension', 'dependent_income_years' => [2026]]);
});

it('cuts the coefficient in the first two years of services only (INI001, INI002)', function (array $answers, string $today, float $reduction, ?string $blocker) {
    $deduction = deduce($answers, $today);

    expect($deduction->irsReduction())->toBe($reduction)
        ->and($deduction->irsReductionBlocker())->toBe($blocker);
})->with([
    'start year' => [[], '2026-11-11', 0.5, null],
    'next year' => [[], '2027-06-01', 0.25, null],
    'third year' => [[], '2028-06-01', 0.0, null],
    'with a salary this year' => [withJob(), '2026-11-11', 0.0, 'employment'],
    'with a pension this year' => [['situation' => ['pension']], '2026-11-11', 0.0, 'pension'],
    'selling goods' => [['work_kind' => 'goods'], '2026-11-11', 0.0, 'goods'],
    'restart exactly five years after closing' => [['activity_start_kind' => 'restart', 'activity_started_on' => '2026-03-10', 'previous_activity_ended_on' => '2021-03-10', 'ss_enrolled_from' => '2026-03-01'], '2026-11-11', 0.5, null],
    'restart under five years after closing' => [['activity_start_kind' => 'restart', 'activity_started_on' => '2026-03-10', 'previous_activity_ended_on' => '2021-03-11', 'ss_enrolled_from' => '2026-03-01'], '2026-11-11', 0.0, 'recent_restart'],
    'restart without the closing date' => [['activity_start_kind' => 'restart', 'ss_enrolled_from' => '2026-03-01'], '2026-11-11', 0.0, 'unknown_cessation'],
]);

it('takes the coefficient and the Social Security share from the work (SIM001–SIM003, SS020)', function (string $kind, float $coefficient, bool $sellsGoods) {
    $settings = deduce(['work_kind' => $kind])->settings();

    expect($settings['irs_coefficient'])->toBe($coefficient)
        ->and($settings['sells_goods'])->toBe($sellsGoods)
        ->and($settings['ss_rate'])->toBe(0.214);
})->with([
    'occupation on the list' => ['listed', 0.75, false],
    'other services' => ['other', 0.35, false],
    'sale of goods' => ['goods', 0.15, true],
]);

it('proposes the sole trader rate only for a purely commercial or industrial activity (SS002, SS003)', function () {
    $deduction = deduce(['work_kind' => 'goods', 'commercial_only' => 'yes']);

    expect($deduction->settings()['ss_rate'])->toBe(0.252)
        ->and(collect($deduction->cards())->firstWhere('key', 'social_security')['status'])->toBe(TaxProfileDeduction::PROBABLE)
        ->and(deduce(['work_kind' => 'listed', 'commercial_only' => 'yes'])->settings()['ss_rate'])->toBe(0.214);
});

it('proposes the sole trader rate for a commercial service that is not on the table (SS003, SSC09)', function () {
    $deduction = deduce(['work_kind' => 'other', 'commercial_only' => 'yes', 'activity_started_on' => '2024-03-10']);

    expect($deduction->settings()['ss_rate'])->toBe(0.252)
        ->and(collect($deduction->cards())->firstWhere('key', 'social_security')['value'])->toContain('70%')
        ->and(deduce(['work_kind' => 'other', 'commercial_only' => 'no'])->settings()['ss_rate'])->toBe(0.214);
});

it('keeps 21.4% for a mixed activity, to confirm with the ISS (SS003)', function (string $workKind) {
    $deduction = deduce(['work_kind' => $workKind, 'commercial_only' => 'mixed']);
    $card = collect($deduction->cards())->firstWhere('key', 'social_security');

    expect($deduction->settings()['ss_rate'])->toBe(0.214)
        ->and($deduction->ssRateToConfirm())->toBeTrue()
        ->and($card['status'])->toBe(TaxProfileDeduction::PROBABLE)
        ->and($card['reason'])->toContain('fica a confirmar com a Segurança Social (ISS)')
        ->and($card['confirm'])->toContain('Segurança Social Direta')
        ->and(collect($deduction->known())->firstWhere('label', 'Taxa Seg. Social')['value'])->toContain('(a confirmar)')
        ->and(deduce(['work_kind' => $workKind, 'commercial_only' => 'no'])->ssRateToConfirm())->toBeFalse();
})->with(['goods', 'other']);

it('warns that the IRS Jovem cap also counts the salary (JOV005, IRS09)', function () {
    $young = ['irs_jovem' => 'yes', 'irs_jovem_year_number' => 2, 'birth_date' => '1997-05-14'];
    $reason = fn (array $answers): string => collect(deduce($answers)->cards())->firstWhere('key', 'irs')['reason'];

    expect($reason(withJob($young)))->toContain('O teto anual de '.Money::format(2954215).' também conta com o teu salário')
        ->and($reason($young))->not->toContain('também conta com o teu salário');
});

it('applies IRS Jovem by year number up to 35 (JOV004, JOV005)', function (string $birth, int $number, ?float $percent) {
    $deduction = deduce(['birth_date' => $birth, 'irs_jovem' => 'yes', 'irs_jovem_year_number' => $number]);

    expect($deduction->irsJovemPercent())->toBe($percent)
        ->and($deduction->settings()['irs_jovem'])->toBe($percent !== null)
        ->and($deduction->settings()['irs_jovem_year'])->toBe($percent !== null ? ['year' => 2026, 'number' => $number] : null);
})->with([
    'first year' => ['1997-05-14', 1, 1.0],
    'second year' => ['1997-05-14', 2, 0.75],
    'fifth year' => ['1997-05-14', 5, 0.5],
    'tenth year' => ['1997-05-14', 10, 0.25],
    '35 on 31 December' => ['1991-12-31', 2, 0.75],
    '36 on 31 December' => ['1990-12-31', 2, null],
]);

it('proposes the VAT exemption up to and including the Article 53 limit (IVA08)', function (int $turnover, bool $exempt) {
    $deduction = deduce(['vat_answer' => 'unknown', 'expected_turnover' => $turnover]);

    expect($deduction->settings()['vat_exempt'])->toBe($exempt)
        ->and(collect($deduction->cards())->firstWhere('key', 'vat')['status'])->toBe(TaxProfileDeduction::PROBABLE);
})->with([
    'exactly 15 000 €' => [1500000, true],
    'one cent over' => [1500001, false],
]);

it('says when a declared exemption ends with the expected turnover (IVA10)', function (int $turnover, ?string $ends) {
    expect(deduce(['vat_answer' => 'exempt', 'expected_turnover' => $turnover])->vatExemptionEnds())->toBe($ends);
})->with([
    'at the limit' => [1500000, null],
    'within the tolerance' => [1600000, 'next_year'],
    'exactly 18 750 €' => [1875000, 'next_year'],
    'over the tolerance' => [1875001, 'immediately'],
]);

it('reads the yearly values of the year it is asked in', function () {
    config(['contiu.ias.2027' => 55000, 'contiu.vat_exemption_limit.2027' => 1600000]);

    $deduction = deduce(['activity_started_on' => '2027-02-01'], '2027-03-01');

    expect($deduction->accumulationLimit())->toBe(220000)
        ->and($deduction->settings()['vat_exemption_threshold'])->toBe(1600000)
        ->and(deduce()->accumulationLimit())->toBe(214852);
});

it('gives each value its reason in words', function () {
    $deduction = deduce(withJob(['irs_jovem' => 'yes', 'irs_jovem_year_number' => 2, 'birth_date' => '1997-05-14', 'profession' => '1332']));
    $cards = collect($deduction->cards())->keyBy('key');

    expect($deduction->summary())->toBe('Em 2026 não pagas Segurança Social nem cobras IVA. No IRS, o IRS Jovem deixa de fora 75% do que receberes.')
        ->and($cards['social_security']['value'])->toBe('Não pagas, por agora')
        ->and($cards['irs']['reason'])->toContain('A tua profissão (1332) está na lista do art. 151.º, por isso o coeficiente é 0,75.')
        ->toContain('Sem a redução dos primeiros anos, porque tens salário em 2026.')
        ->and($cards['vat']['value'])->toBe('Não cobras IVA')
        ->and(collect($deduction->known())->pluck('value', 'label')->all())->toMatchArray([
            'Contribuições' => 'Isento (provável)',
            'Declaração trimestral' => 'Dispensada',
            'IRS Jovem' => 'Ano n.º 2',
            'Segurança Social' => 'Desde mar. 2027',
        ]);
});

it('prepares the confirmed tax profile only when an exemption was deduced', function () {
    $confirmation = deduce(withJob())->exemptionConfirmation('Rita Silva', '501964843');

    expect($confirmation)->toMatchArray([
        'legal_name' => 'Rita Silva',
        'vat_number' => '501964843',
        'ss_contribution_exempt' => 'yes',
        'has_dependent_employment' => 'yes',
        'activity_started_on' => '2026-03-10',
        'effective_from' => '2026-11-11',
    ])->not->toHaveKey('vat_exempt')
        ->and(deduce()->exemptionConfirmation('Rita Silva', '501964843'))->toBeNull();
});
