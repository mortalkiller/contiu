<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'label' => 'Desativar',

    'modal' => [

        'heading' => 'Desativar os códigos por email',

        'description' => 'Tens a certeza de que queres deixar de receber códigos por email? A tua conta fica sem esta camada extra de segurança.',

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
                'label' => 'Desativar os códigos por email',
            ],

        ],

    ],

    'notifications' => [

        'disabled' => [
            'title' => 'Códigos por email desativados',
        ],

    ],

];
