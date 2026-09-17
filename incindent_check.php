<?php
/**
 * Verificador de incidentes para uso com cron.
 *
 * Crontab:
 *   * * * * * /usr/bin/php /var/www/html/dashboard/incident_check.php >> /var/log/pmg_incidents.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/functions.php';

$ts     = date('Y-m-d H:i:s');
$result = incident_check_and_log();
$opened = $result['opened']   ?? [];
$closed = $result['resolved'] ?? [];

if (empty($opened) && empty($closed)) {
    echo "[{$ts}] Nenhuma mudança.\n";
    exit(0);
}

if (!empty($opened)) {
    echo "[{$ts}] " . count($opened) . " incidente(s) ABERTO(S):\n";
    foreach ($opened as $inc) {
        echo "  #{$inc['id']} [{$inc['severity']}] {$inc['subject']}\n";
    }
}
if (!empty($closed)) {
    echo "[{$ts}] " . count($closed) . " incidente(s) RESOLVIDO(S):\n";
    foreach ($closed as $inc) {
        echo "  #{$inc['id']} {$inc['subject']}\n";
    }
}
exit(0);
