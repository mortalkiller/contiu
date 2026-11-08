<?php

// Filament's "pt" reads formal and in places Brazilian; the app speaks to the freelancer as "tu".
return [

    'title' => 'Confirma o teu email',

    'heading' => 'Confirma o teu email',

    'actions' => [

        'resend_notification' => [
            'label' => 'Enviar outra vez',
        ],

    ],

    'messages' => [
        'notification_not_received' => 'Não recebeste o email?',
        'notification_sent' => 'Enviámos um email para :email com um link para confirmares o endereço.',
    ],

    'notifications' => [

        'notification_resent' => [
            'title' => 'Email enviado outra vez.',
        ],

        'notification_resend_throttled' => [
            'title' => 'Demasiados pedidos',
            'body' => 'Tenta outra vez daqui a :seconds segundos.',
        ],

    ],

];
