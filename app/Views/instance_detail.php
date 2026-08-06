<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$it = $instance;
$canOperate = !in_array($it['status'], ['Released', 'Expired', 'Deleted'], true);
?>
<section class="page-head">
    <div>
        <h2 class="page-title">
            <?= e($it['instance_name'] ?: $it['instance_id']) ?>
            <span class="pill pill-<?= e(status_class($it['status'])) ?>"><?= e(status_label($it['status'])) ?></span>
        </h2>
        <p class="page-sub mono"><?= e($it['instance_id']) ?> · <?= e($it['account_name']) ?> / <?= e($it['region_id']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-sm btn-ghost" href="<?= e(url('?page=instances')) ?>">← 返回列表</a>
        <?php if ($canOperate): ?>
            <?php if ($it['status'] !== 'Running' && $it['status'] !== 'Starting'): ?>
                <button type="button" class="btn btn-sm" data-action="instance_start"
                        data-url="<?= e(url('?page=api&action=instance_start')) ?>"
                        data-account="<?= (int)$it['account_id'] ?>" data-instance="<?= e($it['instance_id']) ?>">启动</button>
            <?php else: ?>
                <button type="button" class="btn btn-sm" data-action="instance_stop"
                        data-url="<?= e(url('?page=api&action=instance_stop')) ?>"
                        data-account="<?= (int)$it['account_id'] ?>" data-instance="<?= e($it['instance_id']) ?>">停止</button>
            <?php endif; ?>
            <?php if ($it['status'] === 'Running'): ?>
                <button type="button" class="btn btn-sm btn-outline" data-action="instance_reboot"
                        data-url="<?= e(url('?page=api&action=instance_reboot')) ?>"
                        data-account="<?= (int)$it['account_id'] ?>" data-instance="<?= e($it['instance_id']) ?>">重启</button>
            <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline" data-action="instance_traffic_refresh"
                data-url="<?= e(url('?page=api&action=instance_traffic_refresh')) ?>" data-id="<?= (int)$it['id'] ?>">刷新流量</button>
    </div>
</section>

<section class="dash-grid">
    <div class="cell span-4 panel">
        <div class="cell-head"><h3>实例信息</h3></div>
        <ul class="kv-list">
            <li><span>规格</span><strong class="mono"><?= e($it['instance_type']) ?></strong></li>
            <li><span>配置</span><strong><?= (int)$it['cpu'] ?> vCPU / <?= e(number_format((int)$it['memory_mb'] / 1024, 1)) ?> GiB</strong></li>
            <li><span>系统</span><strong><?= e($it['os_name'] ?: $it['image_id']) ?></strong></li>
            <li><span>公网 IP</span><strong class="mono"><?= e($it['public_ip'] ?: ($it['eip'] ?: '—')) ?></strong></li>
            <li><span>私网 IP</span><strong class="mono"><?= e($it['private_ip'] ?: '—') ?></strong></li>
            <li><span>计费</span><strong><?= $it['charge_type'] === 'PrePaid' ? '包年包月' : '按量付费' ?></strong></li>
            <?php if ($it['spot_strategy'] !== 'NoSpot'): ?>
                <li><span>抢占式</span><strong class="warn-text"><?= e($it['spot_strategy']) ?></strong></li>
            <?php endif; ?>
            <li><span>创建时间</span><strong><?= e($it['created_time'] ?: '—') ?></strong></li>
            <li><span>到期时间</span><strong><?= e($it['expired_at'] ?: '—') ?></strong></li>
            <?php if ($cpu !== null): ?>
                <li><span>CPU 使用率</span><strong class="mono"><?= e(number_format($cpu, 1)) ?>%</strong></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="cell span-8 panel">
        <div class="cell-head">
            <h3>公网流量 · 近 30 天</h3>
            <span class="cell-tag">本月已用 <?= e(number_format($used / \App\Services\TrafficService::GB, 2)) ?> GB</span>
        </div>
        <div class="chart chart-lg" id="instance-chart"
             data-labels='<?= e(json_encode($chartLabels, JSON_UNESCAPED_UNICODE)) ?>'
             data-series='<?= e(json_encode([['name' => '入流量', 'color' => '#4cc9f0', 'values' => $chartIn], ['name' => '出流量', 'color' => '#f4c95d', 'values' => $chartOut]])) ?>'></div>
        <?php if ($used <= 0): ?>
            <p class="hint">本月公网流量为 0：实例可能空闲或没有公网访问。若该实例绑定了 EIP，请确认 EIP 有实际流量经过；确认后点击右上角「刷新流量」重新拉取。</p>
        <?php endif; ?>
    </div>
</section>

<section class="dash-grid">
    <div class="cell span-6 panel">
        <div class="cell-head">
            <h3>流量保护规则</h3>
            <span class="cell-tag">参与自动熔断</span>
        </div>
        <form class="form" data-ajax-form="instance_rule"
              data-url="<?= e(url('?page=api&action=instance_rule')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <div class="form-row">
                <label class="field">
                    <span class="field-label">单实例流量阈值（GB）</span>
                    <input type="number" class="input" name="traffic_limit_gb" min="0" step="0.1"
                           value="<?= $it['traffic_limit_gb'] !== null ? e((string)$it['traffic_limit_gb']) : '' ?>"
                           placeholder="留空则不限制">
                </label>
                <label class="field">
                    <span class="field-label">自动关机</span>
                    <select class="input" name="auto_shutdown">
                        <option value="1" <?= (int)$it['auto_shutdown'] === 1 ? 'selected' : '' ?>>开启（流量超限自动关机）</option>
                        <option value="0" <?= (int)$it['auto_shutdown'] === 0 ? 'selected' : '' ?>>关闭</option>
                    </select>
                </label>
            </div>
            <label class="field field-check">
                <input type="checkbox" name="auto_power_on_monthly" value="1" <?= (int)$it['auto_power_on_monthly'] === 1 ? 'checked' : '' ?>>
                <span>每月 1 号自动开机（当天调度运行时自动启动该实例并通过通知渠道告知）</span>
            </label>
            <div class="form-foot">
                <button type="submit" class="btn btn-sm">保存规则</button>
            </div>
        </form>
    </div>

    <div class="cell span-6 panel">
        <div class="cell-head">
            <h3>保活与定时任务</h3>
            <span class="cell-tag">抢占式实例</span>
        </div>
        <?php
        $hasRecipe = is_array(json_decode((string)$it['recipe_json'], true)) && !empty(json_decode((string)$it['recipe_json'], true)['recipe_id']);
        ?>
        <?php if ($hasRecipe): ?>
            <p class="hint">该实例由面板创建并保存了重建参数。可创建「保活」任务：实例被抢占释放后自动按原配置重建。</p>
            <div class="panel-foot">
                <a class="btn btn-sm" href="<?= e(url('?page=tasks')) ?>">前往创建保活任务 →</a>
            </div>
        <?php else: ?>
            <p class="hint">此实例不是由面板创建（未保存重建参数），保活仅支持“停止后自动拉起”；被阿里云释放后无法自动重建，会发通知提醒。你仍可在「任务」页配置保活或定时开关机。</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <div class="cell-head"><h3>相关事件</h3></div>
    <?php if (count($events) > 0): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>时间</th><th>级别</th><th>标题</th><th>详情</th></tr></thead>
                <tbody>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td class="mono"><?= e($ev['ts']) ?></td>
                        <td><span class="pill pill-<?= e($ev['level']) ?>"><?= e($ev['level']) ?></span></td>
                        <td><?= e($ev['title']) ?></td>
                        <td class="sub"><?= e($ev['body']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="empty-inline">暂无相关事件</p>
    <?php endif; ?>
</section>
