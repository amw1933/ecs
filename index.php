<?php
declare(strict_types=1);

/**
 * OpsDeck ECS 面板 — 根目录前端控制器
 *
 * 支持“整站拷贝到新服务器、目录即 Web 根”的零配置部署：
 *   - 打开站点根即可访问，无需把 Web 根指向 public/
 *   - /assets/... 自动映射到 public/assets/...（CSS / JS / 图片）
 *   - 其余请求全部交给 public/index.php 处理，原有 /public/ 入口仍可用
 */

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$base = rtrim(dirname($script), '/');
// 异常 SCRIPT_NAME（例如 CLI 内置服务器传入绝对路径）：回退为站点根
if ($base !== '' && !str_starts_with($base, '/')) {
    $base = '';
}
// SCRIPT_NAME 不是入口脚本（部分内置服务器会传请求路径）：按站点根处理
if (basename($script) !== 'index.php') {
    $base = '';
}

/* 静态资源：{base}/assets/xxx -> public/assets/xxx */
$prefix = $base . '/assets/';
if (str_starts_with($uriPath, $prefix)) {
    $rel = substr($uriPath, strlen($prefix));
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
        http_response_code(403);
        exit('Forbidden');
    }
    $file = __DIR__ . '/public/assets/' . $rel;
    if (!is_file($file)) {
        http_response_code(404);
        exit('Not Found');
    }
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
    ];
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
    exit;
}

/* 其余请求交给原有前端控制器 */
require __DIR__ . '/public/index.php';
