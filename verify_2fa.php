<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (empty($_SESSION['2fa_code']) || empty($_SESSION['2fa_user_id'])) {
    header('Location: login.php');
    exit;
}

$cfg = pmg_config();

if (time() > ($_SESSION['2fa_expires'] ?? 0)) {
    unset($_SESSION['2fa_code'], $_SESSION['2fa_expires'],
          $_SESSION['2fa_attempts'], $_SESSION['2fa_user_id']);
    header('Location: login.php?expired=1');
    exit;
}

$user = db_find_user_by_id((int)$_SESSION['2fa_user_id']);
if (!$user) { header('Location: login.php'); exit; }

$isAdminFlow = ($user['role'] === 'admin');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;

    if ($_SESSION['2fa_attempts'] > (int)($cfg['auth']['max_attempts'] ?? 5)) {
        unset($_SESSION['2fa_code'], $_SESSION['2fa_expires'],
              $_SESSION['2fa_attempts'], $_SESSION['2fa_user_id']);
        $redir = $isAdminFlow ? 'admin.php' : 'login.php';
        header('Location: ' . $redir . '?blocked=1');
        exit;
    }

    if (!preg_match('/^\d{6}$/', $code)) {
        $error = t('twofa.err.format');
    } elseif (password_verify($code, $_SESSION['2fa_code'])) {
        auth_finish_login($user);
        header('Location: index.php');
        exit;
    } else {
        $remaining = max(0, (int)($cfg['auth']['max_attempts'] ?? 5) - $_SESSION['2fa_attempts']);
        $error = t('twofa.err.wrong', ['attempts' => $remaining]);
    }
}

$emailMask = preg_replace('/(.{2}).+(@.+)/', '$1***$2', $user['email']);
$remaining = max(0, ($_SESSION['2fa_expires'] ?? 0) - time());
$backUrl   = $isAdminFlow ? 'admin.php' : 'login.php';
$currentLang = lang_current();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t('twofa.title')) ?> — <?= htmlspecialchars(t('app.title')) ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body>
    <div class="card" style="text-align:center">
        <div class="lang-switcher lang-switcher-top" role="group">
            <?php foreach (lang_available() as $code => $info): ?>
                <a class="lang-btn <?= $code === $currentLang ? 'active' : '' ?>"
                   href="set_lang.php?lang=<?= urlencode($code) ?>"
                   title="<?= htmlspecialchars($info['name']) ?>"><?= htmlspecialchars($info['flag']) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="icon-2fa">📧</div>
        <h1 style="margin:0 0 8px;background:linear-gradient(135deg,#fff 0%,var(--proxmox-orange) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent">
            <?= htmlspecialchars(t('twofa.hello', ['name' => $user['name']])) ?>
        </h1>
        <p style="color:var(--text-muted);font-size:.9em;margin-bottom:24px">
            <?= htmlspecialchars(t('twofa.sent')) ?><br>
            <strong style="color:var(--text)"><?= htmlspecialchars($emailMask) ?></strong>
        </p>

        <?php if ($error): ?>
            <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="verify_2fa.php">
            <div class="form-group">
                <label for="code"><?= htmlspecialchars(t('twofa.code_label')) ?></label>
                <input type="text" id="code" name="code" class="code-input"
                       maxlength="6" pattern="\d{6}" inputmode="numeric"
                       autocomplete="one-time-code" autofocus required placeholder="000000">
            </div>
            <button type="submit" class="btn"><?= htmlspecialchars(t('twofa.submit')) ?></button>
        </form>

        <div class="timer"><?= htmlspecialchars(t('twofa.expires')) ?> <strong id="timer"><?= (int)$remaining ?>s</strong></div>
        <a href="<?= $backUrl ?>" class="back-link"><?= htmlspecialchars(t('twofa.back')) ?></a>
    </div>

    <script>
        let remaining = <?= (int)$remaining ?>;
        const el = document.getElementById('timer');
        const iv = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(iv);
                window.location.href = '<?= $backUrl ?>?expired=1';
                return;
            }
            el.textContent = remaining + 's';
        }, 1000);
    </script>
</body>
</html>
