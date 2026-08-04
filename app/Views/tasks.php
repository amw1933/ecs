<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
?>
<section class="page-head">
    <div>
        <h2 class="page-title">定时任务</h2>
        <p class="page-sub">定时开关机与抢占式实例保活，由 cron 驱动（见 README）</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-sm" data-action="scheduler_run"
                data-url="<?= e(url('?page=api&action=scheduler_run')) ?>">立即运行调度</button>
    </div>
</section>

<section class="dash-grid">
    <div class="cell span-5 panel">
        <div class="cell-head"><h3>新建任务</h3></div>
        <form class="form" data-ajax-form="task_add" data-url="<?= e(url('?page=api&action=task_add')) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span class="field-label">任务名称</span>
                <input type="text" class="input" name="name" placeholder="例如：夜间停机省流量" required>
            </label>
            <label class="field">
                <span class="field-label">任务类型</span>
                <select class="input" name="kind" id="task-kind">
                    <option value="power_off">定时关机</option>
                    <option value="power_on">定时开机</option>
                    <option value="keepalive">抢占式实例保活</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">账号</span>
                <select class="input" name="account_id" id="task-account" required>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" id="task-instance-wrap">
                <span class="field-label">实例</span>
                <select class="input" name="instance_id" required>
                    <?php foreach ($instances as $it): ?>
                        <option value="<?= e($it['instance_id']) ?>" data-account="<?= (int)$it['account_id'] ?>"><?= e($it['instance_name'] ?: $it['instance_id']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" id="task-cron-wrap">
                <span class="field-label">cron 表达式（分 时 日 月 周）</span>
                <input type="text" class="input mono" name="cron_expr" value="0 3 * * *" required>
            </label>
            <p class="hint" id="task-kind-hint">
                示例：<code>0 3 * * *</code> 每天 03:00 · <code>*/30 * * * *</code> 每 30 分钟 ·
                <code>30 8 * * 1-5</code> 工作日 08:30
            </p>
            <div class="form-foot">
                <button type="submit" class="btn btn-sm btn-primary">添加任务</button>
            </div>
        </form>
    </div>

    <div class="cell span-7 panel">
        <div class="cell-head"><h3>任务列表</h3><span class="cell-tag"><?= count($tasks) ?> 个</span></div>
        <?php if (count($tasks) === 0): ?>
            <p class="empty-inline">暂无任务</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr><th>任务</th><th>类型</th><th>目标</th><th>cron</th><th>上次运行</th><th>下次运行</th><th>状态</th><th class="ta-r">操作</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tasks as $t): ?>
                        <tr>
                            <td><?= e($t['name']) ?></td>
                            <td>
                                <?= ['power_on' => '定时开机', 'power_off' => '定时关机', 'keepalive' => '保活'][$t['kind']] ?? e($t['kind']) ?>
                            </td>
                            <td class="mono sub"><?= e($t['instance_id'] ?: '—') ?></td>
                            <td class="mono"><?= e($t['cron_expr']) ?></td>
                            <td class="mono sub"><?= e($t['last_run_at'] ?: '—') ?></td>
                            <td class="mono"><?= e($t['next_human']) ?></td>
                            <td>
                                <span class="pill pill-<?= (int)$t['enabled'] === 1 ? 'ok' : 'muted' ?>"><?= (int)$t['enabled'] === 1 ? '启用' : '停用' ?></span>
                            </td>
                            <td class="ta-r actions">
                                <button type="button" class="btn btn-xs btn-ghost" data-action="task_run"
                                        data-url="<?= e(url('?page=api&action=task_run')) ?>" data-id="<?= (int)$t['id'] ?>">执行</button>
                                <button type="button" class="btn btn-xs btn-outline" data-action="task_toggle"
                                        data-url="<?= e(url('?page=api&action=task_toggle')) ?>" data-id="<?= (int)$t['id'] ?>">
                                    <?= (int)$t['enabled'] === 1 ? '停用' : '启用' ?>
                                </button>
                                <button type="button" class="btn btn-xs btn-danger-ghost" data-action="task_delete"
                                        data-url="<?= e(url('?page=api&action=task_delete')) ?>" data-id="<?= (int)$t['id'] ?>"
                                        data-confirm="删除任务「<?= e($t['name']) ?>」？">删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
