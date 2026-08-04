<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$pageTitle = $pageTitle ?? 'OpsDeck';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(cfg('app_name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= e(cfg('version')) ?>">
</head>
<body class="standalone">
<div class="auth-wrap">
    <div class="auth-brand">
        <span class="brand-mark">
            <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M12 2 2.5 7.5 12 13l9.5-5.5L12 2z"/>
                <path d="M2.5 12.5 12 18l9.5-5.5M2.5 17 12 22.5 21.5 17" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <div>
            <h1>OpsDeck</h1>
            <p>ECS 运维面板</p>
        </div>
    </div>
    <?php if ($flash = flash_pull()): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" data-flash>
            <?= e($flash['message']) ?>
            <button type="button" class="flash-close" data-flash-close>×</button>
        </div>
    <?php endif; ?>
    <div class="auth-card">
        <?= $content ?? '' ?>
    </div>
    <p class="auth-foot">纯 PHP 实现 · SQLite 存储 · 无第三方依赖</p>
</div>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= e(cfg('version')) ?>"></script>
</body>
</html>
