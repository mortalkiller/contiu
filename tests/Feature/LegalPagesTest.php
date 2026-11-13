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
    'terms' => ['/termos', 'O Contiu é um serviço desenvolvido e prestado pela'],
    'privacy' => ['/privacidade', 'A responsável pelo tratamento dos dados pessoais da tua conta Contiu é a'],
])->group('ui');

it('leaves out the company details that are not configured', function () {
    config()->set('contiu.company.vat_number', null);
    config()->set('contiu.company.address', null);
    config()->set('contiu.company.email', null);

    $this->get('/termos')
        ->assertOk()
        ->assertSee('TrekDev - Tecnologias de Informação, Lda')
        ->assertDontSee('NIPC')
        ->assertDontSee('Sede')
        ->assertDontSee('mailto:', escape: false);
})->group('ui');

it('says the certified accountant service is external and Contiu only an intermediary', function () {
    $this->get('/termos')
        ->assertOk()
        ->assertSee('Serviço de contabilista certificado')
        ->assertSee('externo ao Contiu')
        ->assertSee('O Contiu funciona apenas como intermediário');
})->group('ui');
