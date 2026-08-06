<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$statuses = ['Running' => '运行中', 'Stopped' => '已停止', 'Starting' => '启动中', 'Stopping' => '停止中', 'Pending' => '创建中', 'Released' => '已释放', 'Expired' => '已过期'];
?>
<section class="page-head">
    <div>
        <h2 class="page-title">实例管理</h2>
        <p class="page-sub">共 <?= count($instances) ?> 台实例</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-sm" href="<?= e(url('?page=create')) ?>">＋ 创建实例</a>
        <button type="button" class="btn btn-sm btn-outline" data-action="sync" data-url="<?= e(url('?page=api&action=sync')) ?>">同步缓存</button>
    </div>
</section>

<form class="filter-bar" method="get" action="<?= e(url('?page=instances')) ?>">
    <input type="hidden" name="page" value="instances">
    <select class="input input-sm" name="account">
        <option value="0">全部账号</option>
        <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= (int)$filters['account_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="input input-sm" name="status">
        <option value="">全部状态</option>
        <?php foreach ($statuses as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <input class="input input-sm" type="text" name="q" value="<?= e($filters['search']) ?>" placeholder="名称 / ID / IP">
    <button class="btn btn-sm" type="submit">筛选</button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="table" id="instance-table">
            <thead>
            <tr>
                <th>实例</th>
                <th>账号 / 地域</th>
                <th>规格</th>
                <th>公网 IP</th>
                <th>计费</th>
                <th>本月流量</th>
                <th>状态</th>
                <th class="ta-r">操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($instances as $it): ?>
                <?php
                $used = $usage[$it['account_id'] . ':' . $it['instance_id']] ?? 0;
                $limit = $it['traffic_limit_gb'] !== null && (float)$it['traffic_limit_gb'] > 0
                    ? (float)$it['traffic_limit_gb'] * \App\Services\TrafficService::GB : null;
                $pct = $limit ? $used / $limit : 0;
                $canOperate = !in_array($it['status'], ['Released', 'Expired', 'Deleted'], true);
                ?>
                <tr>
                    <td>
                        <a class="link" href="<?= e(url('?page=instance&id=' . (int)$it['id'])) ?>"><?= e($it['instance_name'] ?: $it['instance_id']) ?></a>
                        <span class="sub mono"><?= e($it['instance_id']) ?></span>
                    </td>
                    <td>
                        <?= e($it['account_name']) ?>
                        <span class="sub"><?= e($it['region_id']) ?></span>
                    </td>
                    <td class="mono">
                        <?= e($it['instance_type']) ?>
                        <span class="sub"><?= (int)$it['cpu'] ?>C / <?= e(number_format((int)$it['memory_mb'] / 1024, 1)) ?>G</span>
                    </td>
                    <td class="mono">
                        <?= e($it['public_ip'] ?: ($it['eip'] ?: '—')) ?>
                        <?php if ($it['eip'] !== '' && $it['public_ip'] !== $it['eip']): ?>
                            <span class="sub">EIP: <?= e($it['eip']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $it['charge_type'] === 'PrePaid' ? '包年包月' : '按量' ?>
                        <?php if ($it['spot_strategy'] !== 'NoSpot'): ?>
                            <span class="pill pill-warn">抢占</span>
                        <?php endif; ?>
                    </td>
                    <td class="usage-cell">
                        <span class="mono"><?= e(number_format($used / \App\Services\TrafficService::GB, 2)) ?> GB</span>
                        <?php if ($limit): ?>
                            <span class="mini-track"><span class="mini-bar <?= $pct >= 1 ? 'danger' : ($pct >= 0.8 ? 'warn' : '') ?>" style="width:<?= e((string)min(100, $pct * 100)) ?>%"></span></span>
                            <span class="sub">限 <?= e(number_format((float)$it['traffic_limit_gb'], 1)) ?> GB</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="pill pill-<?= e(status_class($it['status'])) ?>"><?= e(status_label($it['status'])) ?></span></td>
                    <td class="ta-r actions">
                        <a class="btn btn-xs" href="<?= e(url('?page=instance&id=' . (int)$it['id'])) ?>">详情</a>
                        <?php if ($canOperate): ?>
                            <?php if ($it['status'] !== 'Running' && $it['status'] !== 'Starting'): ?>
                                <button type="button" class="btn btn-xs btn-outline" data-action="instance_start"
                                        data-url="<?= e(url('?page=api&action=instance_start')) ?>"
                                        data-account="<?= (int)$it['account_id'] ?>" data-instance="<?= e($it['instance_id']) ?>">启动</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-xs btn-outline" data-action="instance_stop"
                                        data-url="<?= e(url('?page=api&action=instance_stop')) ?>"
                                        data-account="<?= (int)$it['account_id'] ?>" data-instance="<?= e($it['instance_id']) ?>">停止</button>
                            <?php endif; ?>
                            <?php if ($it['status'] === 'Running'): ?>
                                <button type="button" class="btn btn-xs btn-ghost" data-action="instance_reboot"
                                        data-url="<?= e(url('?page=api&action=instance_reboot')) ?>"
                                        data-account="<?= (int)$it['account_id'] ?>" data-instance="<?= e($it['instance_id']) ?>">重启</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($instances) === 0): ?>
                <tr><td colspan="8" class="empty">暂无匹配实例。请先在「账号」页添加账号，或在演示模式下体验。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
