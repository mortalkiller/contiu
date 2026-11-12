<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
// Only the two-step verification keys; the rest falls back to Filament's file.
return [

    'multi_factor' => [

        'heading' => 'Código de verificação',

        'subheading' => 'Confirma que és tu com o código de verificação.',

        'form' => [

            'provider' => [
                'label' => 'Como queres confirmar?',
            ],

            'actions' => [

                'authenticate' => [
                    'label' => 'Confirmar e entrar',
                ],

            ],

        ],

    ],

];
