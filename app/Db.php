<?php
declare(strict_types=1);
namespace App;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dbPath = (string)cfg('db_path');
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::migrate();
        }
        return self::$pdo;
    }

    public static function init(): void
    {
        self::pdo();
    }

    private static function migrate(): void
    {
        $check = self::$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'settings'");
        $hasSettings = $check->fetch() !== false;
        $version = 0;
        if ($hasSettings) {
            $row = self::$pdo->query("SELECT value FROM settings WHERE key = 'schema_version'")->fetch();
            $version = (int)($row['value'] ?? 0);
        }
        $schema = [
            1 => <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);
CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    access_key_id TEXT NOT NULL DEFAULT '',
    access_key_secret_enc TEXT NOT NULL DEFAULT '',
    region TEXT NOT NULL DEFAULT 'cn-hangzhou',
    quota_gb REAL NOT NULL DEFAULT 200,
    enabled INTEGER NOT NULL DEFAULT 1,
    is_demo INTEGER NOT NULL DEFAULT 0,
    note TEXT NOT NULL DEFAULT '',
    last_test_at TEXT,
    last_test_ok INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS instances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    instance_id TEXT NOT NULL,
    region_id TEXT NOT NULL DEFAULT '',
    instance_name TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT '',
    instance_type TEXT NOT NULL DEFAULT '',
    cpu INTEGER NOT NULL DEFAULT 0,
    memory_mb INTEGER NOT NULL DEFAULT 0,
    public_ip TEXT NOT NULL DEFAULT '',
    private_ip TEXT NOT NULL DEFAULT '',
    eip TEXT NOT NULL DEFAULT '',
    charge_type TEXT NOT NULL DEFAULT '',
    spot_strategy TEXT NOT NULL DEFAULT '',
    expired_at TEXT,
    created_time TEXT,
    image_id TEXT NOT NULL DEFAULT '',
    os_name TEXT NOT NULL DEFAULT '',
    tags_json TEXT NOT NULL DEFAULT '{}',
    traffic_limit_gb REAL,
    auto_shutdown INTEGER NOT NULL DEFAULT 1,
    recipe_json TEXT,
    updated_at TEXT NOT NULL,
    UNIQUE (account_id, instance_id)
);
CREATE INDEX IF NOT EXISTS idx_instances_account ON instances(account_id);
CREATE TABLE IF NOT EXISTS traffic_daily (
    account_id INTEGER NOT NULL,
    instance_id TEXT NOT NULL,
    day TEXT NOT NULL,
    in_bytes INTEGER NOT NULL DEFAULT 0,
    out_bytes INTEGER NOT NULL DEFAULT 0,
    total_bytes INTEGER NOT NULL DEFAULT 0,
    src TEXT NOT NULL DEFAULT 'cms',
    PRIMARY KEY (account_id, instance_id, day)
);
CREATE INDEX IF NOT EXISTS idx_traffic_day ON traffic_daily(day);
CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    kind TEXT NOT NULL,
    account_id INTEGER,
    instance_id TEXT,
    cron_expr TEXT NOT NULL DEFAULT '* * * * *',
    enabled INTEGER NOT NULL DEFAULT 1,
    last_run_at TEXT,
    last_result TEXT NOT NULL DEFAULT '',
    next_run_at TEXT,
    payload_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'system',
    level TEXT NOT NULL DEFAULT 'info',
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    account_id INTEGER,
    instance_id TEXT
);
CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
CREATE TABLE IF NOT EXISTS notify_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts TEXT NOT NULL,
    channel TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL,
    ok INTEGER NOT NULL,
    message TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    ts INTEGER NOT NULL
);
SQL,
            2 => <<<'SQL'
ALTER TABLE instances ADD COLUMN auto_power_on_monthly INTEGER NOT NULL DEFAULT 0;
SQL,
            3 => <<<'SQL'
CREATE TABLE IF NOT EXISTS spot_instances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    instance_id TEXT NOT NULL,
    instance_name TEXT NOT NULL DEFAULT '',
    recipe_json TEXT NOT NULL DEFAULT '{}',
    last_seen_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (account_id, instance_id)
);
SQL,
        ];

        foreach ($schema as $v => $sql) {
            if ($version < $v) {
                try {
                    self::$pdo->exec($sql);
                    self::setSetting('schema_version', (string)$v);
                } catch (\PDOException $e) {
                    // 列已存在等兼容情况：忽略并继续
                    if (str_contains($e->getMessage(), 'duplicate column')) {
                        self::setSetting('schema_version', (string)$v);
                    } else {
                        throw $e;
                    }
                }
            }
        }
    }

    public static function setting(string $key, $default = null)
    {
        $stmt = self::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row === false ? $default : $row['value'];
    }

    public static function setSetting(string $key, $value): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, (string)$value]);
    }
}
