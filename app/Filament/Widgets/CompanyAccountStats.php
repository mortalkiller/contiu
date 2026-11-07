<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\BankStatementImport;
use App\Support\CompanyAccount;
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

    public ?string $month = null;

    protected function getColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getDescription(): ?string
    {
        return 'Saldo do banco comparado com o que registaste na Contiu: a diferença é dinheiro por passar entre a tua conta pessoal e a da empresa.';
    }

    protected function getStats(): array
    {
        $until = filled($this->month) ? Carbon::createFromFormat('Y-m-d', $this->month.'-01')->endOfMonth() : null;
        $account = new CompanyAccount($until);
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
        $pendingNote = match ($pending) {
            0 => null,
            1 => '1 linha do extrato por tratar: o valor ainda pode mudar.',
            default => $pending.' linhas do extrato por tratar: o valor ainda pode mudar.',
        };

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
                    ->description($pendingNote ?? 'Recebeste na conta pessoal dinheiro da empresa.')
                    ->color('warning')
                    ->icon(Phosphor::ArrowsLeftRight),
                $difference > 0 => Stat::make('A empresa deve-te', Money::format($difference))
                    ->description($pendingNote ?? 'Pagaste despesas da empresa com a conta pessoal.')
                    ->color('info')
                    ->icon(Phosphor::ArrowsLeftRight),
                default => Stat::make('Diferença', 'Bate certo')
                    ->description($pendingNote ?? 'O banco tem o que a Contiu diz.')
                    ->color($pending > 0 ? 'warning' : 'success')
                    ->icon(Phosphor::CheckCircle),
            },
        ];
    }
}
