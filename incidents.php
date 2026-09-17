<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (!auth_is_logged_in()) { header('Location: login.php'); exit; }

// Resolução manual
if (!empty($_GET['resolve'])) {
    $id  = (int)$_GET['resolve'];
    $res = db_incident_resolve_by_id($id);
    if ($res) {
        // Notifica admins sobre a resolução manual
        incident_notify_admins_resolved($res);
        db_incident_mark_resolved_notified($id);
    }
    header('Location: incidents.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

$cfg         = pmg_config();
$me          = auth_current_user();
$isAdmin     = ($me['role'] ?? '') === 'admin';
$currentLang = lang_current();

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'active', 'resolved'], true)) $filter = 'all';

$incidents   = db_incident_list(200, $filter);
$countActive = db_incident_count_active();

function incident_duration(array $inc): string
{
    $start = strtotime($inc['started_at']);
    $end   = $inc['resolved_at'] ? strtotime($inc['resolved_at']) : time();
    $sec   = max(0, $end - $start);
    $d = floor($sec / 86400);
    $h = floor(($sec % 86400) / 3600);
    $m = floor(($sec % 3600) / 60);
    if ($d > 0) return sprintf('%dd %dh %dm', $d, $h, $m);
    if ($h > 0) return sprintf('%dh %dm', $h, $m);
    return sprintf('%dm', $m);
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('inc.title')) ?> — <?= htmlspecialchars(t('app.title')) ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/dashboard.css">
<style>
.container { max-width: 1400px; margin: 0 auto; padding: 24px; }
header { display:flex; align-items:center; justify-content:space-between;
    padding:10px 16px; background:var(--glass-bg); backdrop-filter:var(--glass-blur);
    border:1px solid var(--glass-border); border-radius:14px; box-shadow:var(--shadow),var(--glow);
    flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.brand { display:flex; align-items:center; gap:12px; }
.brand img { height:36px; width:36px; filter:drop-shadow(0 0 10px rgba(229,112,0,0.6)); }
.brand h1 { margin:0; font-size:1.15em; font-weight:700;
    background:linear-gradient(135deg, #fff 0%, var(--proxmox-orange) 100%);
    -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
.brand .subtitle { font-size:.72em; color:var(--text-muted); display:block; }
.header-right { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.filter-tabs { display:inline-flex; gap:3px; padding:3px;
    background:rgba(0,0,0,0.35); border:1px solid var(--glass-border); border-radius:12px; }
.filter-tabs a { padding:6px 14px; border-radius:9px; color:var(--text-muted);
    text-decoration:none; font-size:.85em; font-weight:600; transition:background .2s ease; }
.filter-tabs a:hover { background:rgba(255,255,255,0.06); color:var(--text); }
.filter-tabs a.active { background:linear-gradient(135deg,#E57000,#C75E00); color:#fff;
    box-shadow:0 3px 10px rgba(229,112,0,0.4); }
.filter-tabs .count-pill { display:inline-block; margin-left:4px; padding:1px 6px;
    background:rgba(239,68,68,0.25); color:#fca5a5; border-radius:10px; font-size:.85em; }
.inc-table { width:100%; border-collapse:separate; border-spacing:0;
    background:var(--glass-bg); backdrop-filter:var(--glass-blur);
    border:1px solid var(--glass-border); border-radius:16px;
    overflow:hidden; box-shadow:var(--shadow); margin-top:20px; }
.inc-table thead th { padding:14px 16px; background:rgba(0,0,0,0.25);
    color:var(--text-muted); text-align:left; font-size:.75em;
    text-transform:uppercase; letter-spacing:.06em;
    border-bottom:1px solid var(--glass-border); }
.inc-table tbody td { padding:12px 16px;
    border-bottom:1px solid rgba(255,255,255,0.05); font-size:.9em; vertical-align:top; }
.inc-table tbody tr:last-child td { border-bottom:none; }
.inc-table tbody tr:hover { background:rgba(255,255,255,0.03); }
.inc-table tr.active-row { background:rgba(239,68,68,0.05); }
.inc-id { color:var(--text-muted); font-weight:700; font-family:ui-monospace,monospace; }
.inc-detail { color:var(--text-muted); font-size:.85em; line-height:1.4; margin-top:4px; }
.sev-badge { display:inline-block; padding:2px 10px; border-radius:12px;
    font-size:.75em; font-weight:700; letter-spacing:.04em; }
.sev-critical { background:rgba(239,68,68,0.2); color:#fca5a5; border:1px solid rgba(239,68,68,0.4); }
.sev-warning  { background:rgba(245,158,11,0.2); color:#fcd34d; border:1px solid rgba(245,158,11,0.4); }
.state-badge { display:inline-flex; align-items:center; gap:6px;
    padding:3px 10px; border-radius:12px; font-size:.75em; font-weight:700; }
.state-active   { background:rgba(239,68,68,0.15); color:#fca5a5; border:1px solid rgba(239,68,68,0.35); }
.state-resolved { background:rgba(34,197,94,0.15); color:#86efac; border:1px solid rgba(34,197,94,0.35); }
.state-active .dot { width:8px; height:8px; border-radius:50%;
    background:currentColor; box-shadow:0 0 8px currentColor; animation:pulse 1s infinite; }
.btn-resolve { display:inline-flex; padding:4px 12px; border-radius:12px;
    background:rgba(255,255,255,0.06); border:1px solid var(--glass-border);
    color:var(--text); text-decoration:none; font-size:.8em; font-weight:600;
    transition:background .2s ease; }
.btn-resolve:hover { background:rgba(34,197,94,0.15); border-color:rgba(34,197,94,0.4); color:#86efac; }
.empty-state { padding:60px 20px; text-align:center; color:var(--text-muted);
    background:var(--glass-bg); border:1px solid var(--glass-border);
    border-radius:16px; margin-top:20px; }
.empty-state .big-icon { font-size:3em; margin-bottom:12px; opacity:.5; }
.btn-secondary { display:inline-flex; align-items:center; gap:5px;
    padding:6px 12px; background:rgba(229,112,0,0.15);
    border:1px solid rgba(229,112,0,0.35); border-radius:20px;
    color:var(--proxmox-orange); text-decoration:none;
    font-size:.78em; font-weight:600; }
.btn-secondary:hover { background:rgba(229,112,0,0.25); }
@media (max-width: 900px) { .inc-table { display:block; overflow-x:auto; } }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="brand">
            <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg" alt="Proxmox">
            <div>
                <h1>🚨 <?= htmlspecialchars(t('inc.title')) ?></h1>
                <span class="subtitle"><?= htmlspecialchars(t('inc.subtitle')) ?></span>
            </div>
        </div>
        <div class="header-right">
            <?= lang_switcher_html($currentLang) ?>
            <div class="filter-tabs">
                <a href="?filter=active" class="<?= $filter === 'active' ? 'active' : '' ?>">
                    <?= htmlspecialchars(t('inc.filter.active')) ?>
                    <?php if ($countActive > 0): ?>
                        <span class="count-pill"><?= (int)$countActive ?></span>
                    <?php endif; ?>
                </a>
                <a href="?filter=all" class="<?= $filter === 'all' ? 'active' : '' ?>">
                    <?= htmlspecialchars(t('inc.filter.all')) ?>
                </a>
                <a href="?filter=resolved" class="<?= $filter === 'resolved' ? 'active' : '' ?>">
                    <?= htmlspecialchars(t('inc.filter.resolved')) ?>
                </a>
            </div>
            <a href="index.php" class="btn-secondary"><?= htmlspecialchars(t('inc.back')) ?></a>
        </div>
    </header>

    <?php if (empty($incidents)): ?>
        <div class="empty-state">
            <div class="big-icon">✨</div>
            <p><?= htmlspecialchars(t('inc.empty')) ?></p>
        </div>
    <?php else: ?>
        <table class="inc-table">
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th style="width:150px"><?= htmlspecialchars(t('inc.th.started')) ?></th>
                    <th style="width:100px"><?= htmlspecialchars(t('inc.th.duration')) ?></th>
                    <th style="width:110px"><?= htmlspecialchars(t('inc.th.severity')) ?></th>
                    <th><?= htmlspecialchars(t('inc.th.subject')) ?></th>
                    <th style="width:110px"><?= htmlspecialchars(t('inc.th.status')) ?></th>
                    <th style="width:110px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($incidents as $inc): ?>
                <tr class="<?= (int)$inc['is_active'] ? 'active-row' : '' ?>">
                    <td class="inc-id">#<?= (int)$inc['id'] ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($inc['started_at']))) ?></td>
                    <td><?= htmlspecialchars(incident_duration($inc)) ?></td>
                    <td><span class="sev-badge sev-<?= htmlspecialchars($inc['severity']) ?>">
                        <?= strtoupper(htmlspecialchars($inc['severity'])) ?>
                    </span></td>
                    <td>
                        <strong><?= htmlspecialchars($inc['subject']) ?></strong>
                        <div class="inc-detail"><?= nl2br(htmlspecialchars((string)$inc['detail'])) ?></div>
                    </td>
                    <td>
                        <?php if ((int)$inc['is_active']): ?>
                            <span class="state-badge state-active">
                                <span class="dot"></span><?= htmlspecialchars(t('inc.state.active')) ?>
                            </span>
                        <?php else: ?>
                            <span class="state-badge state-resolved">
                                <?= htmlspecialchars(t('inc.state.resolved')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$inc['is_active']): ?>
                            <a href="?resolve=<?= (int)$inc['id'] ?>&filter=<?= urlencode($filter) ?>"
                               class="btn-resolve"
                               onclick="return confirm('<?= htmlspecialchars(t('inc.confirm_resolve')) ?>');">
                                ✓ <?= htmlspecialchars(t('inc.resolve')) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
