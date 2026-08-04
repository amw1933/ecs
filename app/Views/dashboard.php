<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$quotaUsedPct = $quotaTotal > 0 ? $grandTotal / ($quotaTotal * \App\Services\TrafficService::GB) : 0;
$guardOn = $guardEnabled === '1';
?>
<section class="page-head">
    <div>
        <h2 class="page-title">总览</h2>
        <p class="page-sub">全账号实例与流量状态一览</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-sm" data-action="sync" data-url="<?= e(url('?page=api&action=sync')) ?>">同步实例与流量</button>
        <button type="button" class="btn btn-sm btn-outline" data-url="<?= e(url('?page=api&action=dashboard_stats')) ?>" data-refresh-stats>刷新</button>
    </div>
</section>

<section class="dash-grid">
    <div class="cell span-3 stat-cell">
        <span class="stat-label">云账号</span>
        <span class="stat-value" data-stat="accounts"><?= count($accounts) ?></span>
        <span class="stat-hint">已接入账号</span>
    </div>
    <div class="cell span-3 stat-cell">
        <span class="stat-label">实例总数</span>
        <span class="stat-value" data-stat="instances"><?= $instanceCount ?></span>
        <span class="stat-hint">缓存中的 ECS</span>
    </div>
    <div class="cell span-3 stat-cell">
        <span class="stat-label">运行中</span>
        <span class="stat-value ok-text" data-stat="running"><?= $running ?></span>
        <span class="stat-hint">含启动中</span>
    </div>
    <div class="cell span-3 stat-cell">
        <span class="stat-label">近 24h 告警</span>
        <span class="stat-value <?= $alerts > 0 ? 'warn-text' : '' ?>" data-stat="alerts"><?= $alerts ?></span>
        <span class="stat-hint">警告与错误事件</span>
    </div>
</section>

<section class="page-head">
    <div>
        <h2 class="page-title">账号流量</h2>
        <p class="page-sub">合计 <?= e(number_format($grandTotal / \App\Services\TrafficService::GB, 2)) ?> / <?= e(number_format($quotaTotal, 0)) ?> GB · 各账号独立计算</p>
    </div>
</section>

<section class="dash-grid">
    <?php foreach ($accountStats as $s): ?>
        <?php $barClass = $s['pct'] >= 1 ? 'danger' : ($s['pct'] >= 0.8 ? 'warn' : ''); ?>
        <div class="cell span-4 panel">
            <div class="cell-head">
                <h3><?= e($s['account']['name']) ?></h3>
                <?php if ((int)$s['account']['is_demo'] === 1): ?>
                    <span class="pill pill-warn">演示</span>
                <?php else: ?>
                    <span class="pill pill-<?= (int)$s['account']['enabled'] === 1 ? 'ok' : 'muted' ?>"><?= (int)$s['account']['enabled'] === 1 ? '启用' : '停用' ?></span>
                <?php endif; ?>
            </div>
            <div class="hero-number hero-number-sm">
                <strong><?= e(number_format($s['used'] / \App\Services\TrafficService::GB, 2)) ?></strong>
                <span>/ <?= e(number_format($s['quota'] / \App\Services\TrafficService::GB, 0)) ?> GB</span>
            </div>
            <div class="progress">
                <div class="progress-bar <?= e($barClass) ?>" style="width:<?= e((string)min(100, $s['pct'] * 100)) ?>%"></div>
            </div>
            <p class="hero-meta">已用 <?= e(number_format($s['pct'] * 100, 1)) ?>% · 周期内剩余 <?= e(number_format(max(0, $s['quota'] - $s['used']) / \App\Services\TrafficService::GB, 2)) ?> GB</p>
            <p class="hint">CDT 出网 <?= e(number_format($s['cdt'] / \App\Services\TrafficService::GB, 2)) ?> GB · 实例汇总 <?= e(number_format($s['inst'] / \App\Services\TrafficService::GB, 2)) ?> GB · 取较大值</p>
            <?php if (\App\Services\CostService::enabled((int)$s['account']['id'])): ?>
                <?php $cost = \App\Services\CostService::accountCost((int)$s['account']['id']); ?>
                <p class="hint"><?= $cost !== null
                    ? '本月费用 ' . e(number_format((float)$cost['amount'], 2)) . ' 元 · 余额 ' . e(number_format((float)$cost['balance'], 2)) . ' 元'
                    : '成本分析已开启，等待同步（调度运行后自动更新）' ?></p>
            <?php endif; ?>
            <div class="panel-foot">
                <a class="btn btn-xs btn-outline" href="<?= e(url('?page=traffic&account=' . (int)$s['account']['id'])) ?>">流量明细</a>
                <span class="sub">熔断：<?= (string)($s['guard']['enabled'] ?? '1') === '1' ? '开启' : '关闭' ?> · 阈值 <?= e(number_format((float)($s['guard']['threshold_gb'] ?? 180), 0)) ?> GB</span>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (count($accountStats) === 0): ?>
        <div class="cell span-12 panel"><p class="empty-inline">暂无账号，请先到「账号」页添加阿里云账号。</p></div>
    <?php endif; ?>
</section>

<section class="dash-grid">
    <div class="cell span-7 panel">
        <div class="cell-head">
            <h3>流量趋势 · 全部账号合计 · 近 30 天</h3>
            <span class="cell-tag">GB/日</span>
        </div>
        <div class="chart" id="traffic-chart"
             data-labels='<?= e(json_encode($chartLabels, JSON_UNESCAPED_UNICODE)) ?>'
             data-series='<?= e(json_encode($chartSeries)) ?>'></div>
    </div>

    <div class="cell span-5 panel">
        <div class="cell-head">
            <h3>熔断守护（按账号）</h3>
            <span class="cell-tag">独立配置</span>
        </div>
        <ul class="kv-list">
            <li><span>上次调度</span><strong><?= e($lastRun) ?></strong></li>
            <li><span>上次结果</span>
                <strong><?= isset($lastResult['tasks']) ? '任务 ' . (int)$lastResult['tasks'] . ' · 停机 ' . (int)$lastResult['stopped'] . ' 台' : '—' ?></strong>
            </li>
        </ul>
        <div class="hero-list">
            <?php foreach ($accountStats as $s): ?>
                <div class="hero-row">
                    <span class="hero-row-name"><?= e($s['account']['name']) ?></span>
                    <span class="hero-row-bar">
                        <span class="mini-bar" style="width:<?= e((string)min(100, $s['pct'] * 100)) ?>%"></span>
                    </span>
                    <span class="hero-row-val"><?= (string)($s['guard']['enabled'] ?? '1') === '1' ? '开启' : '关闭' ?> · <?= e(number_format((float)($s['guard']['threshold_gb'] ?? 180), 0)) ?> GB</span>
                </div>
            <?php endforeach; ?>
            <?php if (count($accountStats) === 0): ?>
                <p class="empty-inline">暂无账号</p>
            <?php endif; ?>
        </div>
        <div class="panel-foot">
            <a class="btn btn-sm btn-outline" href="<?= e(url('?page=settings')) ?>">守护配置</a>
            <button type="button" class="btn btn-sm" data-action="scheduler_run"
                    data-url="<?= e(url('?page=api&action=scheduler_run')) ?>">立即执行调度</button>
        </div>
    </div>
</section>

<section class="dash-grid">
    <div class="cell span-7 panel">
        <div class="cell-head">
            <h3>实例状态分布</h3>
            <a class="cell-link" href="<?= e(url('?page=instances')) ?>">查看全部 →</a>
        </div>
        <?php
        $byStatus = [];
        foreach ($instances as $it) {
            $byStatus[$it['status']] = ($byStatus[$it['status']] ?? 0) + 1;
        }
        $runningCount = $byStatus['Running'] ?? 0;
        $stoppedCount = $byStatus['Stopped'] ?? 0;
        $otherCount = count($instances) - $runningCount - $stoppedCount;
        ?>
        <div class="status-dist">
            <?php if (count($instances) > 0): ?>
                <div class="stack-bar">
                    <span class="stack-ok" style="width:<?= e((string)($runningCount / count($instances) * 100)) ?>%"></span>
                    <span class="stack-muted" style="width:<?= e((string)($stoppedCount / count($instances) * 100)) ?>%"></span>
                    <span class="stack-warn" style="width:<?= e((string)($otherCount / count($instances) * 100)) ?>%"></span>
                </div>
                <div class="legend">
                    <span><i class="dot dot-ok"></i> 运行中 <?= $runningCount ?></span>
                    <span><i class="dot dot-muted"></i> 已停止 <?= $stoppedCount ?></span>
                    <span><i class="dot dot-warn"></i> 其他 <?= $otherCount ?></span>
                </div>
            <?php else: ?>
                <p class="empty-inline">暂无实例数据，请先在「账号」页添加账号并同步。</p>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>实例</th><th>账号</th><th>规格</th><th>状态</th></tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($instances, 0, 6) as $it): ?>
                    <tr>
                        <td>
                            <a class="link" href="<?= e(url('?page=instance&id=' . (int)$it['id'])) ?>"><?= e($it['instance_name'] ?: $it['instance_id']) ?></a>
                            <span class="sub"><?= e($it['instance_id']) ?></span>
                        </td>
                        <td><?= e($it['account_name']) ?></td>
                        <td class="mono"><?= e($it['instance_type']) ?></td>
                        <td><span class="pill pill-<?= e(status_class($it['status'])) ?>"><?= e(status_label($it['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="cell span-5 panel">
        <div class="cell-head">
            <h3>最新事件</h3>
            <a class="cell-link" href="<?= e(url('?page=events')) ?>">事件中心 →</a>
        </div>
        <ul class="event-feed">
            <?php foreach ($events as $ev): ?>
                <li class="event-item">
                    <span class="event-dot event-<?= e($ev['level']) ?>"></span>
                    <div class="event-body">
                        <p class="event-title"><?= e($ev['title']) ?></p>
                        <?php if ($ev['body'] !== ''): ?>
                            <p class="event-desc"><?= e($ev['body']) ?></p>
                        <?php endif; ?>
                    </div>
                    <time class="event-time"><?= e(substr($ev['ts'], 5, 11)) ?></time>
                </li>
            <?php endforeach; ?>
            <?php if (count($events) === 0): ?>
                <p class="empty-inline">暂无事件</p>
            <?php endif; ?>
        </ul>
    </div>
</section>
