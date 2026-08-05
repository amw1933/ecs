<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$channels = array_filter(array_map('trim', explode(',', (string)setting('notify_channels', ''))));
$smtpPassSet = setting('smtp_pass_enc', '') !== '';
$accounts = \App\Services\EcsService::allAccounts();
?>
<section class="page-head">
    <div>
        <h2 class="page-title">系统设置</h2>
        <p class="page-sub">通用、账号独立配置与通知渠道</p>
    </div>
</section>

<div class="tabs" data-tabs>
    <button type="button" class="tab active" data-tab="general">通用</button>
    <button type="button" class="tab" data-tab="accounts">账号设置</button>
    <button type="button" class="tab" data-tab="notify">通知渠道</button>
    <button type="button" class="tab" data-tab="ops">维护</button>
</div>

<section class="tab-panel active" data-panel="general">
    <div class="panel">
        <div class="cell-head"><h3>通用设置</h3></div>
        <form class="form form-narrow" data-ajax-form="settings_save" data-url="<?= e(url('?page=api&action=settings_save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="general">
            <label class="field">
                <span class="field-label">时区</span>
                <select class="input" name="timezone">
                    <?php foreach (['Asia/Shanghai' => '亚洲/上海 (UTC+8)', 'Asia/Hong_Kong' => '亚洲/香港 (UTC+8)', 'Asia/Tokyo' => '亚洲/东京 (UTC+9)', 'UTC' => 'UTC'] as $tz => $tzLabel): ?>
                        <option value="<?= e($tz) ?>" <?= setting('timezone', 'Asia/Shanghai') === $tz ? 'selected' : '' ?>><?= e($tzLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="form-foot">
                <button type="submit" class="btn btn-sm btn-primary">保存</button>
            </div>
        </form>
    </div>
</section>

<section class="tab-panel" data-panel="accounts">
    <div class="panel">
        <div class="cell-head"><h3>全局默认（未单独配置的账号沿用）</h3></div>
        <form class="form form-narrow" data-ajax-form="settings_save" data-url="<?= e(url('?page=api&action=settings_save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="guard_defaults">
            <label class="field">
                <span class="field-label">默认账单周期起始日（每月第几天重置流量统计）</span>
                <input type="number" class="input" name="billing_cycle_day" min="1" max="28"
                       value="<?= e((string)setting('billing_cycle_day', 1)) ?>" required>
            </label>
            <label class="field field-check">
                <input type="checkbox" name="guard_enabled" value="1" <?= setting('guard_enabled', '1') === '1' ? 'checked' : '' ?>>
                <span>默认启用自动熔断（账号/实例流量达到阈值自动关机，避免超额费用）</span>
            </label>
            <label class="field">
                <span class="field-label">默认账号级阈值（GB/月，配额为 0 时使用）</span>
                <input type="number" class="input" name="guard_threshold_gb" min="1" step="1"
                       value="<?= e((string)setting('guard_threshold_gb', 180)) ?>" required>
            </label>
            <label class="field">
                <span class="field-label">默认预警百分比（达到后发通知）</span>
                <input type="number" class="input" name="guard_warn_pct" min="50" max="99"
                       value="<?= e((string)setting('guard_warn_pct', 80)) ?>" required>
            </label>
            <label class="field">
                <span class="field-label">默认熔断冷却时间（分钟）</span>
                <input type="number" class="input" name="guard_cooldown_min" min="1"
                       value="<?= e((string)setting('guard_cooldown_min', 120)) ?>" required>
            </label>
            <div class="form-foot">
                <button type="submit" class="btn btn-sm btn-primary">保存全局默认</button>
            </div>
        </form>
    </div>

    <?php foreach ($accounts as $a): ?>
        <?php if ((int)$a['is_demo'] === 1) { continue; } ?>
        <?php $cfg = \App\Services\AccountConfig::guardConfig((int)$a['id']); ?>
        <div class="panel">
            <div class="cell-head">
                <h3>账号：<?= e($a['name']) ?></h3>
                <span class="sub"><?= e($a['region']) ?> · 配额 <?= e(number_format((float)$a['quota_gb'], 0)) ?> GB</span>
            </div>
            <form class="form form-narrow" data-ajax-form="settings_save" data-url="<?= e(url('?page=api&action=settings_save')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="section" value="account_cfg">
                <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                <label class="field">
                    <span class="field-label">账单周期起始日（该账号独立）</span>
                    <input type="number" class="input" name="billing_cycle_day" min="1" max="28"
                           value="<?= e((string)\App\Services\AccountConfig::billingCycleDay((int)$a['id'])) ?>" required>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="guard_enabled" value="1" <?= (string)$cfg['enabled'] === '1' ? 'checked' : '' ?>>
                    <span>启用该账号自动熔断</span>
                </label>
                <div class="form-row">
                    <label class="field">
                        <span class="field-label">账号级阈值（GB/月，配额为 0 时使用）</span>
                        <input type="number" class="input" name="guard_threshold_gb" min="1" step="1"
                               value="<?= e((string)(float)$cfg['threshold_gb']) ?>" required>
                    </label>
                    <label class="field">
                        <span class="field-label">预警百分比</span>
                        <input type="number" class="input" name="guard_warn_pct" min="50" max="99"
                               value="<?= e((string)(int)$cfg['warn_pct']) ?>" required>
                    </label>
                </div>
                <label class="field">
                    <span class="field-label">熔断冷却时间（分钟）</span>
                    <input type="number" class="input" name="guard_cooldown_min" min="1"
                           value="<?= e((string)(int)$cfg['cooldown_min']) ?>" required>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="cost_enabled" value="1" <?= \App\Services\CostService::enabled((int)$a['id']) ? 'checked' : '' ?>>
                    <span><strong>成本分析（费用中心）</strong><br><small>同步当月费用与账户余额，总览卡片显示；需要 RAM 权限 bss:DescribeInstanceBill、bss:QueryAccountBalance</small></span>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="keepalive_auto" value="1" <?= \App\Services\AccountConfig::keepaliveAuto((int)$a['id']) ? 'checked' : '' ?>>
                    <span><strong>自动保活（所有抢占式实例）</strong><br><small>新添加的真实账号默认开启；无需创建保活任务，调度每轮自动检查：面板创建的实例被释放后自动重建；非面板创建的停止后自动拉起、释放后发通知</small></span>
                </label>
                <p class="hint">自动保活的检查频率 = 服务器上 cron.php run 的执行频率。保存后该账号与其它账号互不影响；单实例阈值与「每月 1 号自动开机」在实例详情页单独配置。</p>
                <div class="form-foot">
                    <button type="submit" class="btn btn-sm btn-primary">保存该账号设置</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (count(array_filter($accounts, fn ($x) => (int)$x['is_demo'] !== 1)) === 0): ?>
        <div class="panel"><p class="empty-inline">暂无真实账号，添加后即可按账号独立配置。</p></div>
    <?php endif; ?>
</section>

<section class="tab-panel" data-panel="notify">
    <div class="panel">
        <div class="cell-head"><h3>通知渠道</h3></div>
        <form class="form form-narrow" data-ajax-form="settings_save" data-url="<?= e(url('?page=api&action=settings_save')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="section" value="notify">

            <div class="notify-grid">
                <label class="field field-check">
                    <input type="checkbox" name="ch_telegram" value="1" <?= in_array('telegram', $channels, true) ? 'checked' : '' ?>>
                    <span><strong>Telegram</strong><br><small>机器人推送</small></span>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="ch_serverchan" value="1" <?= in_array('serverchan', $channels, true) ? 'checked' : '' ?>>
                    <span><strong>Server酱</strong><br><small>微信推送（Turbo）</small></span>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="ch_webhook" value="1" <?= in_array('webhook', $channels, true) ? 'checked' : '' ?>>
                    <span><strong>Webhook</strong><br><small>POST JSON 到自定义地址</small></span>
                </label>
                <label class="field field-check">
                    <input type="checkbox" name="ch_email" value="1" <?= in_array('email', $channels, true) ? 'checked' : '' ?>>
                    <span><strong>邮件</strong><br><small>SMTP 发送</small></span>
                </label>
            </div>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">Telegram Bot Token</span>
                    <input type="text" class="input mono" name="telegram_bot_token" value="<?= e((string)setting('telegram_bot_token', '')) ?>" autocomplete="off">
                </label>
                <label class="field">
                    <span class="field-label">Telegram Chat ID</span>
                    <input type="text" class="input mono" name="telegram_chat_id" value="<?= e((string)setting('telegram_chat_id', '')) ?>">
                </label>
            </div>
            <label class="field">
                <span class="field-label">Server酱 SendKey</span>
                <input type="text" class="input mono" name="serverchan_key" value="<?= e((string)setting('serverchan_key', '')) ?>" autocomplete="off">
            </label>
            <label class="field">
                <span class="field-label">Webhook URL</span>
                <input type="url" class="input mono" name="webhook_url" value="<?= e((string)setting('webhook_url', '')) ?>" placeholder="https://example.com/hook">
            </label>
            <label class="field">
                <span class="field-label">HTTP(S) 代理（可选，Telegram 等国外接口需要）</span>
                <input type="text" class="input mono" name="http_proxy" value="<?= e((string)setting('http_proxy', '')) ?>"
                       placeholder="http://127.0.0.1:7890" autocomplete="off">
            </label>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">SMTP 服务器</span>
                    <input type="text" class="input mono" name="smtp_host" value="<?= e((string)setting('smtp_host', '')) ?>" placeholder="smtp.example.com">
                </label>
                <label class="field field-sm">
                    <span class="field-label">端口</span>
                    <input type="number" class="input" name="smtp_port" value="<?= e((string)setting('smtp_port', 465)) ?>">
                </label>
                <label class="field field-sm">
                    <span class="field-label">加密</span>
                    <select class="input" name="smtp_encryption">
                        <option value="ssl" <?= setting('smtp_encryption', 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= setting('smtp_encryption', 'ssl') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                        <option value="none" <?= setting('smtp_encryption', 'ssl') === 'none' ? 'selected' : '' ?>>无</option>
                    </select>
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">SMTP 用户名</span>
                    <input type="text" class="input mono" name="smtp_user" value="<?= e((string)setting('smtp_user', '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">SMTP 密码 <span class="sub"><?= $smtpPassSet ? '（已保存，留空不修改）' : '' ?></span></span>
                    <input type="password" class="input mono" name="smtp_pass" autocomplete="new-password">
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">发件人地址</span>
                    <input type="email" class="input mono" name="smtp_from" value="<?= e((string)setting('smtp_from', '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">收件人地址</span>
                    <input type="email" class="input mono" name="smtp_to" value="<?= e((string)setting('smtp_to', '')) ?>">
                </label>
            </div>
            <div class="form-foot">
                <button type="submit" class="btn btn-sm btn-primary">保存通知设置</button>
                <?php foreach (['telegram', 'serverchan', 'webhook', 'email'] as $ch): ?>
                    <button type="button" class="btn btn-sm btn-outline" data-action="notify_test"
                            data-url="<?= e(url('?page=api&action=notify_test')) ?>" data-channel="<?= e($ch) ?>">测试 <?= e($ch) ?></button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</section>

<section class="tab-panel" data-panel="ops">
    <div class="dash-grid">
        <div class="cell span-6 panel">
            <div class="cell-head"><h3>调度器</h3></div>
            <ul class="kv-list">
                <li><span>上次运行</span><strong><?= e((string)setting('scheduler_last_run', '从未运行')) ?></strong></li>
                <li><span>上次结果</span>
                    <strong>
                        <?php if (isset($lastResult['tasks'])): ?>
                            任务 <?= (int)$lastResult['tasks'] ?> · 停机 <?= (int)$lastResult['stopped'] ?> 台 · 通知 <?= (int)$lastResult['notified'] ?> 条
                            <?= isset($lastResult['duration_ms']) ? ' · 耗时 ' . (int)$lastResult['duration_ms'] . 'ms' : '' ?>
                        <?php else: ?>—<?php endif; ?>
                    </strong>
                </li>
                <li><span>cron 建议</span><strong class="mono">* * * * * php <?= e(root_path('cron.php')) ?> run</strong></li>
            </ul>
            <div class="panel-foot">
                <button type="button" class="btn btn-sm" data-action="scheduler_run"
                        data-url="<?= e(url('?page=api&action=scheduler_run')) ?>">立即运行调度</button>
            </div>
        </div>
        <div class="cell span-6 panel">
            <div class="cell-head"><h3>存储与日志</h3></div>
            <ul class="kv-list">
                <li><span>数据库</span><strong class="mono"><?= e(root_path('storage/panel.db')) ?></strong></li>
                <li><span>日志</span><strong class="mono">storage/logs/app.log</strong></li>
                <li><span>密钥文件</span><strong class="mono">storage/app.key</strong></li>
            </ul>
            <div class="panel-foot">
                <button type="button" class="btn btn-sm btn-ghost" data-action="clear_notify_log"
                        data-url="<?= e(url('?page=api&action=clear_notify_log')) ?>"
                        data-confirm="确定清空通知发送记录吗？">清空通知记录</button>
            </div>
        </div>
    </div>
</section>
