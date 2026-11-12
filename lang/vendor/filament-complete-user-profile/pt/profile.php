<?php

return [
    'page' => [
        'label' => 'A minha conta',
        'heading' => 'A minha conta',
        'subheading' => 'O teu perfil, a segurança e o acesso à conta.',
    ],
    'fields' => [
        'avatar' => 'Avatar',
        'locale' => 'Idioma preferido',
    ],
    'profile_information' => [
        'heading' => 'Informações do perfil',
        'description' => 'Atualiza os teus dados pessoais e o perfil.',
    ],
    'overview' => [
        'name' => 'Nome',
        'email' => 'Email',
        'locale' => 'Idioma preferido',
    ],
    'security' => [
        'password' => [
            'action' => 'Alterar palavra-passe',
            'new' => 'Nova palavra-passe',
            'confirmation' => 'Confirmar nova palavra-passe',
        ],
        'reauthentication' => [
            'current_password' => 'Palavra-passe atual',
            'unavailable' => 'Esta conta não pode ser reautenticada com uma palavra-passe local.',
        ],
        'mfa' => [
            'requirement' => 'O modelo autenticável tem de implementar :contract quando a autenticação de dois fatores está ativada.',
        ],
        'email_authentication' => [
            'requirement' => 'O modelo autenticável tem de implementar :contract quando a autenticação por e-mail está ativada.',
            'notifications' => 'O modelo de utilizador autenticado tem de suportar notificações do Laravel para usar autenticação por e-mail.',
            'resend' => [
                'label' => 'Enviar um novo código por email',
                'sent' => 'Foi enviado um novo código de verificação.',
            ],
            'email' => [
                'subject' => 'O teu código de verificação do :app',
                'heading' => 'Confirma que és tu',
                'intro' => 'Pediste um código de verificação para a tua conta :app. Usa o código abaixo para continuares.',
                'expiry' => 'Este código expira dentro de :minutes minuto.|Este código expira dentro de :minutes minutos.',
                'warning' => 'Nunca partilhes este código. A nossa equipa nunca to vai pedir.',
                'ignore' => 'Se não pediste este código, podes ignorar este email.',
                'footer' => 'Mensagem de segurança de :app.',
            ],
        ],
    ],
    'sessions' => [
        'columns' => [
            'device' => 'Dispositivo',
            'ip' => 'IP',
            'last_activity' => 'Última atividade',
            'status' => 'Estado',
        ],
        'status' => [
            'current' => 'Atual',
            'active' => 'Ativa',
        ],
        'actions' => [
            'revoke' => 'Revogar',
            'revoke_others' => 'Revogar outras sessões',
        ],
        'requirements' => [
            'database_driver' => 'A gestão de sessões do browser requer SESSION_DRIVER=database.',
            'table' => 'A tabela de sessões do Laravel configurada não existe.',
        ],
        'device' => [
            'unknown_browser' => 'Navegador desconhecido',
            'unknown_platform' => 'Plataforma desconhecida',
            'tablet' => 'Tablet',
            'mobile' => 'Telemóvel',
            'desktop' => 'Computador',
        ],
        'empty' => 'Sem sessões noutros browsers',
    ],
    'api_tokens' => [
        'columns' => [
            'name' => 'Nome',
            'permissions' => 'Permissões',
            'last_used' => 'Última utilização',
            'expires' => 'Expira',
        ],
        'never' => 'Nunca',
        'fields' => [
            'name' => 'Nome',
            'permissions' => 'Permissões',
            'expiration' => 'Expira em dias',
        ],
        'actions' => [
            'create' => 'Criar token',
            'revoke' => 'Revogar',
            'done' => 'Concluído',
        ],
        'created' => [
            'heading' => 'Token de API criado',
            'warning' => [
                'heading' => 'Copia este token agora',
            ],
            'description' => 'Só o mostramos completo esta vez. Copia-o já: não o vais conseguir ver de novo.',
            'token' => 'Token',
        ],
        'requirements' => [
            'sanctum' => 'O Laravel Sanctum tem de estar instalado para ativar a gestão de tokens de API.',
            'user_model' => 'O modelo de utilizador autenticado tem de usar Laravel\\Sanctum\\HasApiTokens quando a gestão de tokens de API está ativada.',
            'abilities' => 'Configure pelo menos uma permissão permitida para tokens de API antes de ativar a gestão de tokens de API.',
            'context_migration' => 'Publique e execute a migration de contexto de tokens do filament-complete-user-profile antes de ativar tokens de API por tenant.',
            'context_resolver' => 'Registe :contract antes de ativar tokens de API por tenant.',
        ],
        'empty' => 'Sem tokens de API',
    ],
    'features' => [
        'overview' => [
            'label' => 'Visão geral',
            'description' => 'Um resumo da tua conta e da segurança que tens ligada.',
        ],
        'profile' => [
            'label' => 'Perfil',
            'description' => 'Os teus dados pessoais e preferências.',
        ],
        'security' => [
            'label' => 'Segurança',
            'description' => 'A tua palavra-passe e a verificação em dois passos.',
        ],
        'sessions' => [
            'label' => 'Sessões',
            'description' => 'Os dispositivos com sessão iniciada na tua conta.',
        ],
        'api-tokens' => [
            'label' => 'Tokens de API',
            'description' => 'Criar e revogar tokens pessoais de acesso à API.',
        ],
    ],
    'aside' => [
        'security' => [
            'description' => 'Revê a segurança da tua conta.',
            'heading' => 'Segurança da conta',
            'app_authentication' => [
                'label' => 'App de autenticação',
                'enabled' => 'Ativada',
                'disabled' => 'Por configurar',
            ],
            'email_authentication' => [
                'label' => 'Códigos por email',
                'enabled' => 'Ativada',
                'disabled' => 'Por configurar',
            ],
            'sessions' => [
                'label' => 'Sessões ativas',
                'count' => ':count sessão ativa|:count sessões ativas',
            ],
            'api_tokens' => [
                'label' => 'Tokens de acesso pessoal',
                'count' => ':count token|:count tokens',
            ],
        ],
    ],
];
