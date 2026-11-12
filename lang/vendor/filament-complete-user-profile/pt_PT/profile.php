<?php

/*
 * Only the lines the package builds with trans_choice(): it looks for pt_PT and then the app
 * fallback, without the pt_PT → pt step of AppServiceProvider. Everything else comes from ../pt.
 */
return [
    'security' => [
        'email_authentication' => [
            'email' => [
                'expiry' => 'Este código expira daqui a :minutes minuto.|Este código expira daqui a :minutes minutos.',
            ],
        ],
    ],
];
