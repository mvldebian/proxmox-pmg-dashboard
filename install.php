<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

$error = ''; $success = false; $dbReady = true;

try {
    db_migrate();
    if (db_count_users() > 0) {
        die('<h1 style="font-family:sans-serif">⚠️ Instalação já realizada</h1>
             <p style="font-family:sans-serif">Já existem usuários cadastrados.
             Delete o arquivo <code>install.php</code>.</p>');
    }
} catch (Throwable $e) {
    $dbReady = false;
    $error = 'Falha na conexão com o MySQL: ' . htmlspecialchars($e->getMessage());
}

if ($dbReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (strlen($username) < 3)              $error = 'Usuário deve ter ao menos 3 caracteres.';
    elseif (strlen($name) < 2)              $error = 'Nome completo é obrigatório.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'E-mail inválido.';
    elseif (strlen($password) < 8)          $error = 'A senha deve ter ao menos 8 caracteres.';
    elseif ($password !== $confirm)         $error = 'As senhas não coincidem.';
    elseif (db_find_user($username))        $error = 'Este usuário já existe.';
    elseif (db_find_user_by_email($email))  $error = 'Este e-mail já está cadastrado.';
    else {
        try { db_create_user($username, $name, $email, $password, 'admin', true); $success = true; }
        catch (Throwable $e) { $error = 'Erro: ' . $e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalação — PMG Dashboard</title>
<style>
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
        padding:20px; font-family:system-ui, sans-serif; color:#e2e8f0;
        background:
            radial-gradient(ellipse at 20% 0%, rgba(229,112,0,0.22), transparent 55%),
            radial-gradient(ellipse at 80% 100%, rgba(167,199,255,0.15), transparent 55%),
            linear-gradient(135deg, #0a0e1a 0%, #111827 50%, #0a0e1a 100%); }
    .card { width:100%; max-width:480px; padding:36px 32px;
        background:rgba(30,41,59,0.55); backdrop-filter:blur(20px);
        border:1px solid rgba(255,255,255,0.15); border-radius:20px;
        box-shadow:0 24px 60px rgba(0,0,0,0.5); }
    h1 { margin:0 0 6px; font-size:1.5em; color:#E57000; }
    p.sub { margin:0 0 24px; color:#94a3b8; font-size:.9em; }
    .form-group { margin-bottom:16px; }
    label { display:block; margin-bottom:6px; color:#94a3b8; font-size:.85em;
        text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    input { width:100%; padding:12px 16px; background:rgba(0,0,0,0.35);
        border:1px solid rgba(255,255,255,0.15); border-radius:10px;
        color:#e2e8f0; font-size:1em; }
    input:focus { outline:none; border-color:rgba(229,112,0,0.6);
        box-shadow:0 0 0 3px rgba(229,112,0,0.15); }
    .btn { width:100%; padding:13px; margin-top:8px;
        background:linear-gradient(135deg, #E57000 0%, #C75E00 100%);
        color:#fff; border:none; border-radius:10px; font-size:1em;
        font-weight:700; cursor:pointer; box-shadow:0 8px 20px rgba(229,112,0,0.35); }
    .btn:hover { transform:translateY(-2px); }
    .error, .success, .info { padding:12px 16px; margin-bottom:18px;
        border-radius:10px; font-size:.9em; }
    .error { background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); color:#fca5a5; }
    .success { background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.4); color:#86efac; }
    .info { background:rgba(167,199,255,0.1); border:1px solid rgba(167,199,255,0.3); color:#A7C7FF; font-size:.85em; }
    a { color:#E57000; }
    code { background:rgba(0,0,0,0.35); padding:2px 6px; border-radius:4px; font-size:.9em; }
</style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <h1>✅ Instalação concluída</h1>
            <p class="sub">O usuário administrador foi criado com sucesso.</p>
            <div class="success">
                <strong>Importante:</strong> delete o arquivo <code>install.php</code> agora
                por questões de segurança.
            </div>
            <p style="text-align:center;margin-top:24px">
                <a href="admin.php" style="font-weight:600">Ir para o painel administrativo →</a>
            </p>
        <?php elseif (!$dbReady): ?>
            <h1>⚠️ Erro de configuração</h1>
            <div class="error"><?= $error ?></div>
            <div class="info">
                Verifique em <code>config.php</code> a seção <code>database</code>.<br><br>
                <code>CREATE DATABASE pmg_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</code><br>
                <code>CREATE USER 'pmg_dashboard'@'localhost' IDENTIFIED BY 'SenhaDoBanco';</code><br>
                <code>GRANT ALL PRIVILEGES ON pmg_dashboard.* TO 'pmg_dashboard'@'localhost';</code><br>
                <code>FLUSH PRIVILEGES;</code>
            </div>
        <?php else: ?>
            <h1>🚀 Instalação inicial</h1>
            <p class="sub">Crie o primeiro usuário administrador do dashboard.</p>

            <?php if ($error): ?>
                <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="username">Usuário de acesso</label>
                    <input type="text" id="username" name="username" required minlength="3" autofocus>
                </div>
                <div class="form-group">
                    <label for="name">Nome completo</label>
                    <input type="text" id="name" name="name" required minlength="2">
                </div>
                <div class="form-group">
                    <label for="email">E-mail (para 2FA)</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha (mín. 8 caracteres)</label>
                    <input type="password" id="password" name="password" required minlength="8">
                </div>
                <div class="form-group">
                    <label for="confirm">Confirmar senha</label>
                    <input type="password" id="confirm" name="confirm" required minlength="8">
                </div>
                <button type="submit" class="btn">🔐 Criar administrador</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
