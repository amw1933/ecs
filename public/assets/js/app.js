/* OpsDeck ECS 面板前端脚本（原生 JS，无依赖） */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';

    /* ---------------- 工具 ---------------- */

    function toast(message, type) {
        const root = document.getElementById('toast-root');
        if (!root) return;
        const el = document.createElement('div');
        el.className = 'toast toast-' + (type || 'info');
        el.textContent = message;
        root.appendChild(el);
        setTimeout(() => {
            el.classList.add('hide');
            setTimeout(() => el.remove(), 300);
        }, 3200);
    }

    async function fetchJson(url, opts) {
        opts = opts || {};
        const init = { method: opts.method || 'GET', headers: {} };
        if (opts.body) {
            init.method = 'POST';
            const fd = new FormData();
            Object.keys(opts.body).forEach((k) => fd.append(k, opts.body[k]));
            fd.append('_token', CSRF);
            init.body = fd;
        }
        const res = await fetch(url, init);
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            // 非 JSON 响应：通常是成功后的页面跳转
            window.location.href = res.url || url;
            throw new Error('__redirect__');
        }
        if (!res.ok || data.ok === false) {
            const err = new Error(data.message || '请求失败');
            err.data = data;
            throw err;
        }
        return data;
    }

    function confirmDialog(title, message) {
        return new Promise((resolve) => {
            const root = document.getElementById('modal-root');
            if (!root) { resolve(true); return; }
            const mask = document.createElement('div');
            mask.className = 'modal-mask';
            mask.innerHTML =
                '<div class="modal">' +
                '<h3></h3><p></p>' +
                '<div class="form-foot">' +
                '<button type="button" class="btn btn-ghost" data-act="cancel">取消</button>' +
                '<button type="button" class="btn btn-danger" data-act="ok">确定</button>' +
                '</div></div>';
            mask.querySelector('h3').textContent = title;
            mask.querySelector('p').textContent = message;
            const close = (val) => { mask.remove(); resolve(val); };
            mask.querySelector('[data-act="cancel"]').onclick = () => close(false);
            mask.querySelector('[data-act="ok"]').onclick = () => close(true);
            mask.addEventListener('click', (e) => { if (e.target === mask) close(false); });
            root.appendChild(mask);
        });
    }

    /* ---------------- data-action 按钮 ---------------- */

    function bindActions() {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn || btn.disabled) return;

            const action = btn.dataset.action;
            const url = btn.dataset.url;
            const confirmMsg = btn.dataset.confirm;
            const body = {};
            btn.dataset.account && (body.account_id = btn.dataset.account);
            btn.dataset.instance && (body.instance_id = btn.dataset.instance);
            btn.dataset.id && (body.id = btn.dataset.id);
            btn.dataset.channel && (body.channel = btn.dataset.channel);
            btn.dataset.force && (body.force = btn.dataset.force);

            if (confirmMsg) {
                const ok = await confirmDialog('请确认操作', confirmMsg);
                if (!ok) return;
            }

            btn.disabled = true;
            const original = btn.textContent;
            btn.textContent = '处理中…';
            try {
                const data = await fetchJson(url, { body });
                toast(data.message || '操作成功', 'ok');
                if (btn.dataset.reload !== '0') {
                    setTimeout(() => window.location.reload(), 900);
                }
            } catch (err) {
                if (err.message === '__redirect__') return;
                toast(err.message || '操作失败', 'err');
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        });
    }

    /* ---------------- data-ajax-form ---------------- */

    function bindForms() {
        document.addEventListener('submit', async (e) => {
            const form = e.target.closest('form[data-ajax-form]');
            if (!form) return;
            e.preventDefault();
            const url = form.dataset.url;
            const submitBtn = form.querySelector('button[type="submit"]');
            const body = {};
            new FormData(form).forEach((v, k) => { body[k] = v; });
            if (submitBtn) {
                submitBtn.disabled = true;
                const original = submitBtn.textContent;
                submitBtn.textContent = submitBtn.dataset.submitLabel || '提交中…';
            }
            try {
                const data = await fetchJson(url, { body });
                toast(data.message || '已保存', 'ok');
                const pw = document.getElementById('generated-password');
                if (pw && data.data && data.data.password) {
                    pw.value = data.data.password;
                    toast('请立即保存自动生成的密码', 'info');
                }
                if (form.dataset.reload !== '0') {
                    setTimeout(() => window.location.reload(), form.dataset.after || 900);
                }
            } catch (err) {
                if (err.message === '__redirect__') return;
                toast(err.message || '保存失败', 'err');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = original;
                }
            }
        });
    }

    /* ---------------- 图表 ---------------- */

    function renderChart(el) {
        let labels = [];
        let series = [];
        try {
            labels = JSON.parse(el.dataset.labels || '[]');
            series = JSON.parse(el.dataset.series || '[]');
        } catch (e) { return; }
        if (!labels.length || !series.length) {
            el.innerHTML = '<p style="color:#5f7299;font-size:12.5px;text-align:center;padding-top:90px">暂无数据</p>';
            return;
        }

        let seriesList;
        if (Array.isArray(series[0])) {
            seriesList = series.map((vals, i) => ({
                name: '系列' + (i + 1),
                color: ['#4cc9f0', '#f4c95d', '#3ddc97'][i % 3],
                values: vals,
            }));
        } else {
            seriesList = series.map((s) => ({
                name: s.name || '系列',
                color: s.color || '#4cc9f0',
                values: s.values,
            }));
        }

        const W = 900;
        const H = 240;
        const padL = 52;
        const padR = 12;
        const padT = 14;
        const padB = 26;
        const iw = W - padL - padR;
        const ih = H - padT - padB;
        const n = labels.length;

        let max = 0;
        seriesList.forEach((s) => s.values.forEach((v) => { if (Number(v) > max) max = Number(v); }));
        if (max <= 0) max = 1;

        const yTicks = 4;
        const xStep = Math.max(1, Math.ceil(n / 8));
        const x = (i) => padL + (n === 1 ? iw / 2 : (i / (n - 1)) * iw);
        const y = (v) => padT + ih - (Number(v) / max) * ih;

        let svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">';
        for (let t = 0; t <= yTicks; t++) {
            const val = (max / yTicks) * t;
            const yy = y(val);
            svg += '<line class="grid-line" x1="' + padL + '" y1="' + yy + '" x2="' + (W - padR) + '" y2="' + yy + '"/>';
            svg += '<text class="axis-label" x="' + (padL - 8) + '" y="' + (yy + 3) + '" text-anchor="end">' + fmtGb(val) + '</text>';
        }
        for (let i = 0; i < n; i += xStep) {
            svg += '<text class="axis-label" x="' + x(i) + '" y="' + (H - 7) + '" text-anchor="middle">' + esc(labels[i]) + '</text>';
        }
        seriesList.forEach((s) => {
            const pts = s.values.map((v, i) => x(i) + ',' + y(v));
            const area = 'M' + x(0) + ',' + y(s.values[0]) +
                ' L' + pts.join(' L') +
                ' L' + x(n - 1) + ',' + (padT + ih) +
                ' L' + x(0) + ',' + (padT + ih) + ' Z';
            svg += '<path class="series-area" d="' + area + '" fill="' + s.color + '"/>';
            svg += '<polyline class="series-line" points="' + pts.join(' ') + '" stroke="' + s.color + '"/>';
        });
        if (seriesList.length > 1) {
            let lx = padL + 8;
            seriesList.forEach((s) => {
                svg += '<circle class="legend-dot" cx="' + (lx + 4) + '" cy="8" r="3.5" fill="' + s.color + '"/>';
                svg += '<text class="legend-text" x="' + (lx + 12) + '" y="12">' + esc(s.name) + '</text>';
                lx += 16 + s.name.length * 12 + 18;
            });
        }
        svg += '</svg>';
        el.innerHTML = svg;
    }

    function fmtGb(bytes) {
        const gb = Number(bytes) / 1073741824;
        if (gb >= 100) return Math.round(gb) + 'G';
        if (gb >= 10) return gb.toFixed(0) + 'G';
        if (gb >= 0.1) return gb.toFixed(1) + 'G';
        return (Number(bytes) / 1048576).toFixed(0) + 'M';
    }

    function esc(s) {
        return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    }

    function bindCharts() {
        document.querySelectorAll('.chart[data-labels]').forEach(renderChart);
    }

    /* ---------------- 总览轮询 ---------------- */

    function bindStatsPoll() {
        const refreshBtn = document.querySelector('[data-refresh-stats]');
        if (!refreshBtn) return;
        const tick = async () => {
            try {
                const data = await fetchJson(refreshBtn.dataset.url, {});
                ['accounts', 'instances', 'running', 'alerts'].forEach((k) => {
                    const el = document.querySelector('[data-stat="' + k + '"]');
                    if (el && data.data && data.data[k] !== undefined) el.textContent = data.data[k];
                });
            } catch (e) { /* 忽略轮询错误 */ }
        };
        setInterval(tick, 60000);
    }

    /* ---------------- 标签页 ---------------- */

    function bindTabs() {
        document.querySelectorAll('[data-tabs]').forEach((tabs) => {
            tabs.addEventListener('click', (e) => {
                const tab = e.target.closest('.tab');
                if (!tab) return;
                tabs.querySelectorAll('.tab').forEach((t) => t.classList.remove('active'));
                tab.classList.add('active');
                const key = tab.dataset.tab;
                document.querySelectorAll('[data-panel]').forEach((p) => {
                    p.classList.toggle('active', p.dataset.panel === key);
                });
            });
        });
    }

    /* ---------------- 任务表单联动 ---------------- */

    function bindTaskKind() {
        const kind = document.getElementById('task-kind');
        if (!kind) return;
        const hint = document.getElementById('task-kind-hint');
        const cronWrap = document.getElementById('task-cron-wrap');
        const instanceWrap = document.getElementById('task-instance-wrap');
        const cronInput = cronWrap.querySelector('input[name="cron_expr"]');
        const sync = () => {
            const v = kind.value;
            if (v === 'keepalive') {
                cronWrap.querySelector('.field-label').textContent = '检查间隔（cron 表达式）';
                if (cronInput && cronInput.value === '0 3 * * *') {
                    cronInput.value = '*/1 * * * *';
                }
                instanceWrap.querySelector('.field-label').textContent = '实例（要保活的实例）';
                hint.textContent = '保活任务定期检查实例：面板创建的实例被释放后自动按原配置重建；非面板创建的实例仅支持停止后自动拉起，释放后会发通知提醒。建议每分钟 */1 * * * * 或每 3 分钟 */3 * * * *。';
            } else {
                cronWrap.querySelector('.field-label').textContent = 'cron 表达式（分 时 日 月 周）';
                instanceWrap.querySelector('.field-label').textContent = '实例';
                hint.textContent = '示例：0 3 * * * 每天 03:00 · */30 * * * * 每 30 分钟 · 30 8 * * 1-5 工作日 08:30';
            }
        };
        kind.addEventListener('change', sync);
        sync();
    }

    /* ---------------- 任务表单：实例按账号过滤 ---------------- */

    function bindTaskAccount() {
        const account = document.getElementById('task-account');
        const wrap = document.getElementById('task-instance-wrap');
        if (!account || !wrap) return;
        const sel = wrap.querySelector('select[name="instance_id"]');
        if (!sel) return;
        const opts = Array.from(sel.options);
        const sync = () => {
            const aid = account.value;
            let visible = 0;
            opts.forEach((o) => {
                const show = o.dataset.account === aid || aid === '';
                o.hidden = !show;
                if (show) visible++;
            });
            const selected = sel.selectedOptions[0];
            if (!selected || selected.hidden) {
                const first = opts.find((o) => !o.hidden);
                sel.value = first ? first.value : '';
            }
            wrap.style.display = visible > 0 ? '' : 'none';
        };
        account.addEventListener('change', sync);
        sync();
    }

    /* ---------------- 创建实例联动 ---------------- */

    function bindCreateForm() {
        const account = document.getElementById('f-account');
        const region = document.getElementById('f-region');
        const zone = document.getElementById('f-zone');
        const type = document.getElementById('f-type');
        const image = document.getElementById('f-image');
        const sg = document.getElementById('f-sg');
        const vswitch = document.getElementById('f-vswitch');
        const charge = document.getElementById('f-charge');
        const spot = document.getElementById('f-spot');
        if (!account || !region) return;

        const fill = (sel, items, label) => {
            sel.innerHTML = '<option value="">' + label + '</option>';
            items.forEach((it) => {
                const opt = document.createElement('option');
                opt.value = it.id;
                const extra = it.cpu ? ' (' + it.cpu + 'C/' + it.memory + 'G)' : (it.os ? ' · ' + it.os : (it.name ? ' · ' + it.name : ''));
                opt.textContent = it.id + (extra || '');
                sel.appendChild(opt);
            });
        };

        const loadOptions = async (kind, params, sel, label) => {
            try {
                const q = new URLSearchParams({
                    page: 'api', action: 'options',
                    account: account.value, kind: kind, region: region.value,
                });
                if (params) Object.keys(params).forEach((k) => q.append(k, params[k]));
                const data = await fetchJson('?' + q.toString(), {});
                fill(sel, data.data.items || [], label);
            } catch (e) {
                if (e.message !== '__redirect__') {
                    sel.innerHTML = '<option value="">加载失败</option>';
                }
            }
        };

        const reloadAll = () => {
            loadOptions('zones', {}, zone, '自动分配');
            loadOptions('types', {}, type, '请先选择地域');
            loadOptions('images', {}, image, '请先选择地域');
            loadOptions('security_groups', {}, sg, '请先选择地域');
            loadOptions('vswitches', {}, vswitch, '不指定');
        };

        const reloadRegions = () => {
            loadOptions('regions', {}, region, '选择地域').then(() => {
                if (region.value) reloadAll();
            });
        };

        account.addEventListener('change', reloadRegions);
        region.addEventListener('change', reloadAll);
        zone.addEventListener('change', () => {
            loadOptions('vswitches', { zone: zone.value }, vswitch, '不指定');
        });
        charge.addEventListener('change', () => {
            document.getElementById('f-period-wrap').style.display = charge.value === 'prepaid' ? '' : 'none';
        });
        spot.addEventListener('change', () => {
            document.getElementById('f-spot-price-wrap').style.display = spot.value === 'SpotWithPriceLimit' ? '' : 'none';
        });

        charge.dispatchEvent(new Event('change'));
        spot.dispatchEvent(new Event('change'));
        reloadRegions();
    }

    /* ---------------- 演示账号开关 ---------------- */

    function bindDemoToggle() {
        const demo = document.getElementById('f-demo');
        if (!demo) return;
        const sync = () => {
            const on = demo.checked;
            document.getElementById('f-ak-wrap').style.display = on ? 'none' : '';
            document.getElementById('f-sk-wrap').style.display = on ? 'none' : '';
            const ak = document.querySelector('#account-form [name="access_key_id"]');
            const sk = document.querySelector('#account-form [name="access_key_secret"]');
            ak.required = !on;
            sk.required = !on;
            if (on) { ak.value = ''; sk.value = ''; }
        };
        demo.addEventListener('change', sync);
    }

    /* ---------------- 账号编辑 ---------------- */

    function bindAccountEdit() {
        const form = document.getElementById('account-form');
        if (!form) return;
        const mode = document.getElementById('account-form-mode');
        const cancel = document.getElementById('account-form-cancel');
        const fields = ['name', 'access_key_id', 'access_key_secret', 'region', 'quota_gb', 'note'];
        document.querySelectorAll('[data-edit-account]').forEach((btn) => {
            btn.addEventListener('click', () => {
                form.dataset.url = '?page=api&action=account_save';
                form.querySelector('input[name="id"]').value = btn.dataset.id;
                fields.forEach((f) => {
                    const el = form.querySelector('[name="' + f + '"]');
                    if (el && btn.dataset[f] !== undefined) el.value = btn.dataset[f];
                });
                form.querySelector('[name="access_key_secret"]').value = '';
                mode.textContent = '编辑 #' + btn.dataset.id;
                cancel.classList.remove('hidden');
                form.querySelector('button[type="submit"]').textContent = '保存修改';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
        cancel.addEventListener('click', () => {
            form.reset();
            form.querySelector('input[name="id"]').value = '0';
            form.dataset.url = '?page=api&action=account_add';
            mode.textContent = '新增';
            cancel.classList.add('hidden');
            form.querySelector('button[type="submit"]').textContent = '保存账号';
        });
    }

    /* ---------------- 时钟与杂项 ---------------- */

    function bindClock() {
        const el = document.getElementById('clock-top');
        if (!el) return;
        const pad = (n) => String(n).padStart(2, '0');
        const tick = () => {
            const d = new Date();
            el.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
                ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        };
        tick();
        setInterval(tick, 1000);
    }

    function bindFlashClose() {
        document.querySelectorAll('[data-flash-close]').forEach((btn) => {
            btn.addEventListener('click', () => btn.closest('[data-flash]')?.remove());
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindActions();
        bindForms();
        bindCharts();
        bindStatsPoll();
        bindTabs();
        bindTaskKind();
        bindTaskAccount();
        bindCreateForm();
        bindDemoToggle();
        bindAccountEdit();
        bindClock();
        bindFlashClose();
    });
})();
