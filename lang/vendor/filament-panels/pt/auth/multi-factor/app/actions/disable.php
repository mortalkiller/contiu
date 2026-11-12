<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'label' => 'Desativar',

    'modal' => [

        'heading' => 'Desativar a app de autenticação',

        'description' => 'Tens a certeza de que queres deixar de usar a app de autenticação? A tua conta fica sem esta camada extra de segurança.',

        'form' => [
            'password' => [
                'label' => 'Palavra-passe atual',
                'validation_attribute' => 'palavra-passe atual',
            ],

            'code' => [

                'label' => 'Escreve o código de 6 dígitos da app de autenticação',

                'validation_attribute' => 'código',

                'actions' => [

                    'use_recovery_code' => [
                        'label' => 'Usar um código de recuperação',
                    ],

                ],

                'messages' => [

                    'invalid' => 'O código que escreveste não é válido.',

                ],

            ],

            'recovery_code' => [

                'label' => 'Ou escreve um código de recuperação',

                'validation_attribute' => 'código de recuperação',

                'messages' => [

                    'invalid' => 'O código de recuperação que escreveste não é válido.',

                ],

            ],

        ],

        'actions' => [

            'submit' => [
                'label' => 'Desativar a app de autenticação',
            ],

        ],

    ],

    'notifications' => [

        'disabled' => [
            'title' => 'App de autenticação desativada',
        ],

    ],

];
