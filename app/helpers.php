<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/* ---------------- 基础路径 ---------------- */

function root_path(string $path = ''): string
{
    return ROOT_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function storage_path(string $path = ''): string
{
    return root_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function cfg(string $key, $default = null)
{
    return $GLOBALS['__config'][$key] ?? $default;
}

function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (!is_cli() && $script !== '' && str_ends_with($script, '/index.php')) {
            // 自动识别子目录部署，例如 /ecs/public/ 下运行 → /ecs/public
            $base = rtrim(dirname($script), '/\\');
        } else {
            $base = rtrim((string)cfg('base_path', ''), '/');
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function is_cli(): bool
{
    return PHP_SAPI === 'cli';
}

function is_prod(): bool
{
    return !cfg('debug', true);
}

function expects_json(): bool
{
    if (is_cli()) {
        return false;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return str_contains($accept, 'application/json')
        || ($_POST['_json'] ?? '') === '1'
        || ($_GET['_json'] ?? '') === '1';
}

/* ---------------- 输出 ---------------- */

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = [], string $message = 'ok'): void
{
    json_out(['ok' => true, 'message' => $message, 'data' => $data]);
}

function json_error(string $message, int $code = 400): void
{
    json_out(['ok' => false, 'message' => $message], $code);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/* ---------------- 输入 ---------------- */

function query(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function input(string $key, $default = null)
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function post_int(string $key, int $default = 0): int
{
    $v = filter_var(post($key), FILTER_VALIDATE_INT);
    return $v === false ? $default : (int)$v;
}

/* ---------------- 会话与 CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = post('_token');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        json_error('安全令牌已失效，请刷新页面后重试', 419);
    }
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_pull(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/* ---------------- 数据库设置 ---------------- */

function setting(string $key, $default = null)
{
    return Db::setting($key, $default);
}

function set_setting(string $key, $value): void
{
    Db::setSetting($key, $value);
}

/* ---------------- 日志与事件 ---------------- */

function app_log(string $message, string $level = 'INFO'): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . PHP_EOL;
    @file_put_contents(storage_path('logs/app.log'), $line, FILE_APPEND | LOCK_EX);
}

function log_event(
    string $kind,
    string $level,
    string $title,
    string $body = '',
    ?int $accountId = null,
    ?string $instanceId = null
): void {
    Db::pdo()->prepare(
        'INSERT INTO events (ts, kind, level, title, body, account_id, instance_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([date('Y-m-d H:i:s'), $kind, $level, $title, $body, $accountId, $instanceId]);
}

/* ---------------- 格式化 ---------------- */

function fmt_bytes($bytes, int $decimals = 2): string
{
    $bytes = (float)$bytes;
    if ($bytes < 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i === 0 ? 0 : $decimals) . ' ' . $units[$i];
}

function fmt_gb($bytes, int $decimals = 2): string
{
    return number_format((float)$bytes / 1073741824, $decimals);
}

function fmt_pct(float $used, float $total, int $decimals = 1): string
{
    if ($total <= 0) {
        return '0%';
    }
    return number_format($used / $total * 100, $decimals) . '%';
}

function random_token(int $length = 16): string
{
    return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
}

function status_label(string $status): string
{
    $map = [
        'Running'  => '运行中',
        'Stopped'  => '已停止',
        'Starting' => '启动中',
        'Stopping' => '停止中',
        'Pending'  => '创建中',
        'Released' => '已释放',
        'Expired'  => '已过期',
        'Deleted'  => '已删除',
    ];
    return $map[$status] ?? $status;
}

function status_class(string $status): string
{
    $map = [
        'Running'  => 'ok',
        'Stopped'  => 'muted',
        'Starting' => 'warn',
        'Stopping' => 'warn',
        'Pending'  => 'warn',
        'Released' => 'bad',
        'Expired'  => 'bad',
        'Deleted'  => 'bad',
    ];
    return $map[$status] ?? 'muted';
}

function has_demo_account(): bool
{
    $stmt = \App\Db::pdo()->query('SELECT COUNT(*) AS c FROM accounts WHERE is_demo = 1');
    return (int)$stmt->fetch()['c'] > 0;
}

/* ---------------- 视图 ---------------- */

function render_page(string $template, array $data = [], string $layout = 'layout'): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require root_path('app/Views/' . $template . '.php');
    $content = ob_get_clean();
    require root_path('app/Views/' . $layout . '.php');
}
