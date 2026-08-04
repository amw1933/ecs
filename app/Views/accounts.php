<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
function mask_key(string $k): string
{
    if (strlen($k) <= 8) {
        return str_repeat('*', strlen($k));
    }
    return substr($k, 0, 4) . str_repeat('*', max(4, strlen($k) - 8)) . substr($k, -4);
}
?>
<section class="page-head">
    <div>
        <h2 class="page-title">账号管理</h2>
        <p class="page-sub">阿里云 RAM 子账号 AccessKey，密钥加密存储（AES-256-GCM）</p>
    </div>
</section>

<section class="dash-grid">
    <div class="cell span-5 panel">
        <div class="cell-head"><h3>添加 / 编辑账号</h3><span class="cell-tag" id="account-form-mode">新增</span></div>
        <form class="form" data-ajax-form="account_add" id="account-form"
              data-url="<?= e(url('?page=api&action=account_add')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="0">
            <label class="field field-check">
                <input type="checkbox" name="is_demo" value="1" id="f-demo">
                <span><strong>演示账号</strong><br><small>无需密钥，使用本地模拟数据完整体验面板功能</small></span>
            </label>
            <label class="field">
                <span class="field-label">账号名称</span>
                <input type="text" class="input" name="name" placeholder="例如：生产账号" required>
            </label>
            <label class="field" id="f-ak-wrap">
                <span class="field-label">AccessKey ID</span>
                <input type="text" class="input mono" name="access_key_id" autocomplete="off" required>
            </label>
            <label class="field" id="f-sk-wrap">
                <span class="field-label">AccessKey Secret <span class="sub">（编辑时留空表示不修改）</span></span>
                <input type="password" class="input mono" name="access_key_secret" autocomplete="new-password">
            </label>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">默认地域</span>
                    <select class="input" name="region">
                        <?php foreach (['cn-hangzhou' => '华东1（杭州）', 'cn-shanghai' => '华东2（上海）', 'cn-qingdao' => '华北1（青岛）', 'cn-beijing' => '华北2（北京）', 'cn-shenzhen' => '华南1（深圳）', 'cn-hongkong' => '中国香港'] as $rid => $rname): ?>
                            <option value="<?= e($rid) ?>"><?= e($rname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">月度流量配额（GB）</span>
                    <input type="number" class="input" name="quota_gb" value="200" min="0" step="1" required>
                </label>
            </div>
            <label class="field">
                <span class="field-label">备注</span>
                <input type="text" class="input" name="note" placeholder="可选">
            </label>
            <div class="form-foot">
                <button type="submit" class="btn btn-sm btn-primary">保存账号</button>
                <button type="button" class="btn btn-sm btn-ghost hidden" id="account-form-cancel">取消编辑</button>
            </div>
        </form>
        <p class="hint">RAM 最小权限建议：<code>ecs:DescribeInstances</code>、<code>ecs:StartInstance</code>、<code>ecs:StopInstance</code>、<code>ecs:RebootInstance</code>、<code>ecs:DeleteInstance</code>、<code>ecs:CreateInstance</code>、<code>cms:DescribeMetricList</code> 等。</p>
    </div>

    <div class="cell span-7 panel">
        <div class="cell-head"><h3>账号列表</h3><span class="cell-tag"><?= count($accounts) ?> 个</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>名称</th><th>AccessKey</th><th>地域</th><th>配额</th><th>费用</th><th>连接测试</th><th>状态</th><th class="ta-r">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $a): ?>
                    <tr>
                        <td>
                            <?= e($a['name']) ?>
                            <?php if ((int)$a['is_demo'] === 1): ?>
                                <span class="pill pill-warn">演示</span>
                            <?php endif; ?>
                            <?php if ($a['note'] !== ''): ?>
                                <span class="sub"><?= e($a['note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= e(mask_key((string)$a['access_key_id'])) ?></td>
                        <td class="mono"><?= e($a['region']) ?></td>
                        <td class="mono"><?= e(number_format((float)$a['quota_gb'], 0)) ?> GB</td>
                        <td class="mono">
                            <?php if (!empty($costs[$a['id']]['enabled'])): ?>
                                <?php $c = $costs[$a['id']]['data'] ?? null; ?>
                                <?php if ($c !== null): ?>
                                    <?= e(number_format((float)$c['amount'], 2)) ?> 元 · 余额 <?= e(number_format((float)$c['balance'], 2)) ?>
                                <?php else: ?>
                                    <span class="sub">待同步</span>
                                <?php endif; ?>
                                <?php if ((int)$a['is_demo'] !== 1): ?>
                                    <button type="button" class="btn btn-xs btn-ghost" data-action="cost_toggle"
                                            data-url="<?= e(url('?page=api&action=cost_toggle')) ?>" data-id="<?= (int)$a['id'] ?>">关闭</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sub">未开启</span>
                                <?php if ((int)$a['is_demo'] !== 1): ?>
                                    <button type="button" class="btn btn-xs btn-outline" data-action="cost_toggle"
                                            data-url="<?= e(url('?page=api&action=cost_toggle')) ?>" data-id="<?= (int)$a['id'] ?>">开启</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['last_test_at'] !== null): ?>
                                <span class="pill pill-<?= (int)$a['last_test_ok'] === 1 ? 'ok' : 'bad' ?>">
                                    <?= (int)$a['last_test_ok'] === 1 ? '通过' : '失败' ?>
                                </span>
                                <span class="sub"><?= e($a['last_test_at']) ?></span>
                            <?php else: ?>
                                <span class="sub">未测试</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill pill-<?= (int)$a['enabled'] === 1 ? 'ok' : 'muted' ?>"><?= (int)$a['enabled'] === 1 ? '启用' : '停用' ?></span>
                        </td>
                        <td class="ta-r actions">
                            <button type="button" class="btn btn-xs btn-outline" data-action="account_test"
                                    data-url="<?= e(url('?page=api&action=account_test')) ?>" data-id="<?= (int)$a['id'] ?>">测试</button>
                            <?php if ((int)$a['is_demo'] !== 1): ?>
                                <button type="button" class="btn btn-xs btn-ghost" data-action="account_toggle"
                                        data-url="<?= e(url('?page=api&action=account_toggle')) ?>" data-id="<?= (int)$a['id'] ?>">
                                    <?= (int)$a['enabled'] === 1 ? '停用' : '启用' ?>
                                </button>
                                <button type="button" class="btn btn-xs btn-ghost" data-edit-account
                                        data-id="<?= (int)$a['id'] ?>" data-name="<?= e($a['name']) ?>"
                                        data-ak="<?= e($a['access_key_id']) ?>" data-region="<?= e($a['region']) ?>"
                                        data-quota="<?= e((string)$a['quota_gb']) ?>" data-note="<?= e($a['note']) ?>">编辑</button>
                                <button type="button" class="btn btn-xs btn-danger-ghost" data-action="account_delete"
                                        data-url="<?= e(url('?page=api&action=account_delete')) ?>" data-id="<?= (int)$a['id'] ?>"
                                        data-confirm="删除账号「<?= e($a['name']) ?>」将同时删除其实例缓存、流量与任务数据，确定继续吗？">删除</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($accounts) === 0): ?>
                    <tr><td colspan="8" class="empty">暂无账号</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
