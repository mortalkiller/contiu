<?php

it('names the company behind Contiu as provider and data controller', function (string $path, string $sentence) {
    config()->set('contiu.company.vat_number', '500000000');
    config()->set('contiu.company.address', 'Rua de Exemplo 1, 1000-001 Lisboa');
    config()->set('contiu.company.email', 'legal@example.pt');

    $this->get($path)
        ->assertOk()
        ->assertSee($sentence)
        ->assertSee('TrekDev - Tecnologias de Informação, Lda')
        ->assertSee('500000000')
        ->assertSee('Rua de Exemplo 1, 1000-001 Lisboa')
        ->assertSee('mailto:legal@example.pt', escape: false)
        ->assertSee('https://trekdev.com', escape: false);
})->with([
    'terms' => ['/termos', 'O Contiu é um serviço disponibilizado pela'],
    'privacy' => ['/privacidade', 'O Contiu é um serviço disponibilizado pela'],
])->group('ui');

it('leaves out the company details that are not configured', function () {
    config()->set('contiu.company.vat_number', null);
    config()->set('contiu.company.address', null);
    config()->set('contiu.company.email', null);

    $this->get('/privacidade')
        ->assertOk()
        ->assertSee('TrekDev - Tecnologias de Informação, Lda')
        ->assertDontSee('<dt>NIPC', escape: false)
        ->assertDontSee('<dt>Sede', escape: false)
        ->assertDontSee('<dt>Email</dt>', escape: false);
})->group('ui');

it('says the certified accountant service is external and Contiu only an intermediary', function () {
    $this->get('/termos')
        ->assertOk()
        ->assertSee('Serviço de contabilista certificado')
        ->assertSee('externo à TrekDev')
        ->assertSee('A TrekDev atua apenas como intermediária');
})->group('ui');

it('shows the terms with the prices, trial and version as configured, and what is still to confirm', function () {
    $this->get('/termos')
        ->assertOk()
        ->assertSee('<title>Termos e Condições · Contiu</title>', escape: false)
        ->assertSee('Disposições finais')
        ->assertSee('14 dias, com acesso a todas as funcionalidades')
        ->assertSeeInOrder(['Mensal', '5,69', '7,00', 'Anual', '61,46', '75,60'])
        ->assertSeeInOrder(['Regime simplificado', '30,00', 'Contabilidade organizada', '50,00'])
        ->assertSee('mailto:ajuda@contiu.pt', escape: false)
        ->assertSee('Estes Termos entram em vigor a 13 de novembro de 2026.')
        ->assertSee('<p class="placeholder">A confirmar:', escape: false)
        ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
})->group('ui');

it('shows the privacy policy with what the code already answers, and what is still to confirm', function () {
    $this->get('/privacidade')
        ->assertOk()
        ->assertSee('<title>Política de Privacidade · Contiu</title>', escape: false)
        ->assertSeeInOrder(['Responsável pelo tratamento', 'Cookies', 'Alterações a esta política'])
        ->assertSeeInOrder([config('session.cookie'), 'XSRF-TOKEN', 'remember_web_'])
        ->assertSee('uma por ano durante 2 anos')
        ->assertSee('mailto:ajuda@contiu.pt', escape: false)
        ->assertSee('Esta Política entra em vigor a 13 de novembro de 2026.')
        ->assertSee('<p class="placeholder">A confirmar:', escape: false)
        ->assertDontSee('[A CONFIRMAR');
})->group('ui');

it('shows the legal pages inside the site, with the switch between both texts', function (string $path, string $current) {
    $this->get($path)
        ->assertOk()
        ->assertSee('data-site-nav', escape: false)
        ->assertSee('class="st-foot3"', escape: false)
        ->assertSee('<a href="'.route($current).'"  aria-current="page" >', escape: false)
        ->assertSee('Em vigor desde 13 de novembro de 2026')
        ->assertSee('min de leitura');
})->with([
    'terms' => ['/termos', 'legal.terms'],
    'privacy' => ['/privacidade', 'legal.privacy'],
])->group('ui');
