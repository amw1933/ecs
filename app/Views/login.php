<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
?>
<h2 class="auth-title">登录控制台</h2>
<form method="post" action="<?= e(url('?page=login&action=login')) ?>" class="form" data-ajax>
    <?= csrf_field() ?>
    <label class="field">
        <span class="field-label">用户名</span>
        <input type="text" name="username" class="input" autocomplete="username" required autofocus>
    </label>
    <label class="field">
        <span class="field-label">密码</span>
        <input type="password" name="password" class="input" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn btn-primary btn-block">登 录</button>
</form>
