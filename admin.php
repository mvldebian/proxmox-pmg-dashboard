<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (auth_is_logged_in()) {
    $u = auth_current_user();
    if ($u && $u['role'] === 'admin') { header('Location: index.php'); exit; }
    auth_logout();
}

try { if (db_count_users() === 0) { header('Location: install.php'); exit; } }
catch (Throwable $e) { header('Location: install.php'); exit; }

$cfg   = pmg_config();
$error = '';

$sec = $cfg['security'] ?? [];
if (!empty($sec['allowed_ips']) && !in_array($_SERVER['REMOTE_ADDR'], $sec['allowed_ips'], true)) {
    http_response_code(403); exit('IP');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $cfToken  = $_POST['cf-turnstile-response'] ?? '';

    if (auth_turnstile_enabled() && !auth_validate_turnstile($cfToken)) {
        $error = t('admin.err.captcha');
    } else {
        $user = db_find_user($username);
        if (!$user || $user['role'] !== 'admin') {
            $error = t('admin.err.invalid');
        } elseif (empty($user['password_hash'])) {
            $error = t('admin.err.no_password');
        } elseif (!(int)$user['active']) {
            $error = t('admin.err.disabled');
        } elseif (db_is_locked($user)) {
            $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
            $error = t('admin.err.locked', ['minutes' => $mins]);
        } elseif (!password_verify($password, $user['password_hash'])) {
            db_update_login_fail((int)$user['id'],
                (int)($cfg['auth']['max_login_fails'] ?? 5),
                (int)($cfg['auth']['lockout_time'] ?? 900));
            $error = t('admin.err.invalid');
        } elseif (auth_send_2fa_for_user((int)$user['id'])) {
            header('Location: verify_2fa.php');
            exit;
        } else {
            $error = t('admin.err.smtp');
        }
    }
}

$turnstileOn = auth_turnstile_enabled();
$siteKey     = htmlspecialchars($cfg['turnstile']['site_key'] ?? '');
$currentLang = lang_current();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('admin.title')) ?> — <?= htmlspecialchars(t('app.title')) ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/auth.css">
<?php if ($turnstileOn): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</head>
<body>
    <div class="card">
        <div class="lang-switcher lang-switcher-top" role="group">
            <?php foreach (lang_available() as $code => $info): ?>
                <a class="lang-btn <?= $code === $currentLang ? 'active' : '' ?>"
                   href="set_lang.php?lang=<?= urlencode($code) ?>"
                   title="<?= htmlspecialchars($info['name']) ?>"><?= htmlspecialchars($info['flag']) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="brand">
            <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg" alt="Proxmox">
            <h1><?= htmlspecialchars(t('admin.title')) ?></h1>
            <span class="shield"><?= htmlspecialchars(t('admin.badge')) ?></span>
        </div>

        <?php if (isset($_GET['expired'])): ?>
            <div class="error">⚠️ <?= htmlspecialchars(t('admin.err.expired')) ?></div>
        <?php elseif (isset($_GET['blocked'])): ?>
            <div class="error">⚠️ <?= htmlspecialchars(t('admin.err.blocked')) ?></div>
        <?php elseif ($error): ?>
            <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="admin.php">
            <div class="form-group">
                <label for="username"><?= htmlspecialchars(t('admin.username_label')) ?></label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password"><?= htmlspecialchars(t('admin.password_label')) ?></label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <?php if ($turnstileOn): ?>
                <div class="cf-turnstile" data-sitekey="<?= $siteKey ?>" data-theme="dark"></div>
            <?php endif; ?>
            <button type="submit" class="btn"><?= htmlspecialchars(t('admin.submit')) ?></button>
        </form>

        <div class="brand-link">
            <a href="login.php"><?= htmlspecialchars(t('admin.back')) ?></a>
        </div>
    </div>
</body>
</html>
