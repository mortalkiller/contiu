<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'management_schema' => [

        'actions' => [

            'label' => 'App de autenticação',

            'below_content' => 'Uma app no telemóvel gera um código temporário para confirmares que és tu ao entrar.',

            'messages' => [
                'enabled' => 'Ativada',
                'disabled' => 'Desligada',
            ],

        ],

    ],

    'login_form' => [

        'label' => 'Usar um código da app de autenticação',

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

];
