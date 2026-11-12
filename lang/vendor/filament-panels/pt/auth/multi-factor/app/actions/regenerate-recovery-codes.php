<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'label' => 'Regenerar códigos de recuperação',

    'modal' => [

        'heading' => 'Regenerar os códigos de recuperação',

        'description' => 'Se perdeste os teus códigos de recuperação, podes gerar novos aqui. Os códigos antigos deixam de funcionar logo.',

        'form' => [

            'code' => [

                'label' => 'Escreve o código de 6 dígitos da app de autenticação',

                'validation_attribute' => 'código',

                'messages' => [

                    'invalid' => 'O código que escreveste não é válido.',

                ],

            ],

            'password' => [

                'label' => 'Escreve a tua palavra-passe atual',

                'validation_attribute' => 'palavra-passe',

            ],

        ],

        'actions' => [

            'submit' => [
                'label' => 'Regenerar códigos de recuperação',
            ],

        ],

    ],

    'notifications' => [

        'regenerated' => [
            'title' => 'Novos códigos de recuperação gerados',
        ],

    ],

    'show_new_recovery_codes' => [

        'modal' => [

            'heading' => 'Novos códigos de recuperação',

            'description' => 'Guarda estes códigos de recuperação num sítio seguro. Só os mostramos agora, mas precisas deles se perderes o acesso à app:',

            'actions' => [

                'submit' => [
                    'label' => 'Fechar',
                ],

            ],

        ],

    ],

];
