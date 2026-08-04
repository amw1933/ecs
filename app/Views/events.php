<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
?>
<section class="page-head">
    <div>
        <h2 class="page-title">事件中心</h2>
        <p class="page-sub">账号、实例、流量熔断与任务等操作记录</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-sm btn-danger-ghost" data-action="events_clear"
                data-url="<?= e(url('?page=api&action=events_clear')) ?>"
                data-confirm="确定清空全部事件记录吗？">清空事件</button>
    </div>
</section>

<form class="filter-bar" method="get" action="<?= e(url('?page=events')) ?>">
    <input type="hidden" name="page" value="events">
    <select class="input input-sm" name="kind">
        <option value="">全部类型</option>
        <?php foreach ($kinds as $k): ?>
            <option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($k) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="input input-sm" name="level">
        <option value="">全部级别</option>
        <?php foreach ($levels as $lv): ?>
            <option value="<?= e($lv) ?>" <?= $filters['level'] === $lv ? 'selected' : '' ?>><?= e($lv) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">筛选</button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>时间</th><th>级别</th><th>类型</th><th>标题</th><th>详情</th></tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td class="mono"><?= e($ev['ts']) ?></td>
                    <td><span class="pill pill-<?= e($ev['level']) ?>"><?= e($ev['level']) ?></span></td>
                    <td><span class="pill pill-muted"><?= e($ev['kind']) ?></span></td>
                    <td><?= e($ev['title']) ?></td>
                    <td class="sub"><?= e($ev['body']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($events) === 0): ?>
                <tr><td colspan="5" class="empty">暂无事件</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
