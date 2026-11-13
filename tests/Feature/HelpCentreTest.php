<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\HelpCentre;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Support\Money;

pest()->group('ui');

beforeEach(fn () => actingAsAdmin());

it('shows the workflow guide with every topic', function () {
    $this->get(HelpCentre::getUrl())
        ->assertOk()
        ->assertSee('Centro de ajuda')
        ->assertSee(BankStatementImport::getUrl(), escape: false);
});

it('is reachable from the user menu', function () {
    $this->get(Dashboard::getUrl())->assertSee(HelpCentre::getUrl(), escape: false);
});

it('is linked from the bank statement page', function () {
    $this->get(BankStatementImport::getUrl())->assertSee(HelpCentre::getUrl().'#extrato', escape: false);
});

it('explains the trial, the plans and the read-only account', function () {
    $this->get(HelpCentre::getUrl())
        ->assertOk()
        ->assertSee('href="#conta"', escape: false)
        ->assertSeeInOrder(['Notas legais', 'A tua conta e a assinatura'])
        ->assertSee('14 dias')
        // The prices with VAT, as charged.
        ->assertSee(Money::format(700))
        ->assertSee(Money::format(7560))
        ->assertSee('só de leitura')
        ->assertSee('portal de faturação da Stripe');
});

it('explains the monthly tax obligations and where their answers come from', function () {
    $this->get(HelpCentre::getUrl())
        ->assertOk()
        ->assertSee('href="#obrigacoes-fiscais"', escape: false)
        ->assertSeeInOrder(['Obrigações fiscais', 'Notas legais'])
        ->assertSee('Este mês')
        ->assertSee('declaração recapitulativa')
        ->assertSee(FinanceSettingsPage::getUrl(), escape: false)
        ->assertSee(SupplierResource::getUrl(), escape: false)
        ->assertSee(ClientResource::getUrl(), escape: false);
});

it('points the legal notes to the exempt payments, not an IRS exemption setting', function () {
    $this->get(HelpCentre::getUrl())
        ->assertOk()
        ->assertDontSee('se a tua isenção de IRS os abrange')
        ->assertSee('marca esses recebimentos como isentos de impostos');
});
