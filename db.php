<?php
require_once __DIR__ . '/config.php';

/**
 * Conexão única com o MySQL.
 * Roda a migração automaticamente uma vez por request.
 */
function db(): PDO
{
    static $pdo      = null;
    static $migrated = false;

    if ($pdo instanceof PDO && $migrated) {
        return $pdo;
    }

    if (!($pdo instanceof PDO)) {
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
    }

    if (!$migrated) {
        $migrated = true;   // marca ANTES para evitar recursão infinita
        try {
            db_migrate();
        } catch (Throwable $e) {
            error_log('[DB-MIGRATE] ' . $e->getMessage());
        }
    }

    return $pdo;
}

/**
 * Cria/atualiza o schema do banco. Idempotente.
 */
function db_migrate(): void
{
    // ===== Tabelas =====
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

    db()->exec("
        CREATE TABLE IF NOT EXISTS incidents (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            uniq_key     VARCHAR(128) NOT NULL,
            type         ENUM('service_down','high_cpu','high_memory') NOT NULL,
            severity     ENUM('warning','critical') NOT NULL DEFAULT 'warning',
            subject      VARCHAR(255) NOT NULL,
            detail       TEXT,
            started_at   DATETIME NOT NULL,
            resolved_at  DATETIME NULL,
            is_active    TINYINT(1) NOT NULL DEFAULT 1,
            notified_at  DATETIME NULL,
            resolved_notified TINYINT(1) NOT NULL DEFAULT 0,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_active (is_active),
            KEY idx_key_active (uniq_key, is_active),
            KEY idx_started (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    db()->exec("
        CREATE TABLE IF NOT EXISTS system_state (
            k          VARCHAR(64) PRIMARY KEY,
            v          TEXT,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ===== Migrações incrementais (só rodam 1x por versão) =====
    $schemaVersion = '3';
    $stored        = null;
    try { $stored = system_state_get('schema_version', ''); } catch (Throwable $e) {}

    if ($stored === $schemaVersion) return;

    $migrations = [
        "ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL",
        "ALTER TABLE users ADD COLUMN language VARCHAR(10) DEFAULT NULL AFTER role",
        "ALTER TABLE incidents ADD COLUMN resolved_notified TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($migrations as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* já aplicado */ }
    }

    try { system_state_set('schema_version', $schemaVersion); } catch (Throwable $e) {}
}

/* ==================== USERS ==================== */

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

/* ==================== ESTADO DO SISTEMA ==================== */

function system_state_get(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM system_state WHERE k = ? LIMIT 1');
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? (string)$row['v'] : $default;
}

function system_state_set(string $key, ?string $value): void
{
    $st = db()->prepare('
        INSERT INTO system_state (k, v) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v)
    ');
    $st->execute([$key, $value]);
}

/* ==================== INCIDENTES ==================== */

function db_find_incident_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM incidents WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function db_incident_find_active(string $uniqKey): ?array
{
    $st = db()->prepare('SELECT * FROM incidents WHERE uniq_key = ? AND is_active = 1 LIMIT 1');
    $st->execute([$uniqKey]);
    return $st->fetch() ?: null;
}

function db_incident_open(string $uniqKey, string $type, string $severity, string $subject, string $detail): int
{
    $st = db()->prepare('
        INSERT INTO incidents (uniq_key, type, severity, subject, detail, started_at, is_active)
        VALUES (?, ?, ?, ?, ?, NOW(), 1)
    ');
    $st->execute([$uniqKey, $type, $severity, $subject, $detail]);
    return (int)db()->lastInsertId();
}

function db_incident_mark_notified(int $id): void
{
    db()->prepare('UPDATE incidents SET notified_at = NOW() WHERE id = ?')->execute([$id]);
}

function db_incident_mark_resolved_notified(int $id): void
{
    db()->prepare('UPDATE incidents SET resolved_notified = 1 WHERE id = ?')->execute([$id]);
}

/**
 * Marca o incidente como resolvido.
 * Retorna o array completo com resolved_at preenchido, ou null se não havia ativo.
 */
function db_incident_resolve(string $uniqKey): ?array
{
    $inc = db_incident_find_active($uniqKey);
    if (!$inc) return null;

    db()->prepare('UPDATE incidents SET is_active = 0, resolved_at = NOW() WHERE id = ?')
        ->execute([(int)$inc['id']]);

    $inc['is_active']   = 0;
    $inc['resolved_at'] = date('Y-m-d H:i:s');
    return $inc;
}

/**
 * Resolve manualmente por ID. Retorna o incidente ou null.
 */
function db_incident_resolve_by_id(int $id): ?array
{
    $inc = db_find_incident_by_id($id);
    if (!$inc || !(int)$inc['is_active']) return null;

    db()->prepare('UPDATE incidents SET is_active = 0, resolved_at = NOW() WHERE id = ?')
        ->execute([$id]);

    $inc['is_active']   = 0;
    $inc['resolved_at'] = date('Y-m-d H:i:s');
    return $inc;
}

function db_incident_list(int $limit = 100, string $filter = 'all'): array
{
    $where = '';
    if ($filter === 'active')        $where = 'WHERE is_active = 1';
    elseif ($filter === 'resolved')  $where = 'WHERE is_active = 0';

    $st = db()->prepare("
        SELECT * FROM incidents
        $where
        ORDER BY is_active DESC, started_at DESC
        LIMIT " . (int)$limit
    );
    $st->execute();
    return $st->fetchAll();
}

function db_incident_count_active(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM incidents WHERE is_active = 1')->fetchColumn();
}
