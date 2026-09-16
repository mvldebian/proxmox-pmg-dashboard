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

    'smtp' => [
        'host'       => 'vps.suaempresa.com.br',
        'port'       => 587,
        'encryption' => 'tls',
        'auth'       => true,
        'username'   => 'alertas@suaempresa.com.br',
        'password'   => 'SenhaForte',
        'from_email' => 'alertas@suaempresa.com.br',
        'from_name'  => 'PMG Dashboard',
    ],

    'security' => [
        'allowed_ips' => [],
    ],
];
