<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$nav = [
    'dashboard' => ['总览', '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/>'],
    'instances' => ['实例', '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>'],
    'traffic' => ['流量', '<path d="M3 20h18M5 20v-6h4v6M10 20V8h4v12M15 20v-9h4v9"/>'],
    'tasks' => ['任务', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>'],
    'accounts' => ['账号', '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3l2 2-9.2 9.2"/>'],
    'events' => ['事件', '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'],
    'settings' => ['设置', '<path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="7" cy="17" r="2"/>'],
];
$page = $page ?? 'dashboard';
$pageTitle = $pageTitle ?? '总览';
$demo = has_demo_account();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(cfg('app_name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= e(cfg('version')) ?>">
    <?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body>
<header class="topbar">
    <div class="brand">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 2 2.5 7.5 12 13l9.5-5.5L12 2z"/>
                <path d="M2.5 12.5 12 18l9.5-5.5M2.5 17 12 22.5 21.5 17" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <div class="brand-text">
            <strong>OpsDeck</strong>
            <span>CDT 管理保活面板</span>
        </div>
    </div>
    <nav class="topnav">
        <?php foreach ($nav as $key => [$label, $icon]): ?>
            <a class="nav-link <?= $page === $key || ($key === 'instances' && in_array($page, ['instance', 'create'], true)) ? 'active' : '' ?>"
               href="<?= e(url('?page=' . $key)) ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="topbar-right">
        <span class="mode-pill <?= $demo ? 'demo' : '' ?>"><?= $demo ? '演示模式' : '在线模式' ?></span>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('?page=logout')) ?>">退出</a>
    </div>
</header>

<main class="main">
    <?php if ($flash = flash_pull()): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" data-flash>
            <?= e($flash['message']) ?>
            <button type="button" class="flash-close" data-flash-close>×</button>
        </div>
    <?php endif; ?>
    <?= $content ?? '' ?>
</main>

<footer class="statusbar">
    <span class="status-left"><i class="dot dot-ok"></i> 系统在线</span>
    <span class="status-right">
        <span id="clock-top"></span>
        <span class="sep">|</span>
        v<?= e(cfg('version')) ?> · PHP <?= e(PHP_VERSION) ?>
    </span>
</footer>

<div id="modal-root"></div>
<div id="toast-root"></div>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= e(cfg('version')) ?>"></script>
</body>
</html>
