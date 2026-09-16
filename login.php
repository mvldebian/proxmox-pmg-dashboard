<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (auth_is_logged_in()) { header('Location: index.php'); exit; }

try {
    if (db_count_users() === 0) { header('Location: install.php'); exit; }
} catch (Throwable $e) { header('Location: install.php'); exit; }

$cfg   = pmg_config();
$error = '';

$sec = $cfg['security'] ?? [];
if (!empty($sec['allowed_ips']) && !in_array($_SERVER['REMOTE_ADDR'], $sec['allowed_ips'], true)) {
    http_response_code(403); exit('IP');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email   = trim($_POST['email'] ?? '');
    $cfToken = $_POST['cf-turnstile-response'] ?? '';

    if (auth_turnstile_enabled() && !auth_validate_turnstile($cfToken)) {
        $error = t('login.err.captcha');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t('login.err.email_invalid');
    } else {
        $user = db_find_user_by_email($email);

        if (!$user) {
            $error = t('login.err.email_unknown');
        } elseif ($user['role'] === 'admin') {
            $error = t('login.err.admin_redirect');
        } elseif (!(int)$user['active']) {
            $error = t('login.err.user_disabled');
        } elseif (db_is_locked($user)) {
            $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
            $error = t('login.err.locked', ['minutes' => $mins]);
        } elseif (auth_send_2fa_for_user((int)$user['id'])) {
            header('Location: verify_2fa.php');
            exit;
        } else {
            $error = t('login.err.smtp');
        }
    }
}

$turnstileOn = auth_turnstile_enabled();
$siteKey     = htmlspecialchars($cfg['turnstile']['site_key'] ?? '', ENT_QUOTES, 'UTF-8');
$currentLang = lang_current();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('login.title')) ?> — <?= htmlspecialchars(t('app.title')) ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/auth.css">
<?php if ($turnstileOn): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</head>
<body>
    <div class="card">
        <?= lang_switcher_html($currentLang) ? '' : '' ?>
        <div class="lang-switcher lang-switcher-top" role="group">
            <?php foreach (lang_available() as $code => $info): ?>
                <a class="lang-btn <?= $code === $currentLang ? 'active' : '' ?>"
                   href="set_lang.php?lang=<?= urlencode($code) ?>"
                   title="<?= htmlspecialchars($info['name']) ?>"><?= htmlspecialchars($info['flag']) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="brand">
            <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg" alt="Proxmox">
            <h1><?= htmlspecialchars(t('app.title')) ?></h1>
            <p><?= t('login.subtitle') ?></p>
        </div>

        <?php if (isset($_GET['expired'])): ?>
            <div class="error">⚠️ <?= htmlspecialchars(t('login.err.expired')) ?></div>
        <?php elseif (isset($_GET['blocked'])): ?>
            <div class="error">⚠️ <?= htmlspecialchars(t('login.err.blocked')) ?></div>
        <?php elseif ($error): ?>
            <div class="error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="email"><?= htmlspecialchars(t('login.email_label')) ?></label>
                <input type="email" id="email" name="email" required autofocus
                       autocomplete="email"
                       placeholder="<?= htmlspecialchars(t('login.email_placeholder')) ?>">
            </div>

            <?php if ($turnstileOn): ?>
                <div class="cf-turnstile" data-sitekey="<?= $siteKey ?>" data-theme="dark"></div>
            <?php endif; ?>

            <button type="submit" class="btn"><?= htmlspecialchars(t('login.submit')) ?></button>
        </form>

        <div class="brand-link">
            <a href="admin.php"><?= htmlspecialchars(t('login.admin_link')) ?></a>
        </div>
    </div>
</body>
</html>
