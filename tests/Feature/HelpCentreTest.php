<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HelpCentre;

beforeEach(fn () => actingAsAdmin());

it('shows the workflow guide with every topic', function () {
    $this->get(HelpCentre::getUrl())
        ->assertOk()
        ->assertSee('Centro de ajuda')
        ->assertSeeInOrder(['Do trabalho ao extrato', 'Recebimentos', 'Despesas', 'Receitas avulsas', 'Extrato bancário', 'Fecho do mês', 'Conta pessoal', 'Notas legais'])
        ->assertSee('Aceitar sugestões')
        ->assertSee('Transferência minha')
        ->assertSee(BankStatementImport::getUrl(), escape: false);
});

it('is reachable from the user menu', function () {
    $this->get(Dashboard::getUrl())->assertSee(HelpCentre::getUrl(), escape: false);
});

it('is linked from the bank statement page', function () {
    $this->get(BankStatementImport::getUrl())->assertSee(HelpCentre::getUrl().'#extrato', escape: false);
});
