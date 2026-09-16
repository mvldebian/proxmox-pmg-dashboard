<?php
/**
 * Funções de acesso à API do PMG, autenticação, idioma e utilitários.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

const PMG_PERIODS    = ['day', 'month', 'semester', 'year'];
const PMG_TOP_LIMIT  = 15;
const PMG_HOURLY_TTL = 600;

function pmg_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

/* =====================================================================
 *  IDIOMA (i18n)
 * ===================================================================== */

/**
 * Retorna a lista de idiomas disponíveis.
 */
function lang_available(): array
{
    return pmg_config()['languages']['available'] ?? ['pt-br' => ['name' => 'Português', 'flag' => '🇧🇷']];
}

/**
 * Idioma padrão do config.
 */
function lang_default(): string
{
    $def = pmg_config()['languages']['default'] ?? 'pt-br';
    return array_key_exists($def, lang_available()) ? $def : array_key_first(lang_available());
}

/**
 * Faz o parse de um arquivo .lang (chave = valor).
 */
function lang_parse_file(string $path): array
{
    if (!is_file($path)) return [];

    $out   = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;

        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        // Interpreta sequências de escape básicas
        $val = str_replace(['\\n', '\\t'], ["\n", "\t"], $val);

        if ($key !== '') $out[$key] = $val;
    }
    return $out;
}

/**
 * Carrega as traduções de um idioma (com fallback para o padrão).
 */
function lang_load(string $code): array
{
    static $cache = [];

    if (isset($cache[$code])) return $cache[$code];

    $default = lang_default();
    $base    = ($code !== $default) ? lang_load($default) : [];

    $file    = __DIR__ . '/lang/' . basename($code) . '.lang';
    $specific = lang_parse_file($file);

    return $cache[$code] = array_merge($base, $specific);
}

/**
 * Detecta idioma pelo Accept-Language do navegador.
 */
function lang_detect_from_browser(array $available): ?string
{
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($accept === '') return null;

    $langs = [];
    foreach (explode(',', $accept) as $part) {
        $part = trim($part);
        if ($part === '') continue;

        $bits = explode(';', $part);
        $tag  = strtolower(trim($bits[0]));
        $q    = 1.0;
        if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) {
            $q = (float)$m[1];
        }
        $langs[$tag] = $q;
    }
    arsort($langs);

    foreach (array_keys($langs) as $tag) {
        $norm = str_replace('_', '-', $tag);

        // Match exato (pt-br)
        if (in_array($norm, $available, true)) return $norm;

        // Match por idioma (pt => pt-br)
        $lang = explode('-', $norm)[0];
        foreach ($available as $avail) {
            if ($avail === $lang || str_starts_with($avail, $lang . '-')) {
                return $avail;
            }
        }
    }
    return null;
}

/**
 * Retorna o idioma atual, com detecção em cascata:
 * sessão → usuário → cookie → navegador → padrão.
 */
function lang_current(): string
{
    static $current = null;
    if ($current !== null) return $current;

    auth_start_session();

    $available = array_keys(lang_available());

    // 1) Sessão
    if (!empty($_SESSION['lang']) && in_array($_SESSION['lang'], $available, true)) {
        return $current = $_SESSION['lang'];
    }

    // 2) Preferência do usuário (se logado)
    if (!empty($_SESSION['auth_user_id'])) {
        $user = db_find_user_by_id((int)$_SESSION['auth_user_id']);
        if ($user && !empty($user['language']) && in_array($user['language'], $available, true)) {
            $_SESSION['lang'] = $user['language'];
            return $current = $user['language'];
        }
    }

    // 3) Cookie
    if (!empty($_COOKIE['pmg_lang']) && in_array($_COOKIE['pmg_lang'], $available, true)) {
        return $current = $_COOKIE['pmg_lang'];
    }

    // 4) Navegador (opcional)
    if (!empty(pmg_config()['languages']['detect_browser'])) {
        $browser = lang_detect_from_browser($available);
        if ($browser) return $current = $browser;
    }

    // 5) Padrão
    return $current = lang_default();
}

/**
 * Define o idioma ativo (sessão + cookie + BD do usuário se logado).
 */
function lang_set(string $code, bool $persist_user = true): bool
{
    $available = array_keys(lang_available());
    if (!in_array($code, $available, true)) return false;

    auth_start_session();
    $_SESSION['lang'] = $code;

    // Cookie de 1 ano
    setcookie('pmg_lang', $code, [
        'expires'  => time() + 365 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    if ($persist_user && !empty($_SESSION['auth_user_id'])) {
        try {
            db_update_user_language((int)$_SESSION['auth_user_id'], $code);
        } catch (Throwable $e) {
            error_log('[LANG] Falha ao persistir idioma: ' . $e->getMessage());
        }
    }
    return true;
}

/**
 * Traduz uma chave para o idioma atual.
 * Substitui {variaveis} pelos valores passados.
 */
function t(string $key, array $vars = []): string
{
    global $__lang_cache;
    if ($__lang_cache === null) {
        $__lang_cache = lang_load(lang_current());
    }

    $text = $__lang_cache[$key] ?? $key;

    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

/* =====================================================================
 *  AUTENTICAÇÃO
 * ===================================================================== */

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $cfg = pmg_config();
    $lifetime = (int)($cfg['auth']['session_lifetime'] ?? 28800);

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_is_logged_in(): bool
{
    auth_start_session();
    if (empty($_SESSION['auth_ok'])) return false;
    if (empty($_SESSION['auth_expires'])) return false;
    if (time() > $_SESSION['auth_expires']) {
        auth_logout();
        return false;
    }
    return true;
}

function auth_current_user(): ?array
{
    auth_start_session();
    if (empty($_SESSION['auth_user_id'])) return null;
    return db_find_user_by_id((int)$_SESSION['auth_user_id']);
}

function auth_is_admin(): bool
{
    $u = auth_current_user();
    return $u && $u['role'] === 'admin';
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

function auth_turnstile_enabled(): bool
{
    $cfg = pmg_config()['turnstile'] ?? [];
    return !empty($cfg['enabled'])
        && !empty($cfg['site_key'])
        && !empty($cfg['secret_key']);
}

function auth_validate_turnstile(string $token): bool
{
    if (!auth_turnstile_enabled()) return true;

    $cfg    = pmg_config()['turnstile'];
    $secret = $cfg['secret_key'] ?? '';
    if ($secret === '' || $token === '') return false;

    $ch = curl_init($cfg['verify_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log('[Turnstile] Falha na validação: HTTP ' . $httpCode);
        return false;
    }

    $json = json_decode($response, true);
    return !empty($json['success']);
}

function auth_generate_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function auth_send_2fa_email(string $to, string $code, string $userName = ''): bool
{
    $cfg  = pmg_config();
    $smtp = $cfg['smtp'];
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host     = $smtp['host'];
        $mail->Port     = (int)$smtp['port'];
        $mail->SMTPAuth = !empty($smtp['auth']);
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];

        if (strtolower($smtp['encryption']) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to, $userName);

        $greeting = $userName !== ''
            ? 'Olá, <strong>' . htmlspecialchars($userName) . '</strong>!'
            : 'Olá!';

        $mail->isHTML(true);
        $mail->Subject = t('twofa.title') . ' — ' . t('app.title');
        $mail->Body    = '
            <div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;
                        padding:24px;background:#0f172a;color:#e2e8f0;border-radius:12px">
                <h2 style="color:#E57000;margin-top:0">🔐 ' . htmlspecialchars(t('twofa.code_label')) . '</h2>
                <p>' . $greeting . '</p>
                <div style="font-size:2.4em;font-weight:700;letter-spacing:.2em;
                            text-align:center;padding:20px;background:rgba(229,112,0,.15);
                            border:1px solid rgba(229,112,0,.4);border-radius:8px;
                            color:#E57000;margin:20px 0">' . htmlspecialchars($code) . '</div>
                <p style="color:#94a3b8;font-size:.9em">' . htmlspecialchars(t('twofa.expires')) . ' 5 min.</p>
            </div>';
        $mail->AltBody = t('twofa.code_label') . ": {$code}";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[2FA] Falha ao enviar e-mail: ' . $mail->ErrorInfo);
        return false;
    }
}

function auth_send_2fa_for_user(int $userId): bool
{
    $user = db_find_user_by_id($userId);
    if (!$user || !(int)$user['active']) return false;

    $cfg     = pmg_config();
    $code    = auth_generate_code();
    $expires = time() + (int)$cfg['auth']['code_ttl'];

    $_SESSION['2fa_code']     = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['2fa_expires']  = $expires;
    $_SESSION['2fa_attempts'] = 0;
    $_SESSION['2fa_user_id']  = $userId;

    return auth_send_2fa_email($user['email'], $code, $user['name']);
}

function auth_finish_login(array $user): void
{
    $cfg = pmg_config();

    session_regenerate_id(true);
    $_SESSION['auth_ok']      = true;
    $_SESSION['auth_user_id'] = (int)$user['id'];
    $_SESSION['auth_expires'] = time() + (int)($cfg['auth']['session_lifetime'] ?? 28800);
    $_SESSION['auth_via']     = $user['role'] === 'admin' ? 'admin' : 'email';

    // Aplica a preferência de idioma do usuário, se existir
    if (!empty($user['language']) && array_key_exists($user['language'], lang_available())) {
        $_SESSION['lang'] = $user['language'];
    }

    unset($_SESSION['2fa_code'], $_SESSION['2fa_expires'],
          $_SESSION['2fa_attempts'], $_SESSION['2fa_user_id']);

    db_update_login_success((int)$user['id']);
}

/* =====================================================================
 *  API DO PMG
 * ===================================================================== */

function pmg_ticket_cache(?array $new = null): ?array
{
    static $cache = null;
    if ($new !== null) { $cache = $new; }
    return $cache;
}

function pmg_login(): ?array
{
    $cached = pmg_ticket_cache();
    if ($cached !== null) { return $cached; }

    $cfg = pmg_config()['pmg'];
    $url = rtrim($cfg['host'], '/') . '/api2/json/access/ticket';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'username' => $cfg['user'],
            'password' => $cfg['password'],
        ]),
        CURLOPT_TIMEOUT        => $cfg['timeout'],
        CURLOPT_SSL_VERIFYPEER => $cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[PMG Ticket] cURL erro: {$curlErr}");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("[PMG Ticket] HTTP {$httpCode}: {$response}");
        return null;
    }

    $json = json_decode($response, true);
    if (empty($json['data']['ticket'])) {
        error_log("[PMG Ticket] Sem ticket: {$response}");
        return null;
    }

    $data = [
        'ticket'   => $json['data']['ticket'],
        'csrf'     => $json['data']['CSRFPreventionToken'] ?? '',
        'username' => $json['data']['username'] ?? '',
    ];

    pmg_ticket_cache($data);
    return $data;
}

function pmg_request(string $method, string $path, array $params = []): ?array
{
    $auth = pmg_login();
    if (!$auth) { return null; }

    $cfg    = pmg_config()['pmg'];
    $url    = rtrim($cfg['host'], '/') . '/api2/json' . $path;
    $method = strtoupper($method);

    $headers = [
        'Accept: application/json',
        'Cookie: PMGAuthCookie=' . $auth['ticket'],
    ];

    if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && !empty($auth['csrf'])) {
        $headers[] = 'CSRFPreventionToken: ' . $auth['csrf'];
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => $cfg['timeout'],
        CURLOPT_SSL_VERIFYPEER => $cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
        CURLOPT_HTTPHEADER     => $headers,
    ];

    if (!empty($params)) {
        if ($method === 'GET') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        } else {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    $opts[CURLOPT_URL] = $url;
    $ch = curl_init();
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[PMG {$method} {$path}] cURL erro: {$curlErr}");
        return null;
    }
    if ($httpCode >= 400) {
        error_log("[PMG {$method} {$path}] HTTP {$httpCode}: {$response}");
        return null;
    }

    $json = json_decode($response, true);
    return $json['data'] ?? null;
}

function pmg_get(string $path): ?array
{
    return pmg_request('GET', $path);
}

/* =====================================================================
 *  INFORMAÇÕES DO PMG
 * ===================================================================== */

function pmg_is_public_ip(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function pmg_dns_lookup_public(string $hostname): ?string
{
    if ($hostname === '') return null;
    if (filter_var($hostname, FILTER_VALIDATE_IP)) {
        return pmg_is_public_ip($hostname) ? $hostname : null;
    }
    $ip = gethostbyname($hostname);
    if ($ip !== $hostname && pmg_is_public_ip($ip)) return $ip;
    $records = @dns_get_record($hostname, DNS_A);
    if (is_array($records)) {
        foreach ($records as $r) {
            if (!empty($r['ip']) && pmg_is_public_ip($r['ip'])) return $r['ip'];
        }
    }
    return null;
}

function pmg_check_port(string $host, int $port, float $timeout = 2.0): bool
{
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) { fclose($fp); return true; }
    return false;
}

function pmg_collect_system_info(): array
{
    $cfg = pmg_config();
    $pmg = $cfg['pmg'];

    $status   = pmg_get("/nodes/{$pmg['node']}/status") ?? [];
    $hostname = $status['node'] ?? $pmg['node'];

    $publicIp = null;
    if (!empty($cfg['dashboard']['public_ip_override'])) {
        $publicIp = $cfg['dashboard']['public_ip_override'];
    }
    if (!$publicIp) $publicIp = pmg_dns_lookup_public($hostname);
    if (!$publicIp) {
        $pmgHost = parse_url($pmg['host'], PHP_URL_HOST) ?: '';
        if ($pmgHost && $pmgHost !== $hostname) {
            $publicIp = pmg_dns_lookup_public($pmgHost);
        }
    }

    $pmgHost = parse_url($pmg['host'], PHP_URL_HOST) ?: 'localhost';
    $portExt = (int)($cfg['dashboard']['relay_external_port'] ?? 25);
    $portInt = (int)($cfg['dashboard']['relay_internal_port'] ?? 26);

    return [
        'hostname'      => $hostname,
        'public_ip'     => $publicIp ?: 'n/a',
        'port_ext'      => $portExt,
        'port_int'      => $portInt,
        'port_ext_open' => pmg_check_port($pmgHost, $portExt, 2.0),
        'port_int_open' => pmg_check_port($pmgHost, $portInt, 2.0),
        'checked_at'    => date('c'),
    ];
}

/* =====================================================================
 *  PERÍODOS
 * ===================================================================== */

function pmg_validate_period(?string $period): string
{
    $cfg = pmg_config();
    $default = $cfg['dashboard']['stats_period'] ?? 'month';
    if (!in_array($default, PMG_PERIODS, true)) $default = 'month';
    if ($period === null || $period === '') return $default;
    return in_array($period, PMG_PERIODS, true) ? $period : $default;
}

function pmg_mail_stats_path(string $period): string
{
    switch ($period) {
        case 'day':
            return '/statistics/mail?year=' . date('Y')
                 . '&month=' . date('n')
                 . '&day=' . date('j');
        case 'semester':
            $end   = time();
            $start = strtotime('-6 months', $end);
            return '/statistics/mail?starttime=' . $start . '&endtime=' . $end;
        case 'year':
            return '/statistics/mail?year=' . date('Y');
        case 'month':
        default:
            return '/statistics/mail?year=' . date('Y') . '&month=' . date('n');
    }
}

/**
 * Retorna o rótulo TRADUZIDO do período.
 */
function pmg_period_label(string $period): string
{
    switch ($period) {
        case 'day':
            return t('dash.period.day.label', ['date' => date('d/m/Y')]);
        case 'semester':
            return t('dash.period.semester.label');
        case 'year':
            return t('dash.period.year.label', ['year' => date('Y')]);
        case 'month':
        default:
            return t('dash.period.month.label', ['date' => date('m/Y')]);
    }
}

/* =====================================================================
 *  GRÁFICO HORÁRIO
 * ===================================================================== */

function pmg_get_hourly_mail_stats(): array
{
    $cacheFile = sys_get_temp_dir() . '/pmg_dashboard_hourly_v2.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < PMG_HOURLY_TTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && count($cached) === 24) {
            return $cached;
        }
    }

    $result      = [];
    $now         = time();
    $currentHour = strtotime(date('Y-m-d H:00:00', $now));

    for ($i = 23; $i >= 0; $i--) {
        $hourStart = $currentHour - ($i * 3600);
        $hourEnd   = $hourStart + 3599;

        $stats = pmg_get('/statistics/mail?starttime=' . $hourStart . '&endtime=' . $hourEnd);

        $countIn   = (int)($stats['count_in']         ?? 0);
        $spamIn    = (int)($stats['spamcount_in']     ?? 0);
        $virusIn   = (int)($stats['viruscount_in']    ?? 0);
        $preReject = (int)($stats['pregreet_rejects'] ?? 0);

        $denom = $countIn + $spamIn + $preReject;
        if ($denom < 1) $denom = 1;

        $result[] = [
            'hour'          => date('H:00', $hourStart),
            'hour_ts'       => $hourStart,
            'count_in'      => $countIn,
            'spam_in'       => $spamIn,
            'virus_in'      => $virusIn,
            'pregreet'      => $preReject,
            'spam_rate'     => round($spamIn    / $denom * 100, 2),
            'pregreet_rate' => round($preReject / $denom * 100, 2),
        ];
    }

    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

/* =====================================================================
 *  TOP SENDERS / DOMÍNIOS
 * ===================================================================== */

function pmg_get_top_senders(string $period, int $limit = PMG_TOP_LIMIT): array
{
    $path = pmg_mail_stats_path($period);
    $senderPath = preg_replace('#^/statistics/mail#', '/statistics/sender', $path);

    $data = pmg_get($senderPath);
    if (!is_array($data)) return [];
    $rows = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $result[] = [
            'sender'     => (string)($row['sender'] ?? ''),
            'count'      => (int)($row['count'] ?? 0),
            'viruscount' => (int)($row['viruscount'] ?? 0),
            'bytes'      => (int)($row['bytes'] ?? 0),
        ];
    }
    usort($result, fn($a, $b) => $b['count'] <=> $a['count']);
    return array_slice($result, 0, $limit);
}

function pmg_get_top_domains(string $period, int $limit = PMG_TOP_LIMIT): array
{
    $path = pmg_mail_stats_path($period);
    $domainPath = preg_replace('#^/statistics/mail#', '/statistics/domains', $path);

    $data = pmg_get($domainPath);
    if (!is_array($data)) return [];
    $rows = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $result[] = [
            'domain'         => (string)($row['domain'] ?? ''),
            'count_in'       => (int)($row['count_in'] ?? 0),
            'count_out'      => (int)($row['count_out'] ?? 0),
            'spamcount_in'   => (int)($row['spamcount_in'] ?? 0),
            'spamcount_out'  => (int)($row['spamcount_out'] ?? 0),
            'viruscount_in'  => (int)($row['viruscount_in'] ?? 0),
            'viruscount_out' => (int)($row['viruscount_out'] ?? 0),
            'bytes_in'       => (int)($row['bytes_in'] ?? 0),
            'bytes_out'      => (int)($row['bytes_out'] ?? 0),
        ];
    }
    usort($result, fn($a, $b) => $b['count_in'] <=> $a['count_in']);
    return array_slice($result, 0, $limit);
}

/* =====================================================================
 *  MÉTRICAS
 * ===================================================================== */

function pmg_collect_metrics(?string $period = null): array
{
    $cfg    = pmg_config();
    $node   = $cfg['pmg']['node'];
    $period = pmg_validate_period($period);

    $status = pmg_get("/nodes/{$node}/status") ?? [];
    $mail   = pmg_get(pmg_mail_stats_path($period)) ?? [];

    $cpuPct = isset($status['cpu']) ? round($status['cpu'] * 100, 1) : 0;
    $mem    = $status['memory'] ?? ['used' => 0, 'total' => 1, 'free' => 0];
    $memPct = ($mem['total'] ?? 0) > 0 ? round($mem['used'] / $mem['total'] * 100, 1) : 0;
    $swap    = $status['swap'] ?? ['used' => 0, 'total' => 1, 'free' => 0];
    $swapPct = ($swap['total'] ?? 0) > 0 ? round($swap['used'] / $swap['total'] * 100, 1) : 0;
    $disk    = $status['rootfs'] ?? ['used' => 0, 'total' => 1, 'free' => 0];
    $diskPct = ($disk['total'] ?? 0) > 0 ? round($disk['used'] / $disk['total'] * 100, 1) : 0;

    $mailsIn  = (int)($mail['count_in']       ?? 0);
    $mailsOut = (int)($mail['count_out']      ?? 0);
    $spamIn   = (int)($mail['spamcount_in']   ?? 0);
    $spamOut  = (int)($mail['spamcount_out']  ?? 0);
    $virusIn  = (int)($mail['viruscount_in']  ?? 0);
    $virusOut = (int)($mail['viruscount_out'] ?? 0);

    $junkIn     = (int)($mail['junk_in']          ?? 0);
    $junkOut    = (int)($mail['junk_out']         ?? 0);
    $greylist   = (int)($mail['glcount']          ?? 0);
    $spfRejects = (int)($mail['spfcount']         ?? 0);
    $rblRejects = (int)($mail['rbl_rejects']      ?? 0);
    $preRejects = (int)($mail['pregreet_rejects'] ?? 0);
    $bytesIn    = (float)($mail['bytes_in']       ?? 0);
    $bytesOut   = (float)($mail['bytes_out']      ?? 0);
    $avptime    = (float)($mail['avptime']        ?? 0);

    $totalIn  = $mailsIn + $spamIn + $virusIn;
    $spamRate = $totalIn > 0 ? round(($spamIn + $virusIn) / $totalIn * 100, 2) : 0;

    return [
        'timestamp'    => date('c'),
        'period'       => $period,
        'period_label' => pmg_period_label($period),
        'cpu'          => (float)$cpuPct,
        'memory'       => ['pct' => (float)$memPct,  'used' => (int)($mem['used']  ?? 0), 'total' => (int)($mem['total']  ?? 0)],
        'swap'         => ['pct' => (float)$swapPct, 'used' => (int)($swap['used'] ?? 0), 'total' => (int)($swap['total'] ?? 0)],
        'disk'         => ['pct' => (float)$diskPct, 'used' => (int)($disk['used'] ?? 0), 'total' => (int)($disk['total'] ?? 0)],
        'uptime'       => (int)($status['uptime'] ?? 0),
        'loadavg'      => array_map('floatval', $status['loadavg'] ?? [0, 0, 0]),
        'mails'        => [
            'incoming'    => $mailsIn,
            'outgoing'    => $mailsOut,
            'spam_in'     => $spamIn,
            'spam_out'    => $spamOut,
            'virus_in'    => $virusIn,
            'virus_out'   => $virusOut,
            'junk_in'     => $junkIn,
            'junk_out'    => $junkOut,
            'greylist'    => $greylist,
            'spf_rejects' => $spfRejects,
            'rbl_rejects' => $rblRejects,
            'pre_rejects' => $preRejects,
            'spam_rate'   => (float)$spamRate,
            'avptime'     => (float)$avptime,
        ],
        'traffic'      => ['in' => (float)$bytesIn, 'out' => (float)$bytesOut],
        'top_senders'  => pmg_get_top_senders($period, PMG_TOP_LIMIT),
        'top_domains'  => pmg_get_top_domains($period, PMG_TOP_LIMIT),
        'hourly_mail'  => pmg_get_hourly_mail_stats(),
        'system_info'  => pmg_collect_system_info(),
    ];
}

function format_bytes(float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function format_uptime(int $seconds): string
{
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf('%dd %dh %dm', $d, $h, $m);
}

/* =====================================================================
 *  RENDERIZAÇÃO DE SELETOR DE IDIOMA
 * ===================================================================== */

/**
 * Renderiza o seletor de idioma (usado no header do dashboard/login).
 */
function lang_switcher_html(string $currentLang): string
{
    $available = lang_available();
    if (count($available) < 2) return '';

    $html  = '<div class="lang-switcher" role="group" aria-label="' . htmlspecialchars(t('lang.select')) . '">';
    foreach ($available as $code => $info) {
        $active = $code === $currentLang ? ' active' : '';
        $title  = htmlspecialchars($info['name']);
        $flag   = htmlspecialchars($info['flag']);
        $href   = 'set_lang.php?lang=' . urlencode($code);
        $html  .= '<a class="lang-btn' . $active . '" href="' . $href . '" title="' . $title . '" data-lang="' . htmlspecialchars($code) . '">' . $flag . '</a>';
    }
    $html .= '</div>';
    return $html;
}
