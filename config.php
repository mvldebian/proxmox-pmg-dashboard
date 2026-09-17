<?php
return [
    'pmg' => [
        'host'       => 'https://enderecoseupmg:8006',
        'node'       => 'nomedonode',
        'user'       => 'dashboard@pmg',
        'password'   => 'SenhaForte',
        // Mantenha false para certificados auto assinados
        'verify_ssl' => false,
        'timeout'    => 10,
    ],

    'dashboard' => [
        'title'           => 'PMG Dashboard',
        'refresh_seconds' => 10,
        'timezone'        => 'America/Sao_Paulo',
        'enable_uptime'   => true,
        'enable_traffic'  => true,
        'stats_period'    => 'daily',
        'public_ip_override' => null,
        'relay_external_port' => 25,
        'relay_internal_port' => 26,
    ],

    // ===== Banco de dados MySQL =====
    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'dashboard',
        'username' => 'dashboard',
        'password' => 'SenhaForte',
        'charset'  => 'utf8mb4',
    ],

    'auth' => [
        'code_ttl'         => 300,       // validade do código 2FA (s)
        'max_attempts'     => 5,         // tentativas do código 2FA
        'session_lifetime' => 28800,     // 8 horas
        'max_login_fails'  => 5,         // tentativas de senha antes de bloquear
        'lockout_time'     => 900,       // 15 minutos
    ],

    // ===== Idiomas disponíveis =====
    'languages' => [
        // Idioma padrão (usado quando nenhum outro é detectado)
        'default' => 'pt-br',

        // Idiomas ativos. A chave é o código (usada no .lang),
        // e o valor contém nome exibido e bandeira (emoji).
        'available' => [
            'pt-br' => ['name' => 'Português (Brasil)', 'flag' => '🇧🇷'],
            'en-us' => ['name' => 'English (US)',       'flag' => '🇺🇸'],
            'es-es' => ['name' => 'Español',            'flag' => '🇪🇸'],
        ],

        // Detectar automaticamente do navegador quando nenhuma
        // preferência estiver salva (session / user / cookie).
        'detect_browser' => true,
    ],
    // ===== Cloudflare Turnstile =====
    'turnstile' => [
        // Mude para false para desativar completamente o Turnstile
        'enabled'    => false,
        'site_key'   => 'sitekey',
        'secret_key' => 'secretkey',
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

        // =====================================================================
    //  ENVIO DE E-MAIL
    //  Escolha o driver: 'smtp' (PHPMailer) ou 'mailjet' (API v3.1)
    // =====================================================================
    'mail' => [
        // 'smtp' ou 'mailjet'
        'driver'     => 'smtp',

        // Remetente padrão (usado por ambos os drivers)
        'from_email' => 'no-reply@empresa.com.br',
        'from_name'  => 'PMG Dashboard',

        // ========== Driver SMTP (usado se driver = 'smtp') ==========
        'smtp' => [
            'host'       => 'smtp.empresa.com.br',
            'port'       => 587,
            'encryption' => 'tls',   // 'tls' ou 'ssl'
            'auth'       => true,
            'username'   => 'no-reply@empresa.com.br',
            'password'   => 'SenhaDoSMTP',
        ],

        // ========== Driver Mailjet (usado se driver = 'mailjet') ==========
        'mailjet' => [
            // Credenciais de https://app.mailjet.com/account/apikeys
            'api_key'    => 'SUA_API_KEY_MAILJET',
            'secret_key' => 'SUA_SECRET_KEY_MAILJET',

            // Sandbox não entrega e-mails (útil para testes).
            // Coloque false em produção.
            'sandbox'    => false,

            // Timeout HTTP em segundos
            'timeout'    => 15,

            // Endpoint da API (raramente precisa mudar)
            'endpoint'   => 'https://api.mailjet.com/v3.1/send',
        ],
    ],

    'security' => [
        'allowed_ips' => [],
    ],
];
