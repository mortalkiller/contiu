<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'label' => 'Configurar',

    'modal' => [

        'heading' => 'Configurar os códigos por email',

        'description' => 'Vais precisar de um código de 6 dígitos enviado por email sempre que entrares ou fizeres ações sensíveis. Para terminar, abre o teu email e escreve o código que te enviámos.',

        'form' => [

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

        'actions' => [

            'submit' => [
                'label' => 'Ativar os códigos por email',
            ],

        ],

    ],

    'notifications' => [

        'enabled' => [
            'title' => 'Códigos por email ativados',
        ],

    ],

];
