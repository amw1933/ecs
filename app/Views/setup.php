<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
?>
<h2 class="auth-title">首次初始化</h2>
<p class="auth-desc">首次部署需要设置管理员密码。初始化口令已生成，请妥善保存。</p>
<div class="token-box" data-token><?= e($setupToken) ?></div>
<form method="post" action="<?= e(url('?page=setup&action=setup')) ?>" class="form" data-ajax>
    <?= csrf_field() ?>
    <label class="field">
        <span class="field-label">初始化口令</span>
        <input type="text" name="token" class="input" required>
    </label>
    <label class="field">
        <span class="field-label">管理员用户名</span>
        <input type="text" name="username" class="input" value="admin" required>
    </label>
    <label class="field">
        <span class="field-label">密码（至少 6 位）</span>
        <input type="password" name="password" class="input" minlength="6" autocomplete="new-password" required>
    </label>
    <button type="submit" class="btn btn-primary btn-block">完成初始化</button>
</form>

<?php
$checks = [];
$checks['PHP 版本'] = version_compare(PHP_VERSION, '8.1.0', '>=') ? [true, PHP_VERSION] : [false, PHP_VERSION];
foreach (['pdo_sqlite', 'openssl', 'curl', 'mbstring', 'json'] as $ext) {
    $checks[$ext . ' 扩展'] = extension_loaded($ext) ? [true, '已加载'] : [false, '未加载'];
}
$checks['storage 目录可写'] = is_writable(root_path('storage')) ? [true, '可写'] : [false, '不可写'];
$dbOk = false;
$dbMsg = '';
try {
    \App\Db::pdo()->query('SELECT 1');
    $dbOk = true;
    $dbMsg = '已连接 · schema v' . (string)setting('schema_version', '?');
} catch (\Throwable $e) {
    $dbMsg = $e->getMessage();
}
$checks['数据库连接'] = [$dbOk, $dbMsg];
?>
<div class="env-check">
    <h3>部署环境自检</h3>
    <ul>
        <?php foreach ($checks as $label => [$ok, $detail]): ?>
            <li class="<?= $ok ? 'ok' : 'bad' ?>">
                <span><?= $ok ? '✓' : '✗' ?></span>
                <?= e($label) ?>
                <em><?= e((string)$detail) ?></em>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="hint">站点根：<code><?= e(root_path()) ?></code> —— 直接把 Web 根指向本目录即可打开，无需 public/。</p>
</div>
