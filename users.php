<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (!auth_is_logged_in()) { header('Location: login.php'); exit; }
if (!auth_is_admin())     { header('Location: index.php'); exit; }

$cfg   = pmg_config();
$me    = auth_current_user();
$users = db_list_users();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$currentLang = lang_current();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('users.title')) ?> — <?= htmlspecialchars(t('app.title')) ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/dashboard.css">
<style>
.container { max-width:1200px; margin:0 auto; padding:24px; }
table { width:100%; border-collapse:separate; border-spacing:0;
    background:var(--glass-bg); backdrop-filter:var(--glass-blur);
    border:1px solid var(--glass-border); border-radius:16px;
    overflow:hidden; box-shadow:var(--shadow); }
thead th { padding:14px 18px; background:rgba(0,0,0,0.25); color:var(--text-muted);
    text-align:left; font-size:.8em; text-transform:uppercase; letter-spacing:.05em;
    border-bottom:1px solid var(--glass-border); }
tbody td { padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.06); font-size:.92em; }
tbody tr:last-child td { border-bottom:none; }
tbody tr:hover { background:rgba(255,255,255,0.03); }
.badge { display:inline-block; padding:3px 10px; border-radius:20px;
    font-size:.78em; font-weight:700; letter-spacing:.03em; }
.badge-admin { background:rgba(229,112,0,0.2); color:#E57000; border:1px solid rgba(229,112,0,0.4); }
.badge-operator { background:rgba(167,199,255,0.15); color:#A7C7FF; border:1px solid rgba(167,199,255,0.35); }
.badge-active { background:rgba(34,197,94,0.15); color:var(--ok); border:1px solid rgba(34,197,94,0.35); }
.badge-inactive { background:rgba(239,68,68,0.15); color:var(--bad); border:1px solid rgba(239,68,68,0.35); }
.badge-locked { background:rgba(245,158,11,0.15); color:var(--warn); border:1px solid rgba(245,158,11,0.35); }
.row-actions { display:flex; gap:8px; }
.row-actions a { display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:8px; background:rgba(255,255,255,0.06);
    border:1px solid var(--glass-border); color:var(--text); text-decoration:none;
    transition:background .2s ease; }
.row-actions a:hover { background:rgba(255,255,255,0.15); }
.row-actions a.danger:hover { background:rgba(239,68,68,0.25); color:#fca5a5; }
.flash { padding:14px 18px; margin-bottom:20px; border-radius:12px; font-size:.92em; }
.flash.ok { background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.4); color:#86efac; }
.flash.err { background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); color:#fca5a5; }
.empty { padding:60px 20px; text-align:center; color:var(--text-muted);
    background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:16px; }
.actions { display:flex; gap:10px; flex-wrap:wrap; }
.btn-secondary, .btn-primary { display:inline-flex; align-items:center; gap:6px;
    padding:8px 16px; border-radius:20px; text-decoration:none; font-size:.85em;
    font-weight:600; transition:background .2s ease; }
.btn-secondary { background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); color:var(--text); }
.btn-secondary:hover { background:rgba(255,255,255,0.12); }
.btn-primary { background:linear-gradient(135deg,#E57000,#C75E00); border:1px solid transparent;
    color:#fff; box-shadow:0 4px 14px rgba(229,112,0,0.4); }
.btn-primary:hover { box-shadow:0 8px 20px rgba(229,112,0,0.5); }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="brand">
            <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg" alt="Proxmox"
                 style="filter:brightness(0) saturate(100%) invert(48%) sepia(88%) saturate(1478%) hue-rotate(2deg) brightness(93%) contrast(101%);">
            <div class="brand-text">
                <h1><?= htmlspecialchars(t('users.title')) ?></h1>
                <span class="subtitle"><?= htmlspecialchars(t('app.title')) ?> · <?= htmlspecialchars(t('users.count', ['count' => count($users)])) ?></span>
            </div>
        </div>
        <div class="header-right">
            <?= lang_switcher_html($currentLang) ?>
            <div class="actions">
                <a href="index.php" class="btn-secondary"><?= htmlspecialchars(t('users.back')) ?></a>
                <a href="user_edit.php" class="btn-primary"><?= htmlspecialchars(t('users.new')) ?></a>
            </div>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($users)): ?>
        <div class="empty"><?= htmlspecialchars(t('users.empty')) ?></div>
    <?php else: ?>
        <table>
            <thead><tr>
                <th><?= htmlspecialchars(t('users.th.user')) ?></th>
                <th><?= htmlspecialchars(t('users.th.name')) ?></th>
                <th><?= htmlspecialchars(t('users.th.email')) ?></th>
                <th><?= htmlspecialchars(t('users.th.profile')) ?></th>
                <th><?= htmlspecialchars(t('users.th.status')) ?></th>
                <th><?= htmlspecialchars(t('users.th.last_login')) ?></th>
                <th style="width:100px"><?= htmlspecialchars(t('users.th.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'admin' : 'operator' ?>">
                        <?= htmlspecialchars(t($u['role'] === 'admin' ? 'users.badge.admin' : 'users.badge.operator')) ?>
                    </span></td>
                    <td>
                        <?php if (db_is_locked($u)): ?>
                            <span class="badge badge-locked">🔒 <?= htmlspecialchars(t('users.badge.locked')) ?></span>
                        <?php elseif ((int)$u['active']): ?>
                            <span class="badge badge-active">● <?= htmlspecialchars(t('users.badge.active')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-inactive">● <?= htmlspecialchars(t('users.badge.inactive')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['last_login']): ?>
                            <?= htmlspecialchars(date('d/m/Y H:i', strtotime($u['last_login']))) ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted)"><?= htmlspecialchars(t('users.never')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a href="user_edit.php?id=<?= (int)$u['id'] ?>" title="<?= htmlspecialchars(t('users.edit_tooltip')) ?>">✏️</a>
                            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                                <a href="user_delete.php?id=<?= (int)$u['id'] ?>" class="danger"
                                   title="<?= htmlspecialchars(t('users.delete_tooltip')) ?>"
                                   onclick="return confirm('<?= htmlspecialchars(t('users.delete_confirm', ['username' => $u['username']])) ?>');">🗑️</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
