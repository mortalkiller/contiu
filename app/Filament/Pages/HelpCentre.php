<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Support\FinanceSettings;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Centro de ajuda": how to use the app day to day, from a new job to the bank statement and the
 * month close, with the worked example agreed with Pedro.
 */
class HelpCentre extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Phosphor::Question;

    protected static ?string $slug = 'ajuda';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.help-centre';

    public function getSubheading(): string
    {
        return $this->hasBusinessAccount()
            ? __('How to use the app day to day, from new work to bank statements and month close.')
            : __('How to use the app day to day, from new work to payment and month close.');
    }

    /** Without a bank account for the activity, the statement and the transfers are left out. */
    public function hasBusinessAccount(): bool
    {
        return app(FinanceSettings::class)->hasBusinessAccount();
    }

    /** @return array<int, array{id: string, title: string, summary: string, icon: Phosphor}> */
    public function topics(): array
    {
        $bankOnly = ['extrato', 'conta-pessoal'];

        return array_values(array_filter($this->allTopics(), fn (array $topic): bool => $this->hasBusinessAccount() || ! in_array($topic['id'], $bankOnly, true)));
    }

    /** @return array<int, array{id: string, title: string, summary: string, icon: Phosphor}> */
    private function allTopics(): array
    {
        return [
            ['id' => 'fluxo', 'title' => $this->hasBusinessAccount() ? __('From work to bank statement') : __('From work to payment'), 'summary' => __('A complete, step-by-step example.'), 'icon' => Phosphor::Path],
            ['id' => 'recebimentos', 'title' => __('Payments received'), 'summary' => __('Full payments, partial payments and invoice-receipts.'), 'icon' => Phosphor::HandCoins],
            ['id' => 'despesas', 'title' => __('Expenses'), 'summary' => __('Recording expenses, recurring expenses and suppliers.'), 'icon' => Phosphor::Receipt],
            ['id' => 'receitas-avulsas', 'title' => __('One-off receipts'), 'summary' => __('Donations and other inflows.'), 'icon' => Phosphor::Gift],
            ['id' => 'extrato', 'title' => __('Bank statement'), 'summary' => __('Import and explain each line.'), 'icon' => Phosphor::Bank],
            ['id' => 'fecho-do-mes', 'title' => __('Month close'), 'summary' => __('Check that everything is accounted for.'), 'icon' => Phosphor::CalendarCheck],
            ['id' => 'conta-pessoal', 'title' => __('Personal account'), 'summary' => __('When money goes through your own account.'), 'icon' => Phosphor::ArrowsLeftRight],
            ['id' => 'obrigacoes-fiscais', 'title' => __('Tax obligations'), 'summary' => __('What to pay and file each month.'), 'icon' => Phosphor::CalendarDots],
            ['id' => 'notas-legais', 'title' => __('Legal notes'), 'summary' => __('What to check with your accountant.'), 'icon' => Phosphor::Scales],
            ['id' => 'conta', 'title' => __('Your account and subscription'), 'summary' => __('Trial, pricing and read-only access.'), 'icon' => Phosphor::CreditCard],
        ];
    }

    /**
     * Trial length and prices (VAT included) as the app charges them, so the help never drifts
     * from the subscription page.
     *
     * @return array{trialDays: int, monthly: string, yearly: string}
     */
    public function subscription(): array
    {
        return [
            'trialDays' => (int) config('contiu.trial_days'),
            'monthly' => Money::format(AccountSubscription::priceWithVat('monthly'), locale: app()->getLocale()),
            'yearly' => Money::format(AccountSubscription::priceWithVat('yearly'), locale: app()->getLocale()),
        ];
    }

    /** @return array<string, string> */
    public function links(): array
    {
        return [
            'newProject' => ProjectResource::getUrl('create'),
            'newRetainer' => RetainerResource::getUrl('create'),
            'payments' => PaymentResource::getUrl(),
            'expenses' => ExpenseResource::getUrl(),
            'newExpense' => ExpenseResource::getUrl('create'),
            'recurring' => RecurringExpenseResource::getUrl(),
            'bank' => BankStatementImport::getUrl(),
            'monthClose' => MonthClose::getUrl(),
            'settings' => FinanceSettingsPage::getUrl(),
            'suppliers' => SupplierResource::getUrl(),
            'clients' => ClientResource::getUrl(),
            'dashboard' => Dashboard::getUrl(),
        ];
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Help center');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Help center');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }
}
