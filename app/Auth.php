<?php
declare(strict_types=1);
namespace App;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

final class Auth
{
    public static function boot(): void
    {
        // 首次启动时生成初始化口令并落库
        if (self::needsSetup() && (string)Db::setting('setup_token', '') === '') {
            $token = (string)cfg('setup_token', '') !== '' ? (string)cfg('setup_token', '') : random_token(8);
            Db::setSetting('setup_token', $token);
            app_log('初始化 token: ' . $token);
        }
    }

    public static function needsSetup(): bool
    {
        $stmt = Db::pdo()->query('SELECT COUNT(*) AS c FROM users');
        return (int)$stmt->fetch()['c'] === 0;
    }

    public static function setupToken(): string
    {
        return (string)Db::setting('setup_token', '');
    }

    public static function doSetup(string $token, string $username, string $password): array
    {
        if (!hash_equals(self::setupToken(), $token)) {
            return [false, '初始化口令不正确'];
        }
        $username = trim($username);
        if ($username === '' || mb_strlen($username) > 32) {
            return [false, '用户名不能为空且不超过 32 个字符'];
        }
        if (mb_strlen($password) < 6) {
            return [false, '密码长度至少 6 位'];
        }
        Db::pdo()->prepare(
            'INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$username, password_hash($password, PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]);
        Db::setSetting('setup_token', '');
        return [true, '初始化完成，请登录'];
    }

    public static function throttleKey(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }

    public static function attemptLogin(string $username, string $password): array
    {
        $ip = self::throttleKey();
        $cutoff = time() - 300;
        Db::pdo()->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([$cutoff]);
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND ts >= ?');
        $stmt->execute([$ip, $cutoff]);
        if ((int)$stmt->fetch()['c'] >= 5) {
            return [false, '尝试次数过多，请 5 分钟后再试'];
        }

        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $ok = $user !== false && password_verify($password, $user['password_hash']);
        if (!$ok) {
            Db::pdo()->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            return [false, '用户名或密码错误'];
        }
        // 登录成功后清掉该 IP 的历史失败记录
        Db::pdo()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        $_SESSION['uid'] = (int)$user['id'];
        session_regenerate_id(true);
        return [true, '登录成功'];
    }

    public static function check(): bool
    {
        return isset($_SESSION['uid']) && (int)$_SESSION['uid'] > 0;
    }

    public static function user(): ?array
    {
        if (!isset($_SESSION['uid'])) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['uid']]);
        $user = $stmt->fetch();
        return $user === false ? null : $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
