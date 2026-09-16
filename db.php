<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $db  = $cfg['database'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'], (int)($db['port'] ?? 3306),
        $db['database'], $db['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    } catch (PDOException $e) {
        error_log('[DB] ' . $e->getMessage());
        throw new RuntimeException('Não foi possível conectar ao banco de dados.');
    }
    return $pdo;
}

function db_migrate(): void
{
    db()->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(64)  NOT NULL,
            name          VARCHAR(128) NOT NULL,
            email         VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NULL,
            role          ENUM('admin','operator') NOT NULL DEFAULT 'operator',
            language      VARCHAR(10)  NULL,
            active        TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login    DATETIME     NULL,
            failed_logins INT          NOT NULL DEFAULT 0,
            locked_until  DATETIME     NULL,
            UNIQUE KEY uniq_username (username),
            UNIQUE KEY uniq_email (email),
            KEY idx_role_active (role, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        "ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN language VARCHAR(10) DEFAULT NULL AFTER role",
    ] as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) {}
    }
}

function db_find_user(string $username): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    return $st->fetch() ?: null;
}

function db_find_user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function db_find_user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

function db_list_users(): array
{
    return db()->query('SELECT * FROM users ORDER BY role DESC, username')->fetchAll();
}

function db_count_users(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function db_count_admins(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
}

function db_create_user(string $username, string $name, string $email, ?string $password, string $role = 'operator', bool $active = true, ?string $language = null): int
{
    $hash = ($password !== null && $password !== '')
        ? password_hash($password, PASSWORD_DEFAULT)
        : null;

    $st = db()->prepare('
        INSERT INTO users (username, name, email, password_hash, role, language, active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $st->execute([$username, $name, $email, $hash, $role, $language, $active ? 1 : 0]);
    return (int)db()->lastInsertId();
}

function db_update_user(int $id, array $fields): void
{
    $allowed = ['username', 'name', 'email', 'role', 'language', 'active'];
    $sets = []; $params = [];
    foreach ($fields as $k => $v) {
        if (in_array($k, $allowed, true)) { $sets[] = "$k = ?"; $params[] = $v; }
    }

    if (array_key_exists('password', $fields)) {
        if ($fields['password'] === '') {
            $sets[] = 'password_hash = NULL';
        } elseif ($fields['password'] !== null) {
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($fields['password'], PASSWORD_DEFAULT);
        }
    }

    if (empty($sets)) return;
    $params[] = $id;
    db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
}

function db_update_user_language(int $id, string $language): void
{
    db()->prepare('UPDATE users SET language = ? WHERE id = ?')->execute([$language, $id]);
}

function db_delete_user(int $id): void
{
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

function db_update_login_success(int $id): void
{
    db()->prepare("UPDATE users SET last_login=NOW(), failed_logins=0, locked_until=NULL WHERE id=?")
        ->execute([$id]);
}

function db_update_login_fail(int $id, int $maxFails, int $lockoutSeconds): void
{
    $u = db_find_user_by_id($id);
    if (!$u) return;
    $fails  = (int)$u['failed_logins'] + 1;
    $locked = $fails >= $maxFails ? date('Y-m-d H:i:s', time() + $lockoutSeconds) : null;
    db()->prepare('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?')
        ->execute([$fails, $locked, $id]);
}

function db_is_locked(array $user): bool
{
    if (empty($user['locked_until'])) return false;
    return strtotime($user['locked_until']) > time();
}
