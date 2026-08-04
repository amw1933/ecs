<?php
declare(strict_types=1);

// 禁止通过网址直接访问本文件（只允许被 public/index.php 或 cron.php 引入）
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

define('ROOT_PATH', dirname(__DIR__));

$GLOBALS['__config'] = require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
date_default_timezone_set((string)cfg('timezone'));

error_reporting(E_ALL);
ini_set('display_errors', is_prod() ? '0' : '1');
ini_set('log_errors', '1');

foreach (['storage', 'storage/logs', 'storage/cache'] as $dir) {
    $p = root_path($dir);
    if (!is_dir($p)) {
        @mkdir($p, 0755, true);
    }
}

set_exception_handler(function (Throwable $e): void {
    app_log('EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (is_cli()) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    $msg = is_prod() ? '服务器内部错误，请查看 storage/logs/app.log' : $e->getMessage();
    if (expects_json()) {
        json_out(['ok' => false, 'message' => $msg], 500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>500</title>'
        . '<body style="background:#0a0f1e;color:#e2e8f0;font-family:system-ui;display:grid;'
        . 'place-items:center;height:100vh;margin:0"><div style="text-align:center">'
        . '<h1 style="font-size:48px;margin:0 0 8px">500</h1>'
        . '<p style="color:#8ea0c2">' . e($msg) . '</p></div></body>';
    exit;
});

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $rel = str_replace('\\', '/', substr($class, 4));
        $file = __DIR__ . '/' . $rel . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!is_cli()) {
    session_name((string)cfg('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool)cfg('cookie_secure'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

\App\Db::init();
\App\Auth::boot();
