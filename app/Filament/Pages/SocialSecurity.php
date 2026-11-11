<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Support\PeriodPicker;
use App\Models\SocialSecurityContribution;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\Period;
use App\Support\PortugueseDeadline;
use App\Support\SocialSecurity as SocialSecurityService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class SocialSecurity extends Page
{
    use HasTaxSubNavigation;

    protected string $view = 'filament.pages.social-security';

    protected static ?string $slug = 'seguranca-social';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Bank;

    protected static ?int $navigationSort = 6;

    #[Locked]
    public string $quarter;

    public static function canAccess(): bool
    {
        return CurrentAccount::get() !== null;
    }

    public function mount(SocialSecurityService $security): void
    {
        $quarter = request()->query('quarter', $security->latestClosedQuarter());
        abort_unless(is_string($quarter) && $security->closedQuarter($quarter) !== null, 404);

        $this->quarter = $quarter;
    }

    public function getSubheading(): string
    {
        return __('Social Security, by closed quarter.');
    }

    /** As in the mock: the quarter to show, then the way to Segurança Social Direta. */
    protected function getHeaderActions(): array
    {
        $quarters = app(SocialSecurityService::class)->quarterOptions($this->quarter);

        return [
            PeriodPicker::links(
                'quarter',
                collect($quarters)->mapWithKeys(fn (string $quarter): array => [$quarter => self::quarterRange($quarter)])->all(),
                $this->quarter,
                fn (int|string $quarter): string => static::getUrl(['quarter' => $quarter]),
                __('Quarter closed'),
            ),
            Action::make('portal')
                ->label(__('Social Security Direct'))
                ->color('gray')
                ->url('https://www.seg-social.pt/ptss/pssd/menu/trabalho/relacoes-trabalho/trabalhadores/trabalhadores-independentes', shouldOpenInNewTab: true),
        ];
    }

    public function recordDeclarationAction(): Action
    {
        $months = app(SocialSecurityService::class)->incomeMonths($this->quarter);

        return Action::make('recordDeclaration')
            ->label(fn (): string => $this->declarationHistory->isEmpty() ? __('Record declared amounts') : __('Correct recorded declaration'))
            ->color(fn (): string => $this->declarationHistory->isEmpty() ? 'primary' : 'gray')
            ->visible(fn (): bool => $this->canWrite())
            ->modalDescription(fn (): string => $this->declarationHistory->isEmpty()
                ? __('Copy the monthly totals from the declaration you filed in Social Security Direct.')
                : __('Confirm the monthly totals from the corrected declaration in Social Security Direct. The previous record is kept in the history.'))
            ->modalSubmitActionLabel(__('Confirm local record'))
            ->fillForm(function () use ($months): array {
                $record = $this->declarationHistory->first();
                $values = ['revision' => $record?->id];

                foreach ($months as $index => $month) {
                    $values['month'.$index] = $this->euroInputValue($record?->amounts[$month->format('Y-m')] ?? null);
                }

                return $values;
            })
            ->schema([
                $this->revisionField(),
                ...array_map(
                    fn (int $index, CarbonImmutable $month): TextInput => $this->euroField('month'.$index, self::monthLabel($month)),
                    array_keys($months),
                    $months,
                ),
            ])
            ->action(function (array $data, Action $action, SocialSecurityService $security) use ($months): void {
                abort_unless($this->canWrite(), 403);
                $amounts = [];

                foreach ($months as $index => $month) {
                    $amounts[$month->format('Y-m')] = $data['month'.$index];
                }

                try {
                    $security->recordDeclaration($this->quarter, $amounts, isset($data['revision']) ? (int) $data['revision'] : null);
                } catch (ValidationException $exception) {
                    $this->notifyConflict($exception, $action);
                }

                unset($this->declarationHistory);
                Notification::make()->success()->title(__('Declared amounts recorded'))->send();
            });
    }

    public function recordContributionAction(): Action
    {
        return Action::make('recordContribution')
            ->label(fn (array $arguments): string => $this->contributionRecord($arguments) === null
                ? __('Confirm amount')
                : __('Correct confirmed amount'))
            ->color('gray')
            ->size('sm')
            ->visible(fn (array $arguments): bool => $this->canWrite() && $this->contributionMonth($arguments) !== null)
            ->modalHeading(fn (array $arguments): string => __('Contribution for :month', ['month' => self::monthLabel($this->contributionMonth($arguments) ?? abort(404))]))
            ->modalDescription(fn (array $arguments): string => $this->contributionRecord($arguments) === null
                ? __('Record the exact amount payable shown in Social Security Direct for this contribution month.')
                : __('Confirm the new exact amount for this contribution month. The previous amount is kept in the history.'))
            ->modalSubmitActionLabel(__('Confirm amount locally'))
            ->fillForm(function (array $arguments): array {
                $record = $this->contributionRecord($arguments);

                return ['amount' => $this->euroInputValue($record?->amount), 'revision' => $record?->id];
            })
            ->schema([$this->revisionField(), $this->euroField('amount', __('Exact amount payable'))])
            ->action(function (array $data, array $arguments, Action $action, SocialSecurityService $security): void {
                abort_unless($this->canWrite(), 403);
                $month = $this->contributionMonth($arguments) ?? abort(404);

                try {
                    $security->confirmContribution($this->quarter, $month->format('Y-m'), $data['amount'], isset($data['revision']) ? (int) $data['revision'] : null);
                } catch (ValidationException $exception) {
                    $this->notifyConflict($exception, $action);
                }

                unset($this->contributionHistory);
                Notification::make()->success()->title(__('Contribution confirmed'))->send();
            });
    }

    #[Computed]
    public function declarationHistory(): Collection
    {
        return app(SocialSecurityService::class)->declarationHistory($this->quarter);
    }

    #[Computed]
    public function contributionHistory(): Collection
    {
        return app(SocialSecurityService::class)->contributionHistory($this->quarter);
    }

    public static function monthLabel(CarbonImmutable $month): string
    {
        return Period::of($month->year, $month->month)->label();
    }

    /** "Jul. a set. de 2026", as in the mock's quarter picker. */
    public static function quarterRange(string $quarter): string
    {
        $months = app(SocialSecurityService::class)->incomeMonths($quarter);
        $short = fn (CarbonImmutable $month): string => rtrim($month->locale(app()->getLocale())->translatedFormat('M'), '.');

        return __(':from to :to :year', [
            'from' => Str::ucfirst($short($months[0])),
            'to' => $short($months[2]),
            'year' => $months[0]->year,
        ]);
    }

    public static function quarterLabel(string $quarter): string
    {
        return __('Quarter :quarter of :year', ['quarter' => substr($quarter, -1), 'year' => substr($quarter, 0, 4)]);
    }

    protected function getViewData(): array
    {
        $security = app(SocialSecurityService::class);

        return [
            ...$security->preparation($this->quarter),
            'incomeMonths' => $security->incomeMonths($this->quarter),
            'contributionMonths' => $security->contributionMonths($this->quarter),
            'declarations' => $this->declarationHistory,
            'declarationDueOn' => $this->declarationDueOn(),
            'contributionsByMonth' => $this->contributionHistory->groupBy(fn (SocialSecurityContribution $record): string => $record->contribution_month->format('Y-m')),
            'steps' => $this->steps(),
        ];
    }

    /** The quarterly declaration is due by the end of the month after the quarter, on a working day (SS052). */
    private function declarationDueOn(): CarbonImmutable
    {
        return PortugueseDeadline::of(app(SocialSecurityService::class)->contributionMonths($this->quarter)[0]->endOfMonth()->startOfDay());
    }

    /**
     * "O ciclo deste trimestre", as in the mock: the income recorded, the declaration, then each
     * of the three monthly contributions it sets, each paid by the 20th of the following month.
     *
     * @return list<array{label: string, state: string, done: bool, tone?: ?string}>
     */
    private function steps(): array
    {
        $security = app(SocialSecurityService::class);
        $preparation = $security->preparation($this->quarter);
        $locale = app()->getLocale();
        $today = CarbonImmutable::today('Europe/Lisbon');
        $dueOn = $this->declarationDueOn();
        $declared = $this->declarationHistory->isNotEmpty();
        $confirmed = $this->contributionHistory->groupBy(fn (SocialSecurityContribution $record): string => $record->contribution_month->format('Y-m'));
        $shortDate = fn (CarbonImmutable $date): string => $date->locale($locale)->translatedFormat(__('j M'));

        $steps = [
            [
                'label' => __('Payments received'),
                'state' => __(':amount recorded', ['amount' => Money::format((int) $preparation['receipts']->sum('amount'), locale: $locale)]),
                'done' => true,
            ],
            [
                'label' => __('Declaration'),
                'state' => match (true) {
                    $declared => __('Declaration recorded'),
                    $today->gt($dueOn) => trans_choice('{1} 1 day late|[2,*] :count days late', (int) $dueOn->diffInDays($today)),
                    default => __('By :date', ['date' => $shortDate($dueOn)]),
                },
                'done' => $declared,
                'tone' => ! $declared && $today->gt($dueOn) ? 'danger' : null,
            ],
        ];

        foreach ($security->contributionMonths($this->quarter) as $month) {
            $record = $confirmed->get($month->format('Y-m'))?->first();
            $paymentDue = PortugueseDeadline::of($month->addMonth()->setDay(20));

            $steps[] = [
                'label' => Str::ucfirst($month->locale($locale)->translatedFormat('F')),
                'state' => match (true) {
                    $record !== null => __('Confirmed · :amount', ['amount' => Money::format($record->amount, locale: $locale)]),
                    $month->gt($today) => __('Estimate: :amount', ['amount' => Money::format($preparation['estimatedContributions'][$month->format('Y-m')], locale: $locale)]),
                    default => __('To confirm · by :date', ['date' => $shortDate($paymentDue)]),
                },
                'done' => $record !== null,
                'tone' => $record === null && $month->gt($today) ? 'gray' : null,
            ];
        }

        return $steps;
    }

    private function canWrite(): bool
    {
        return static::canAccess() && ! CurrentAccount::readOnly();
    }

    private function contributionMonth(array $arguments): ?CarbonImmutable
    {
        foreach (app(SocialSecurityService::class)->contributionMonths($this->quarter) as $month) {
            if ($month->format('Y-m') === ($arguments['month'] ?? null)) {
                return $month;
            }
        }

        return null;
    }

    private function contributionRecord(array $arguments): ?SocialSecurityContribution
    {
        $month = $this->contributionMonth($arguments);

        return $month === null ? null : $this->contributionHistory->first(
            fn (SocialSecurityContribution $record): bool => $record->contribution_month->equalTo($month),
        );
    }

    private function revisionField(): Hidden
    {
        return Hidden::make('revision')->rules(['nullable', 'integer', 'min:1']);
    }

    private function euroField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->suffix('€')
            ->inputMode('decimal')
            ->stripCharacters([' ', "\u{00A0}", "\u{202F}"])
            ->regex('/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/')
            ->required()
            ->validationMessages([
                'required' => __('Enter an amount. Use 0 for a zero amount.'),
                'regex' => __('Enter a euro amount between 0 and 999,999,999.99 with up to two decimal places.'),
            ])
            ->dehydrateStateUsing(function (string $state): int {
                [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $state)), 2, '');

                return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            });
    }

    private function euroInputValue(?int $amount): ?string
    {
        return $amount === null ? null : sprintf('%d,%02d', intdiv($amount, 100), $amount % 100);
    }

    private function notifyConflict(ValidationException $exception, Action $action): never
    {
        Notification::make()->danger()->title(__('The record could not be saved'))->body($exception->getMessage())->send();

        $action->cancel();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Social Security');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Taxes');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Social Security');
    }
}
