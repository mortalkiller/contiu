<?php

// Filament's "pt" reads formal; the app speaks to the freelancer as "tu".
return [

    'label' => 'Configurar',

    'modal' => [

        'heading' => 'Configurar a app de autenticação',

        'description' => <<<'BLADE'
            Precisas de uma app como o Google Authenticator (<x-filament::link href="https://itunes.apple.com/us/app/google-authenticator/id388497605" target="_blank">iOS</x-filament::link>, <x-filament::link href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</x-filament::link>) no telemóvel.
            BLADE,

        'content' => [

            'qr_code' => [

                'instruction' => 'Lê este código QR com a tua app de autenticação:',

                'alt' => 'Código QR para ler com uma app de autenticação',

            ],

            'text_code' => [

                'instruction' => 'Ou escreve este código à mão:',

                'messages' => [
                    'copied' => 'Copiado',
                ],

            ],

            'recovery_codes' => [

                'instruction' => 'Guarda estes códigos de recuperação num sítio seguro. Só os mostramos agora, mas precisas deles se perderes o acesso à app:',

            ],

        ],

        'form' => [

            'password' => [
                'label' => 'Palavra-passe atual',
                'validation_attribute' => 'palavra-passe atual',
            ],

            'code' => [

                'label' => 'Escreve o código de 6 dígitos da app de autenticação',

                'validation_attribute' => 'código',

                'below_content' => 'Vais precisar do código da app sempre que entrares ou fizeres ações sensíveis.',

                'messages' => [

                    'invalid' => 'O código que escreveste não é válido.',

                ],

            ],

        ],

        'actions' => [

            'submit' => [
                'label' => 'Ativar a app de autenticação',
            ],

        ],

    ],

    'notifications' => [

        'enabled' => [
            'title' => 'App de autenticação ativada',
        ],

    ],

];
