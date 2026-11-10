<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Support\AnnualFiscalReview as Report;
use App\Support\DocumentedTurnover;
use App\Support\Money;
use App\Support\PeriodSummary;
use App\Support\WithholdingReview;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class AnnualFiscalReview extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $title = 'Resumo anual fiscal';

    protected static ?string $slug = 'resumo-anual-fiscal';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ClipboardText;

    protected static string|UnitEnum|null $navigationGroup = 'Impostos';

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function content(Schema $schema): Schema
    {
        $years = collect(array_keys(PeriodSummary::availableYears()))->merge(DocumentedTurnover::issueYears())
            ->unique()->sortDesc()->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all();
        $year = isset($years[$this->year]) ? $this->year : today()->year;
        $report = new Report($year);
        $totals = $report->totals();
        $withholding = WithholdingReview::forYear($year);
        $contributions = $report->confirmedContributions();

        $stale = $withholding->rows->where('current', false)->count();
        $toReview = $totals['otherCurrencies'] + $totals['expensesWithoutProof'] + $totals['expensesToReview'] + $stale;

        return $schema->components([
            Callout::make($toReview === 0 ? 'Pronto para o contabilista: nada por rever' : trans_choice('{1} Falta rever 1 ponto antes de entregares ao contabilista|[2,*] Faltam rever :count pontos antes de entregares ao contabilista', $toReview))
                ->description($toReview === 0
                    ? 'Documentos, despesas e retenções de '.$year.' não têm nada pendente.'
                    : 'Vê em «Dados por rever» o que está em causa: outras moedas, despesas sem comprovativo ou por rever e retenções com a fonte alterada.')
                ->icon($toReview === 0 ? Phosphor::CheckCircle : Phosphor::WarningCircle)
                ->status($toReview === 0 ? 'success' : 'warning'),
            ...($stale === 0 ? [] : [
                Callout::make(trans_choice('{1} 1 retenção tem a fonte alterada e não entra no total retido|[2,*] :count retenções têm a fonte alterada e não entram no total retido', $stale))
                    ->icon(Phosphor::WarningCircle)
                    ->status('warning'),
            ]),
            Section::make()->schema([
                Select::make('year')->label('Ano')->options($years)->selectablePlaceholder(false)->live(),
            ]),
            Section::make('Operações do ano')
                ->columns(['md' => 2, 'xl' => 4])
                ->schema([
                    $this->source('billed', 'Faturado líquido documentado em EUR', $totals['billed'], 'Ver documentos',
                        FiscalDocumentResource::canViewAny() && $totals['billed'] !== null
                            ? FiscalDocumentResource::getUrl('index', $this->filter('billed_year', $year)) : null),
                    $this->source('received', 'Recebimentos pela data de pagamento', $totals['received'], 'Ver recebimentos',
                        PaymentResource::canViewAny() ? PaymentResource::getUrl('index', $this->filter('year', $year)) : null),
                    $this->source('expenses', 'Despesas registadas pelo valor total', $totals['expenses'], 'Ver despesas',
                        ExpenseResource::canViewAny() ? ExpenseResource::getUrl('index', $this->filter('year', $year)) : null),
                    $this->source('taxPaid', 'Impostos e contribuições pagos', $totals['taxPaid'], 'Ver pagamentos de impostos e contribuições',
                        ExpenseResource::canViewAny() ? ExpenseResource::getUrl('index', $this->filter('paid_tax_year', $year)) : null),
                ]),
            Section::make('Dados por rever')
                ->columns(3)
                ->schema([
                    TextEntry::make('otherCurrencies')->label('Documentos de faturação noutras moedas')->state($totals['otherCurrencies']),
                    TextEntry::make('expensesWithoutProof')->label('Despesas sem comprovativo')->state($totals['expensesWithoutProof']),
                    TextEntry::make('expensesToReview')->label('Despesas por rever')->state($totals['expensesToReview']),
                    TextEntry::make('withholdingToReview')->label('Retenções a rever após alteração do documento')->state($withholding->rows->where('current', false)->count()),
                ]),
            Section::make('Confirmações fiscais')
                ->columns(['md' => 2])
                ->schema([
                    $this->source('withholding', 'Retenções documentadas', $withholding->retainedTotal, 'Ver documentos e recebimentos',
                        WithholdingReviewPage::canAccess() ? WithholdingReviewPage::getUrl(['year' => $year]) : null, 'Sem retenções confirmadas'),
                    Section::make('Contribuições de Segurança Social confirmadas')
                        ->schema([
                            TextEntry::make('confirmedSocialSecurity')->hiddenLabel()
                                ->state($contributions->isEmpty() ? 'Sem contribuições confirmadas' : Money::format((int) $contributions->sum('amount')))
                                ->weight('bold'),
                            RepeatableEntry::make('contributions')->hiddenLabel()->contained(false)
                                ->state($contributions->map(fn ($row): array => [
                                    'month' => $row->contribution_month->format('m/Y'),
                                    'quarter' => Report::incomeQuarter($row->contribution_month),
                                    'amount' => $row->amount,
                                ])->all())
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('month')->label('Mês'),
                                    TextEntry::make('amount')->label('Confirmado')->money('EUR', divideBy: 100),
                                    TextEntry::make('quarter')->label('Mapa de origem')
                                        ->url(fn (string $state): string => SocialSecurity::getUrl(['quarter' => $state])),
                                ]),
                        ]),
                ]),
        ]);
    }

    private function source(string $name, string $label, ?int $amount, string $linkLabel, ?string $url, string $empty = 'Sem documentos em EUR'): Section
    {
        return Section::make($label)->schema([
            TextEntry::make($name)->hiddenLabel()->state($amount === null ? $empty : Money::format($amount))->weight('bold'),
            ...($url === null ? [] : [
                TextEntry::make($name.'Link')->hiddenLabel()->state($linkLabel)->url($url)->color('primary'),
            ]),
        ]);
    }

    /** @return array{tableFilters: array<string, array{value: int}>} */
    private function filter(string $filter, int $year): array
    {
        return ['tableFilters' => [$filter => ['value' => $year]]];
    }
}
