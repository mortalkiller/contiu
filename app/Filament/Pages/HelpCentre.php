<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Centro de ajuda": how to use the app day to day, from a new job to the bank statement and the
 * month close, with the worked example agreed with Pedro.
 */
class HelpCentre extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Phosphor::Question;

    protected static ?string $navigationLabel = 'Centro de ajuda';

    protected static ?string $title = 'Centro de ajuda';

    protected static ?string $slug = 'ajuda';

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.help-centre';

    public function getSubheading(): string
    {
        return 'Como usar a app no dia a dia: do trabalho novo ao extrato do banco e ao fecho do mês.';
    }

    /** @return array<int, array{id: string, title: string, summary: string, icon: Phosphor}> */
    public function topics(): array
    {
        return [
            ['id' => 'fluxo', 'title' => 'Do trabalho ao extrato', 'summary' => 'Um exemplo completo, passo a passo.', 'icon' => Phosphor::Path],
            ['id' => 'recebimentos', 'title' => 'Recebimentos', 'summary' => 'Totais, parciais e faturas-recibo.', 'icon' => Phosphor::HandCoins],
            ['id' => 'despesas', 'title' => 'Despesas', 'summary' => 'Registar, recorrentes e fornecedores.', 'icon' => Phosphor::Receipt],
            ['id' => 'receitas-avulsas', 'title' => 'Receitas avulsas', 'summary' => 'Donativos e outras entradas.', 'icon' => Phosphor::Gift],
            ['id' => 'extrato', 'title' => 'Extrato bancário', 'summary' => 'Importar e explicar cada linha.', 'icon' => Phosphor::Bank],
            ['id' => 'fecho-do-mes', 'title' => 'Fecho do mês', 'summary' => 'Confirmar que nada ficou para trás.', 'icon' => Phosphor::CalendarCheck],
            ['id' => 'conta-pessoal', 'title' => 'Conta pessoal', 'summary' => 'Quando o dinheiro passa pela tua conta.', 'icon' => Phosphor::ArrowsLeftRight],
            ['id' => 'notas-legais', 'title' => 'Notas legais', 'summary' => 'O que confirmar com o contabilista.', 'icon' => Phosphor::Scales],
            ['id' => 'conta', 'title' => 'A tua conta e a assinatura', 'summary' => 'Experiência, preços e conta só de leitura.', 'icon' => Phosphor::CreditCard],
        ];
    }

    /**
     * Trial length and prices as the app charges them, so the help never drifts from the
     * subscription page.
     *
     * @return array{trialDays: int, monthly: string, yearly: string}
     */
    public function subscription(): array
    {
        return [
            'trialDays' => (int) config('contiu.trial_days'),
            'monthly' => Money::format(AccountSubscription::PLANS['monthly']['amount']),
            'yearly' => Money::format(AccountSubscription::PLANS['yearly']['amount']),
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
            'dashboard' => Dashboard::getUrl(),
        ];
    }
}
