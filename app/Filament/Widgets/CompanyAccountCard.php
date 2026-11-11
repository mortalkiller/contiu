<?php

namespace App\Filament\Widgets;

use App\Enums\Language;
use App\Enums\ReconciliationStatus;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Support\CompanyAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Widgets\Widget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Conta da empresa" on the dashboard, next to the cash flow chart: the bank balance (imported
 * statement) against Contiu's balance, as one section. The difference is money still to move
 * between the personal account and the company's. "Fecho do mês" and "Extrato bancário" show
 * the same figures as stat cards (CompanyAccountStats).
 */
class CompanyAccountCard extends Widget implements HasActions, HasSchemas
{
    use HasSkeletonPlaceholder;
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.company-account-card';

    /** Its balance comes from the statement: only for an account with a bank account for the activity. */
    public static function canView(): bool
    {
        return app(FinanceSettings::class)->hasBusinessAccount();
    }

    public function content(Schema $schema): Schema
    {
        $account = new CompanyAccount;
        $bank = $account->bankBalance();

        return $schema->components([
            Section::make(__('Business account'))
                ->description(__('Bank balance compared with what you recorded in Contiu.'))
                ->schema($bank === null ? [
                    Text::make(__('You have not imported a statement yet. Import your bank statement to compare the balance.')),
                    Actions::make([$this->nextStep($bank, 0)])->fullWidth(),
                ] : [
                    Group::make([
                        $this->line(__('Bank balance'), Money::format($bank, locale: app()->getLocale())),
                        $this->line(__('Balance according to Contiu'), Money::format((int) $account->contiuBalance(), locale: app()->getLocale())),
                        $this->difference((int) $account->difference(), $account->pendingLines()),
                    ])->dense(),
                    Text::make(__('Statement through :date.', ['date' => $account->endsOn()?->format(Language::resolve(app()->getLocale())->dateFormat())]))->color('gray'),
                    Actions::make([$this->nextStep($bank, (int) $account->difference())])->fullWidth(),
                ]),
        ]);
    }

    /** A label on the left and its amount on the right, on one line. */
    private function line(string $label, string $value, ?string $color = null, ?FontWeight $weight = null): Flex
    {
        return Flex::make([
            Text::make($label)->color($weight === null ? 'gray' : 'neutral')->weight($weight),
            Text::make($value)->color($color ?? 'neutral')->weight($weight)->grow(false),
        ])->from('default');
    }

    /**
     * The colour says whose turn it is to move money: the bank has less than Contiu says (a
     * transfer to the company's account is missing), or more (the company owes the owner).
     */
    private function difference(int $difference, int $pendingLines): Flex
    {
        return $this->line(
            __('Difference'),
            $difference === 0 ? __('Matches') : Money::format(abs($difference), locale: app()->getLocale()),
            match (true) {
                $difference < 0 => 'warning',
                $difference > 0 => 'info',
                $pendingLines > 0 => 'warning',
                default => 'success',
            },
            FontWeight::SemiBold,
        );
    }

    /** Where the difference is sorted out: the amounts received, or the expenses, still to reconcile. */
    private function nextStep(?int $bank, int $difference): Action
    {
        $notReconciled = ['tableFilters' => ['reconciliation' => ['value' => ReconciliationStatus::NotReconciled->value]]];

        return match (true) {
            $bank === null => Action::make('importStatement')
                ->label(__('Import statement'))
                ->icon(Phosphor::Bank)
                ->color('gray')
                ->url(BankStatementImport::getUrl()),
            $difference < 0 => Action::make('reconcile')
                ->label(__('View unreconciled lines'))
                ->color('gray')
                ->url(PaymentResource::getUrl('index', $notReconciled)),
            $difference > 0 => Action::make('reconcile')
                ->label(__('View unreconciled lines'))
                ->color('gray')
                ->url(ExpenseResource::getUrl('index', $notReconciled)),
            default => Action::make('reconcile')
                ->label(__('View the statement'))
                ->color('gray')
                ->url(BankStatementImport::getUrl()),
        };
    }
}
