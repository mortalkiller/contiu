<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'management_schema' => [

        'actions' => [

            'label' => 'Códigos por email',

            'below_content' => 'Recebes um código temporário no teu email para confirmares que és tu ao entrar.',

            'messages' => [
                'enabled' => 'Ativados',
                'disabled' => 'Desligados',
            ],

        ],

    ],

    'login_form' => [

        'label' => 'Enviar um código para o teu email',

        'code' => [

            'label' => 'Escreve o código de 6 dígitos que te enviámos por email',

            'validation_attribute' => 'código',

            'actions' => [

                'resend' => [

                    'label' => 'Enviar um novo código',

                    'notifications' => [

                        'resent' => [
                            'title' => 'Enviámos-te um novo código por email',
                        ],

                    ],

                ],

            ],

            'messages' => [

                'invalid' => 'O código que escreveste não é válido.',

            ],

        ],

    ],

];
