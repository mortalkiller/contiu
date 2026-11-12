<?php

/*
 * Same as ../pt: Filament builds these lines with trans_choice(), which looks for pt_PT and then
 * the app fallback, without the pt_PT → pt step of AppServiceProvider.
 */

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'subject' => 'O teu código para entrar',

    'lines' => [
        'O teu código para entrar é: :code',
        'Este código expira daqui a um minuto.|Este código expira daqui a :minutes minutos.',
    ],

];
