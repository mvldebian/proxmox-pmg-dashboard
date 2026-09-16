<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:20px'>";
echo "=== PMG Dashboard Diagnostic ===\n\n";

$cfg = pmg_config();
echo "PMG host: {$cfg['pmg']['host']}\n";
echo "PMG node: {$cfg['pmg']['node']}\n";
echo "PMG user: {$cfg['pmg']['user']}\n\n";

echo "[1] MySQL\n";
try {
    db_migrate();
    echo "    ✅ Connected. Users: " . db_count_users()
       . " (admins: " . db_count_admins() . ")\n";
} catch (Throwable $e) {
    echo "    ❌ " . $e->getMessage() . "\n";
}
echo "\n";

echo "[2] PMG authentication\n";
$auth = pmg_login();
if (!$auth) {
    echo "    ❌ Failed\n</pre>"; exit;
}
echo "    ✅ Ticket OK: {$auth['username']}\n\n";

echo "[3] Nodes\n";
$nodes = pmg_get('/nodes');
if (is_array($nodes)) {
    foreach ($nodes as $n) echo "    → '{$n['node']}' ({$n['status']})\n";
}
echo "\n";

echo "[4] Periods\n";
foreach (PMG_PERIODS as $p) {
    $path = pmg_mail_stats_path($p);
    $data = pmg_get($path);
    echo "  " . str_pad($p, 10) . " → ";
    if ($data) {
        printf("count_in=%d spam=%d spf=%d rbl=%d\n",
            $data['count_in'] ?? 0, $data['spamcount_in'] ?? 0,
            $data['spfcount'] ?? 0, $data['rbl_rejects'] ?? 0);
    } else echo "❌\n";
}
echo "\n";

echo "[5] System info\n";
$si = pmg_collect_system_info();
echo "    hostname:    {$si['hostname']}\n";
echo "    public_ip:   {$si['public_ip']}\n";
echo "    port {$si['port_ext']}: " . ($si['port_ext_open'] ? '✅ open' : '❌ closed') . "\n";
echo "    port {$si['port_int']}: " . ($si['port_int_open'] ? '✅ open' : '❌ closed') . "\n\n";

echo "[6] SMTP test\n";
$ok = auth_send_2fa_email($cfg['smtp']['from_email'], '000000', 'Test');
echo "    " . ($ok ? '✅ sent' : '❌ failed') . "\n\n";

echo "[7] Turnstile\n";
echo "    enabled: " . (auth_turnstile_enabled() ? 'YES' : 'NO') . "\n\n";

echo "[8] Languages\n";
echo "    current: " . lang_current() . "\n";
foreach (lang_available() as $code => $info) {
    echo "    → {$code} ({$info['name']})\n";
}
echo "\n=== END ===\n</pre>";
