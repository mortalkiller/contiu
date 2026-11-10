<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Models\SocialSecurityContribution;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\Period;
use App\Support\SocialSecurity as SocialSecurityService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class SocialSecurity extends Page
{
    use HasTaxSubNavigation;

    protected string $view = 'filament.pages.social-security';

    protected static ?string $title = 'Segurança Social';

    protected static ?string $slug = 'seguranca-social';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Bank;

    protected static string|UnitEnum|null $navigationGroup = 'Impostos';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fiscalSettings')
                ->label('Definições fiscais')
                ->color('gray')
                ->icon(Phosphor::Sliders)
                ->url(FinanceSettingsPage::getUrl()),
            Action::make('portal')
                ->label('Segurança Social Direta')
                ->color('gray')
                ->icon(Phosphor::ArrowSquareOut)
                ->url('https://www.seg-social.pt/ptss/pssd/menu/trabalho/relacoes-trabalho/trabalhadores/trabalhadores-independentes', shouldOpenInNewTab: true),
        ];
    }

    public function recordDeclarationAction(): Action
    {
        $months = app(SocialSecurityService::class)->incomeMonths($this->quarter);

        return Action::make('recordDeclaration')
            ->label(fn (): string => $this->declarationHistory->isEmpty() ? 'Registar valores declarados' : 'Corrigir registo da declaração')
            ->visible(fn (): bool => $this->canWrite())
            ->modalDescription(fn (): string => $this->declarationHistory->isEmpty()
                ? 'Transcreve os totais mensais da declaração já entregue na Segurança Social Direta.'
                : 'Confirma os totais mensais da declaração corrigida na Segurança Social Direta. O registo anterior fica no histórico.')
            ->modalSubmitActionLabel('Confirmar registo local')
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
                Notification::make()->success()->title('Valores declarados registados')->send();
            });
    }

    public function recordContributionAction(): Action
    {
        return Action::make('recordContribution')
            ->label(fn (array $arguments): string => $this->contributionRecord($arguments) === null
                ? 'Confirmar valor'
                : 'Corrigir valor confirmado')
            ->visible(fn (array $arguments): bool => $this->canWrite() && $this->contributionMonth($arguments) !== null)
            ->modalHeading(fn (array $arguments): string => 'Contribuição de '.self::monthLabel($this->contributionMonth($arguments) ?? abort(404)))
            ->modalDescription(fn (array $arguments): string => $this->contributionRecord($arguments) === null
                ? 'Regista o valor exato a pagar indicado na Segurança Social Direta para este mês de contribuição.'
                : 'Confirma o novo valor exato para este mês de contribuição. O valor anterior fica no histórico.')
            ->modalSubmitActionLabel('Confirmar valor localmente')
            ->fillForm(function (array $arguments): array {
                $record = $this->contributionRecord($arguments);

                return ['amount' => $this->euroInputValue($record?->amount), 'revision' => $record?->id];
            })
            ->schema([$this->revisionField(), $this->euroField('amount', 'Valor exato a pagar')])
            ->action(function (array $data, array $arguments, Action $action, SocialSecurityService $security): void {
                abort_unless($this->canWrite(), 403);
                $month = $this->contributionMonth($arguments) ?? abort(404);

                try {
                    $security->confirmContribution($this->quarter, $month->format('Y-m'), $data['amount'], isset($data['revision']) ? (int) $data['revision'] : null);
                } catch (ValidationException $exception) {
                    $this->notifyConflict($exception, $action);
                }

                unset($this->contributionHistory);
                Notification::make()->success()->title('Contribuição confirmada')->send();
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
        return Period::MONTHS[$month->month - 1].' '.$month->year;
    }

    public static function quarterLabel(string $quarter): string
    {
        return substr($quarter, -1).'.º trimestre de '.substr($quarter, 0, 4);
    }

    protected function getViewData(): array
    {
        $security = app(SocialSecurityService::class);

        return [
            ...$security->preparation($this->quarter),
            'quarters' => $security->quarterOptions($this->quarter),
            'incomeMonths' => $security->incomeMonths($this->quarter),
            'contributionMonths' => $security->contributionMonths($this->quarter),
            'declarations' => $this->declarationHistory,
            'contributionsByMonth' => $this->contributionHistory->groupBy(fn (SocialSecurityContribution $record): string => $record->contribution_month->format('Y-m')),
            'summary' => $this->summary(),
        ];
    }

    /**
     * The quarter at a glance, above the detail: what came in, whether the declaration is on
     * record and how many of the three monthly contributions have a confirmed value.
     *
     * @return list<array{label: string, value: string, note?: ?string, tone?: ?string, percent?: ?float}>
     */
    private function summary(): array
    {
        $security = app(SocialSecurityService::class);
        $preparation = $security->preparation($this->quarter);
        $months = $security->contributionMonths($this->quarter);
        $confirmed = $this->contributionHistory
            ->unique(fn (SocialSecurityContribution $record): string => $record->contribution_month->format('Y-m'))
            ->count();
        $declared = $this->declarationHistory->isNotEmpty();
        $exempt = (int) $preparation['receipts']->sum('amount') - $preparation['estimateIncome'];

        return [
            [
                'label' => 'Total registado',
                'value' => Money::format((int) $preparation['receipts']->sum('amount')),
                'note' => trans_choice('{0} Sem recebimentos no trimestre|{1} 1 recebimento no trimestre|[2,*] :count recebimentos no trimestre', $preparation['receipts']->count()),
            ],
            [
                'label' => 'Usado na estimativa',
                'value' => Money::format($preparation['estimateIncome']),
                'note' => $exempt > 0 ? Money::format($exempt).' marcados como isentos ficam de fora' : 'Sem recebimentos isentos',
            ],
            [
                'label' => 'Declaração trimestral',
                'value' => $declared ? 'Registada' : 'Por registar',
                'note' => $declared ? 'Valores declarados guardados no Contiu' : 'Transcreve os totais da Segurança Social Direta',
                'tone' => $declared ? 'success' : 'warning',
            ],
            [
                'label' => 'Contribuições confirmadas',
                'value' => $confirmed.' de '.count($months),
                'percent' => count($months) > 0 ? $confirmed / count($months) * 100 : 0,
            ],
        ];
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
                'required' => 'Introduz um valor. Usa 0 se o valor for zero.',
                'regex' => 'Introduz um montante entre 0 e 999 999 999,99 € com até duas casas decimais.',
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
        Notification::make()->danger()->title('O registo não foi guardado')->body($exception->getMessage())->send();

        $action->cancel();
    }
}
