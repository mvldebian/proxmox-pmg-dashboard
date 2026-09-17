<?php
require_once __DIR__ . '/functions.php';
auth_start_session();

if (!auth_is_logged_in()) { header('Location: login.php'); exit; }
if (!auth_is_admin())     { header('Location: index.php'); exit; }

$cfg   = pmg_config();
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$me    = auth_current_user();
$isNew = $id === 0;
$user  = $isNew ? [
    'username' => '', 'name' => '', 'email' => '', 'role' => 'operator', 'active' => 1,
    'language' => null,
] : db_find_user_by_id($id);

if (!$user) {
    $_SESSION['flash'] = ['type' => 'err', 'msg' => 'User not found.'];
    header('Location: users.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = ($_POST['role'] ?? 'operator') === 'admin' ? 'admin' : 'operator';
    $active   = isset($_POST['active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $language = trim($_POST['language'] ?? '');

    if ($username === '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $username = strstr($email, '@', true);
    }

    $availableLangs = array_keys(lang_available());
    if ($language !== '' && !in_array($language, $availableLangs, true)) $language = null;
    if ($language === '') $language = null;

    if (strlen($username) < 3) {
        $error = t('edit.err.username_short');
    } elseif (strlen($name) < 2) {
        $error = t('edit.err.name_required');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t('edit.err.email_invalid');
    } elseif (!$isNew && !$active && $user['role'] === 'admin' && db_count_admins() <= 1) {
        $error = t('edit.err.last_admin');
    } elseif ($role === 'admin' && $isNew && strlen($password) < 8) {
        $error = t('edit.err.password_short_admin');
    } elseif ($role === 'admin' && $password !== '' && strlen($password) < 8) {
        $error = t('edit.err.password_short');
    } elseif ($password !== '' && $password !== $confirm) {
        $error = t('edit.err.password_mismatch');
    } else {
        $existing = db_find_user($username);
        if ($existing && (int)$existing['id'] !== $id) {
            $error = t('edit.err.username_taken');
        } else {
            try {
                if ($isNew) {
                    $pwd = ($role === 'admin') ? $password : null;
                    db_create_user($username, $name, $email, $pwd, $role, (bool)$active, $language);
                    $_SESSION['flash'] = ['type' => 'ok', 'msg' => "OK: {$username}"];
                } else {
                    $fields = [
                        'username' => $username, 'name' => $name, 'email' => $email,
                        'role' => $role, 'language' => $language, 'active' => $active,
                    ];
                    if ($role === 'admin' && $password !== '') $fields['password'] = $password;
                    elseif ($role === 'operator' && $user['role'] === 'admin') $fields['password'] = '';
                    db_update_user($id, $fields);
                    $_SESSION['flash'] = ['type' => 'ok', 'msg' => "OK: {$username}"];
                }
                header('Location: users.php'); exit;
            } catch (Throwable $e) {
                $error = t('edit.err.save', ['error' => $e->getMessage()]);
            }
        }
    }

    $user = array_merge($user, [
        'username' => $username, 'name' => $name, 'email' => $email,
        'role' => $role, 'language' => $language, 'active' => $active,
    ]);
}

$currentLang    = lang_current();
$availableLangs = lang_available();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(t($isNew ? 'edit.title.new' : 'edit.title.edit')) ?> — <?= htmlspecialchars(t('app.title')) ?></title>
<link rel="icon" href="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg">
<link rel="stylesheet" href="assets/dashboard.css">
<style>
.container { max-width:680px; margin:0 auto; padding:24px; }
.card { padding:28px; }
.form-group { margin-bottom:18px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
label { display:block; margin-bottom:6px; color:var(--text-muted); font-size:.85em;
    text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
label .hint { color:var(--text-muted); font-weight:400; text-transform:none;
    letter-spacing:0; font-size:.9em; margin-left:6px; }
input[type=text], input[type=email], input[type=password], select {
    width:100%; padding:12px 16px; background:rgba(0,0,0,0.35);
    border:1px solid var(--glass-border); border-radius:10px;
    color:var(--text); font-size:1em; font-family:inherit; }
input:focus, select:focus { outline:none; border-color:rgba(229,112,0,0.6);
    box-shadow:0 0 0 3px rgba(229,112,0,0.15); }
select { cursor:pointer; }
select option { background:#1e293b; color:#e2e8f0; }
.checkbox-group { display:flex; align-items:center; gap:10px; padding:12px 16px;
    background:rgba(0,0,0,0.25); border:1px solid var(--glass-border); border-radius:10px; }
.checkbox-group input[type=checkbox] { width:18px; height:18px; accent-color:#E57000; }
.radio-group { display:flex; gap:10px; }
.radio-option { flex:1; padding:12px 16px; background:rgba(0,0,0,0.25);
    border:1px solid var(--glass-border); border-radius:10px; cursor:pointer;
    transition:border-color .2s ease, background .2s ease; }
.radio-option:hover { border-color:rgba(255,255,255,0.25); }
.radio-option input { margin-right:8px; accent-color:#E57000; }
.radio-option.selected { border-color:rgba(229,112,0,0.6); background:rgba(229,112,0,0.08); }
.error { padding:12px 16px; margin-bottom:20px; background:rgba(239,68,68,0.15);
    border:1px solid rgba(239,68,68,0.4); border-radius:10px; color:#fca5a5; font-size:.9em; }
.info { padding:12px 16px; margin-bottom:20px; background:rgba(167,199,255,0.08);
    border:1px solid rgba(167,199,255,0.3); border-radius:10px; color:#A7C7FF; font-size:.88em; }
.section-title { margin:24px 0 14px; padding-top:20px;
    border-top:1px solid rgba(255,255,255,0.08); color:var(--text-muted); font-size:.8em;
    text-transform:uppercase; letter-spacing:.08em; font-weight:600; }
.admin-only { display:none; }
.admin-only.visible { display:block; }
.btn-primary { width:100%; padding:13px; margin-top:8px;
    background:linear-gradient(135deg,#E57000,#C75E00); border:none; border-radius:10px;
    color:#fff; font-weight:700; font-size:1em; cursor:pointer;
    box-shadow:0 6px 16px rgba(229,112,0,0.35); }
.btn-secondary { display:inline-flex; padding:8px 16px; border-radius:20px;
    background:rgba(255,255,255,0.06); border:1px solid var(--glass-border);
    color:var(--text); text-decoration:none; font-size:.85em; }
.btn-secondary:hover { background:rgba(255,255,255,0.12); }
@media (max-width: 500px) { .form-row { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="brand">
            <img src="https://cdn.jsdelivr.net/npm/simple-icons@latest/icons/proxmox.svg" alt="Proxmox"
                 style="filter:brightness(0) saturate(100%) invert(48%) sepia(88%) saturate(1478%) hue-rotate(2deg) brightness(93%) contrast(101%);">
            <div class="brand-text">
                <h1><?= htmlspecialchars(t($isNew ? 'edit.title.new' : 'edit.title.edit')) ?></h1>
            </div>
        </div>
        <div class="header-right">
            <?= lang_switcher_html($currentLang) ?>
            <a href="users.php" class="btn-secondary"><?= htmlspecialchars(t('edit.back')) ?></a>
        </div>
    </header>

    <div class="card">
        <?php if ($error): ?>
            <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="info">💡 <?= htmlspecialchars(t('edit.info')) ?></div>

        <form method="POST" id="userForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="name"><?= htmlspecialchars(t('edit.name')) ?></label>
                    <input type="text" id="name" name="name" required minlength="2"
                           value="<?= htmlspecialchars($user['name']) ?>"
                           <?= $isNew ? 'autofocus' : '' ?>>
                </div>
                <div class="form-group">
                    <label for="email"><?= htmlspecialchars(t('edit.email')) ?>
                        <span class="hint"><?= htmlspecialchars(t('edit.email_hint')) ?></span>
                    </label>
                    <input type="email" id="email" name="email" required
                           value="<?= htmlspecialchars($user['email']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="username"><?= htmlspecialchars(t('edit.username')) ?>
                    <span class="hint"><?= htmlspecialchars(t('edit.username_hint')) ?></span>
                </label>
                <input type="text" id="username" name="username" minlength="3"
                       value="<?= htmlspecialchars($user['username']) ?>">
            </div>

            <div class="form-group">
                <label><?= htmlspecialchars(t('edit.profile')) ?></label>
                <div class="radio-group">
                    <label class="radio-option <?= $user['role'] === 'operator' ? 'selected' : '' ?>">
                        <input type="radio" name="role" value="operator"
                               <?= $user['role'] === 'operator' ? 'checked' : '' ?>>
                        <?= htmlspecialchars(t('edit.profile.operator')) ?>
                    </label>
                    <label class="radio-option <?= $user['role'] === 'admin' ? 'selected' : '' ?>">
                        <input type="radio" name="role" value="admin"
                               <?= $user['role'] === 'admin' ? 'checked' : '' ?>>
                        <?= htmlspecialchars(t('edit.profile.admin')) ?>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="language"><?= htmlspecialchars(t('edit.language')) ?>
                    <span class="hint"><?= htmlspecialchars(t('edit.language_hint')) ?></span>
                </label>
                <select id="language" name="language">
                    <option value="">— (<?= htmlspecialchars(t('lang.select')) ?>) —</option>
                    <?php foreach ($availableLangs as $code => $info): ?>
                        <option value="<?= htmlspecialchars($code) ?>"
                            <?= ($user['language'] ?? '') === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($info['flag'] . ' ' . $info['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="active" name="active"
                           <?= (int)$user['active'] ? 'checked' : '' ?>>
                    <label for="active" style="margin:0;text-transform:none;letter-spacing:0">
                        <?= htmlspecialchars(t('edit.active')) ?>
                    </label>
                </div>
            </div>

            <div id="passwordSection" class="admin-only <?= $user['role'] === 'admin' ? 'visible' : '' ?>">
                <div class="section-title">
                    <?= htmlspecialchars(t($isNew ? 'edit.password.new' : 'edit.password.change')) ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><?= htmlspecialchars(t('edit.password')) ?>
                            <?= $isNew ? '' : '<span class="hint">' . htmlspecialchars(t('edit.password_optional')) . '</span>' ?>
                        </label>
                        <input type="password" id="password" name="password" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="confirm"><?= htmlspecialchars(t('edit.confirm')) ?></label>
                        <input type="password" id="confirm" name="confirm" autocomplete="new-password">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary">
                <?= htmlspecialchars(t($isNew ? 'edit.submit.new' : 'edit.submit.save')) ?>
            </button>
        </form>
    </div>
</div>

<script>
const roleRadios = document.querySelectorAll('input[name="role"]');
const passwordSection = document.getElementById('passwordSection');
const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm');
const isNew = <?= $isNew ? 'true' : 'false' ?>;

function syncRoleUI() {
    const role = document.querySelector('input[name="role"]:checked').value;
    document.querySelectorAll('.radio-option').forEach(el => el.classList.remove('selected'));
    document.querySelector('input[name="role"]:checked').closest('.radio-option').classList.add('selected');
    if (role === 'admin') {
        passwordSection.classList.add('visible');
        if (isNew) { passwordInput.required = true; confirmInput.required = true; }
    } else {
        passwordSection.classList.remove('visible');
        passwordInput.required = false;
        confirmInput.required = false;
    }
}
roleRadios.forEach(r => r.addEventListener('change', syncRoleUI));
syncRoleUI();
</script>
</body>
</html>
