<?php

namespace App\Filament\Widgets;

use App\Enums\ReconciliationStatus;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Support\CompanyAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Conta da empresa": the bank balance (imported statement) against Contiu's balance. The
 * difference is money still to move between Pedro's personal account and the company's.
 * On the month close it looks at the end of that month ($month = "Y-m").
 */
class CompanyAccountStats extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Conta da empresa';

    /** Its balance comes from the statement: only for an account with a bank account for the activity. */
    public static function canView(): bool
    {
        return app(FinanceSettings::class)->hasBusinessAccount();
    }

    public ?string $month = null;

    protected function getColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getDescription(): ?string
    {
        $pending = (new CompanyAccount($this->until()))->pendingLines();

        return 'Saldo do banco comparado com o que registaste na Contiu.'
            .match ($pending) {
                0 => '',
                1 => ' Há 1 linha do extrato por tratar: os valores ainda podem mudar.',
                default => ' Há '.$pending.' linhas do extrato por tratar: os valores ainda podem mudar.',
            };
    }

    private function until(): ?Carbon
    {
        return filled($this->month) ? Carbon::createFromFormat('Y-m-d', $this->month.'-01')->endOfMonth() : null;
    }

    protected function getStats(): array
    {
        $account = new CompanyAccount($this->until());
        $bank = $account->bankBalance();

        if ($bank === null) {
            return [
                Stat::make('Conta da empresa', 'Sem extrato')
                    ->description('Importa o extrato do banco para comparar o saldo.')
                    ->icon(Phosphor::Bank)
                    ->url(BankStatementImport::getUrl()),
            ];
        }

        $difference = (int) $account->difference();
        $pending = $account->pendingLines();
        $notReconciled = fn (string $resource) => $resource::getUrl('index', ['tableFilters' => ['reconciliation' => ['value' => ReconciliationStatus::NotReconciled->value]]]);

        return [
            Stat::make('Saldo no banco', Money::format($bank))
                ->description('Extrato até '.$account->endsOn()?->format('d/m/Y'))
                ->icon(Phosphor::Bank)
                ->url(BankStatementImport::getUrl()),
            Stat::make('Saldo segundo a Contiu', Money::format($account->contiuBalance()))
                ->description('Saldo inicial + recebido − despesas pagas')
                ->icon(Phosphor::Calculator),
            match (true) {
                $difference < 0 => Stat::make('Falta transferir para a conta da empresa', Money::format(-$difference))
                    ->description('Ver o que falta reconciliar')
                    ->descriptionIcon(Phosphor::ArrowRight)
                    ->url($notReconciled(PaymentResource::class))
                    ->color('warning')
                    ->icon(Phosphor::ArrowsLeftRight),
                $difference > 0 => Stat::make('A empresa deve-te', Money::format($difference))
                    ->description('Ver o que falta reconciliar')
                    ->descriptionIcon(Phosphor::ArrowRight)
                    ->url($notReconciled(ExpenseResource::class))
                    ->color('info')
                    ->icon(Phosphor::ArrowsLeftRight),
                default => Stat::make('Diferença', 'Bate certo')
                    ->description($pending > 0 ? 'Falta tratar linhas do extrato.' : 'O banco tem o que a Contiu diz.')
                    ->color($pending > 0 ? 'warning' : 'success')
                    ->icon(Phosphor::CheckCircle),
            },
        ];
    }
}
