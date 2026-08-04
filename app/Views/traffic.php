<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
?>
<section class="page-head">
    <div>
        <h2 class="page-title">流量监控</h2>
        <p class="page-sub">账号口径：CDT 出网流量（含共享带宽包等）；实例口径：CMS 监控积分 · 最近同步：<?= e($lastSync) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-sm" data-action="traffic_refresh"
                data-url="<?= e(url('?page=api&action=traffic_refresh')) ?>"
                data-account="<?= (int)$accountId ?>">刷新全部流量</button>
    </div>
</section>

<section class="dash-grid">
    <?php foreach ($stats as $s): ?>
        <?php
        $pct = $s['pct'];
        $barClass = $pct >= 1 ? 'danger' : ($pct >= 0.8 ? 'warn' : '');
        ?>
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
                <div class="progress-bar <?= e($barClass) ?>" style="width:<?= e((string)min(100, $pct * 100)) ?>%"></div>
            </div>
            <p class="hero-meta">已用 <?= e(number_format($pct * 100, 1)) ?>% · 周期内剩余 <?= e(number_format(max(0, $s['quota'] - $s['used']) / \App\Services\TrafficService::GB, 2)) ?> GB</p>
            <p class="hint">CDT 出网 <?= e(number_format($s['cdt'] / \App\Services\TrafficService::GB, 2)) ?> GB · 实例汇总 <?= e(number_format($s['inst'] / \App\Services\TrafficService::GB, 2)) ?> GB · 取较大值</p>
            <?php if ($s['used'] <= 0): ?>
                <p class="hint">本月暂无流量：实例可能空闲、无公网访问，或共享带宽包/实例监控均未统计到。</p>
            <?php endif; ?>
            <div class="panel-foot">
                <a class="btn btn-xs btn-outline" href="<?= e(url('?page=traffic&account=' . (int)$s['account']['id'])) ?>">查看曲线</a>
                <?php if ((int)$s['account']['is_demo'] === 1): ?>
                    <button type="button" class="btn btn-xs btn-ghost" data-action="traffic_reset_demo"
                            data-url="<?= e(url('?page=api&action=traffic_reset_demo')) ?>"
                            data-account="<?= (int)$s['account']['id'] ?>">重置演示数据</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (count($stats) === 0): ?>
        <div class="cell span-12 panel"><p class="empty-inline">暂无账号，请先到「账号」页添加。</p></div>
    <?php endif; ?>
</section>

<?php if ($accountId > 0): ?>
    <section class="dash-grid">
        <div class="cell span-12 panel">
            <div class="cell-head">
                <h3>账号流量曲线 · 近 30 天</h3>
                <span class="cell-tag">GB/日</span>
            </div>
            <div class="chart" id="account-chart"
                 data-labels='<?= e(json_encode($labels, JSON_UNESCAPED_UNICODE)) ?>'
                 data-series='<?= e(json_encode($series)) ?>'></div>
        </div>
    </section>

    <section class="panel">
        <div class="cell-head">
            <h3>实例流量明细</h3>
            <span class="cell-tag">本月</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>实例</th><th>本月流量</th><th>占比</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php
                $total = array_sum($breakdown);
                $cdtTotal = 0;
                foreach ($stats as $s) {
                    if ((int)$s['account']['id'] === $accountId) {
                        $cdtTotal = $s['cdt'];
                    }
                }
                $allTotal = $cdtTotal + $total;
                ?>
                <?php if ($cdtTotal > 0): ?>
                    <tr>
                        <td>
                            <span class="link">账号 CDT 出网流量</span>
                            <span class="sub">共享带宽包 / 共享流量包等计费口径</span>
                        </td>
                        <td class="mono"><?= e(number_format($cdtTotal / \App\Services\TrafficService::GB, 2)) ?> GB</td>
                        <td class="usage-cell">
                            <?php $cdtPct = $allTotal > 0 ? $cdtTotal / $allTotal : 0; ?>
                            <span class="mini-track"><span class="mini-bar" style="width:<?= e((string)min(100, $cdtPct * 100)) ?>%"></span></span>
                            <span class="sub"><?= e(number_format($cdtPct * 100, 1)) ?>%</span>
                        </td>
                        <td><span class="sub">CMS 实例监控不覆盖</span></td>
                    </tr>
                <?php endif; ?>
                <?php
                foreach (\App\Services\EcsService::instances(['account_id' => $accountId]) as $it):
                    $used = $breakdown[$it['instance_id']] ?? 0;
                    $pct = $allTotal > 0 ? $used / $allTotal : 0;
                    ?>
                    <tr>
                        <td>
                            <a class="link" href="<?= e(url('?page=instance&id=' . (int)$it['id'])) ?>"><?= e($it['instance_name'] ?: $it['instance_id']) ?></a>
                            <span class="sub mono"><?= e($it['instance_id']) ?></span>
                        </td>
                        <td class="mono"><?= e(number_format($used / \App\Services\TrafficService::GB, 2)) ?> GB</td>
                        <td class="usage-cell">
                            <span class="mini-track"><span class="mini-bar" style="width:<?= e((string)min(100, $pct * 100)) ?>%"></span></span>
                            <span class="sub"><?= e(number_format($pct * 100, 1)) ?>%</span>
                        </td>
                        <td>
                            <a class="btn btn-xs" href="<?= e(url('?page=instance&id=' . (int)$it['id'])) ?>">详情</a>
                            <button type="button" class="btn btn-xs btn-ghost" data-action="instance_traffic_refresh"
                                    data-url="<?= e(url('?page=api&action=instance_traffic_refresh')) ?>"
                                    data-id="<?= (int)$it['id'] ?>">刷新</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
