<?php

namespace App\Filament\Widgets;

use App\Enums\BankTransactionStatus;
use App\Enums\Language;
use App\Enums\ReconciliationStatus;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\BankTransaction;
use App\Support\CompanyAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
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

    protected ?string $heading = 'Business account';

    /** Its balance comes from the statement: only for an account with a bank account for the activity. */
    public static function canView(): bool
    {
        return app(FinanceSettings::class)->hasBusinessAccount();
    }

    public ?string $month = null;

    /**
     * On "Extrato bancário": a fourth card with how many statement lines are already explained.
     * The dashboard and "Fecho do mês" leave it out.
     */
    public bool $withLineProgress = false;

    protected function getColumns(): int|array
    {
        return $this->withLineProgress ? ['md' => 2, 'xl' => 4] : ['md' => 3];
    }

    protected function getHeading(): ?string
    {
        return __('Business account');
    }

    protected function getDescription(): ?string
    {
        $pending = (new CompanyAccount($this->until()))->pendingLines();

        return __('Bank balance compared with what you recorded in Contiu.')
            .match ($pending) {
                0 => '',
                default => ' '.trans_choice('{1} There is 1 statement line to process: amounts may still change.|[2,*] There are :count statement lines to process: amounts may still change.', $pending),
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
                Stat::make(__('Business account'), __('No statement'))
                    ->description(__('Import your bank statement to compare the balance.'))
                    ->icon(Phosphor::Bank)
                    ->url(BankStatementImport::getUrl()),
            ];
        }

        $difference = (int) $account->difference();
        $pending = $account->pendingLines();
        $notReconciled = fn (string $resource) => $resource::getUrl('index', ['tableFilters' => ['reconciliation' => ['value' => ReconciliationStatus::NotReconciled->value]]]);

        return [
            Stat::make(__('Bank balance'), Money::format($bank, locale: app()->getLocale()))
                ->description(__('Statement through :date', ['date' => $account->endsOn()?->format(Language::resolve(app()->getLocale())->dateFormat())]))
                ->icon(Phosphor::Bank)
                ->url(BankStatementImport::getUrl()),
            Stat::make(__('Balance according to Contiu'), Money::format($account->contiuBalance(), locale: app()->getLocale()))
                ->description(__('Opening balance + payments received − paid expenses'))
                ->icon(Phosphor::Calculator),
            match (true) {
                $difference < 0 => Stat::make(__('Amount to transfer to the business account'), Money::format(-$difference, locale: app()->getLocale()))
                    ->description(__('View unreconciled lines'))
                    ->descriptionIcon(Phosphor::ArrowRight)
                    ->url($notReconciled(PaymentResource::class))
                    ->color('warning')
                    ->icon(Phosphor::ArrowsLeftRight),
                $difference > 0 => Stat::make(__('The business owes you'), Money::format($difference, locale: app()->getLocale()))
                    ->description(__('View unreconciled lines'))
                    ->descriptionIcon(Phosphor::ArrowRight)
                    ->url($notReconciled(ExpenseResource::class))
                    ->color('info')
                    ->icon(Phosphor::ArrowsLeftRight),
                default => Stat::make(__('Difference'), __('Matches'))
                    ->description($pending > 0 ? __('Some statement lines still need processing.') : __('The bank balance matches Contiu.'))
                    ->color($pending > 0 ? 'warning' : 'success')
                    ->icon(Phosphor::CheckCircle),
            },
            ...($this->withLineProgress ? [$this->lineProgressStat()] : []),
        ];
    }

    /** Ignored lines are out of the count: they were set aside, not explained. */
    private function lineProgressStat(): Stat
    {
        $treated = BankTransaction::query()
            ->whereIn('status', [BankTransactionStatus::Matched, BankTransactionStatus::OwnTransfer])
            ->count();
        $total = $treated + BankTransaction::pending()->count();
        $percent = $total === 0 ? 0 : (int) floor($treated / $total * 100);

        return Stat::make(__('Processed lines'), __(':treated of :total', ['treated' => $treated, 'total' => $total]))
            ->description(new HtmlString(
                '<span class="fap-progress" role="img" aria-label="'.e(__(':percent% of lines processed', ['percent' => $percent])).'"><i style="width: '.$percent.'%"></i></span>',
            ))
            ->color($treated === $total ? 'success' : null)
            ->icon(Phosphor::ListChecks);
    }
}
