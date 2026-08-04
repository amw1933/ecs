<?php
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }
$firstAccount = $accounts[0] ?? null;
?>
<section class="page-head">
    <div>
        <h2 class="page-title">创建实例</h2>
        <p class="page-sub">引导式创建 ECS 实例（单选配置，创建后可继续在实例详情配置保活）</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-sm btn-ghost" href="<?= e(url('?page=instances')) ?>">← 返回列表</a>
    </div>
</section>

<form class="dash-grid" data-ajax-form="instance_create"
      data-url="<?= e(url('?page=api&action=instance_create')) ?>">
    <?= csrf_field() ?>
    <div class="cell span-8 panel">
        <div class="cell-head"><h3>基础配置</h3></div>
        <div class="form form-grid">
            <label class="field">
                <span class="field-label">所属账号</span>
                <select class="input" name="account_id" id="f-account" required>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?>（<?= e($a['region']) ?>）</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">实例名称</span>
                <input type="text" class="input" name="name" placeholder="my-ecs-01" required>
            </label>
            <label class="field">
                <span class="field-label">地域</span>
                <select class="input" name="region" id="f-region" data-options="zones,types,images,security_groups,vswitches" required>
                    <option value="">加载中…</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">可用区（可选）</span>
                <select class="input" name="zone_id" id="f-zone">
                    <option value="">自动分配</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">实例规格</span>
                <select class="input" name="instance_type" id="f-type" required>
                    <option value="">请先选择地域</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">镜像</span>
                <select class="input" name="image_id" id="f-image" required>
                    <option value="">请先选择地域</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">安全组</span>
                <select class="input" name="security_group_id" id="f-sg" required>
                    <option value="">请先选择地域</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">交换机（可选）</span>
                <select class="input" name="vswitch_id" id="f-vswitch">
                    <option value="">不指定</option>
                </select>
            </label>
        </div>

        <div class="cell-head cell-head-gap"><h3>计费与网络</h3></div>
        <div class="form form-grid">
            <label class="field">
                <span class="field-label">计费方式</span>
                <select class="input" name="charge" id="f-charge">
                    <option value="postpaid">按量付费</option>
                    <option value="prepaid">包年包月</option>
                </select>
            </label>
            <label class="field" id="f-period-wrap">
                <span class="field-label">包月时长</span>
                <select class="input" name="period">
                    <?php foreach ([1, 2, 3, 6, 9, 12] as $m): ?>
                        <option value="<?= $m ?>"><?= $m ?> 个月</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">公网计费</span>
                <select class="input" name="internet_charge">
                    <option value="traffic">按使用流量</option>
                    <option value="bandwidth">按固定带宽</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">带宽上限（Mbps）</span>
                <input type="number" class="input" name="bandwidth_out" value="5" min="1" max="100" required>
            </label>
            <label class="field">
                <span class="field-label">抢占式实例</span>
                <select class="input" name="spot" id="f-spot">
                    <option value="NoSpot">不使用</option>
                    <option value="SpotWithPriceLimit">抢占式（设置价格上限）</option>
                </select>
            </label>
            <label class="field" id="f-spot-price-wrap">
                <span class="field-label">价格上限（元/时）</span>
                <input type="number" class="input" name="spot_price" value="0.5" min="0.001" step="0.001">
            </label>
        </div>

        <div class="cell-head cell-head-gap"><h3>磁盘</h3></div>
        <div class="form form-grid">
            <label class="field">
                <span class="field-label">系统盘类型</span>
                <select class="input" name="disk_category">
                    <option value="cloud_essd">ESSD 云盘</option>
                    <option value="cloud_ssd">SSD 云盘</option>
                    <option value="cloud_efficiency">高效云盘</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">系统盘大小（GB）</span>
                <input type="number" class="input" name="disk_size" value="40" min="20" max="500" required>
            </label>
            <label class="field">
                <span class="field-label">数据盘大小（GB，可选）</span>
                <input type="number" class="input" name="data_disk_size" value="0" min="0" max="32768">
            </label>
            <label class="field">
                <span class="field-label">数据盘类型</span>
                <select class="input" name="data_disk_category">
                    <option value="cloud_essd">ESSD 云盘</option>
                    <option value="cloud_ssd">SSD 云盘</option>
                    <option value="cloud_efficiency">高效云盘</option>
                </select>
            </label>
        </div>

        <div class="cell-head cell-head-gap"><h3>登录凭证</h3></div>
        <div class="form form-grid">
            <label class="field">
                <span class="field-label">SSH 密码</span>
                <input type="text" class="input" name="password" id="f-password" placeholder="留空则自动生成" autocomplete="new-password">
            </label>
            <label class="field">
                <span class="field-label">密钥对名称（可选）</span>
                <input type="text" class="input" name="key_pair" placeholder="如未配置请留空">
            </label>
        </div>
        <div class="form-foot">
            <button type="submit" class="btn btn-primary" data-submit-label="创建中…">创建实例</button>
        </div>
    </div>

    <aside class="cell span-4">
        <div class="panel">
            <div class="cell-head"><h3>创建说明</h3></div>
            <ul class="note-list">
                <li>创建需要账号具备 ECS 创建权限（RAM 最小权限见 README）。</li>
                <li>抢占式实例价格波动可能导致被回收，创建后建议在「任务」页配置保活。</li>
                <li>密码仅在本次响应中展示，请及时保存。</li>
                <li>创建成功后会自动同步实例缓存。</li>
            </ul>
        </div>
        <div class="panel">
            <div class="cell-head"><h3>自动生成的密码</h3></div>
            <p class="hint">留空密码时，面板会生成随机密码并在创建结果中显示一次。</p>
            <input type="text" class="input mono" id="generated-password" readonly placeholder="—">
        </div>
    </aside>
</form>
