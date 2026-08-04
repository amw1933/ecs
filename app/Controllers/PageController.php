<?php
declare(strict_types=1);
namespace App\Controllers;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;
use App\Services\EcsService;
use App\Services\SchedulerService;
use App\Services\TrafficService;

final class PageController
{
    public static function dashboard(): void
    {
        $pdo = Db::pdo();
        $accounts = EcsService::allAccounts();
        $instances = EcsService::instances();
        $running = 0;
        foreach ($instances as $it) {
            if (in_array($it['status'], ['Running', 'Starting', 'Pending'], true)) {
                $running++;
            }
        }
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM events WHERE level IN ('warn','error') AND ts >= date('now','-1 day')");
        $alerts = (int)$stmt->fetch()['c'];

        [$accountStats, $grandTotal] = TrafficService::allAccountStats();
        $quotaTotal = 0.0;
        foreach ($accounts as $a) {
            $quotaTotal += max(0, (float)$a['quota_gb']);
        }

        // 汇总流量曲线（全部账号）
        $series = [];
        $labels = [];
        for ($i = 29; $i >= 0; $i--) {
            $labels[] = date('m-d', strtotime('-' . $i . ' days'));
            $series[] = 0;
        }
        foreach ($accounts as $a) {
            foreach (TrafficService::accountSeries((int)$a['id'], 30) as $row) {
                $idx = 29 - (int)floor((strtotime(date('Y-m-d')) - strtotime($row['day'])) / 86400);
                if ($idx >= 0 && $idx < 30) {
                    $series[$idx] += (int)$row['total'];
                }
            }
        }

        $events = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 8')->fetchAll();
        $lastRun = (string)setting('scheduler_last_run', '从未运行');
        $lastResult = json_decode((string)setting('scheduler_last_result', '{}'), true) ?: [];

        render_page('dashboard', [
            'page' => 'dashboard',
            'pageTitle' => '总览',
            'accounts' => $accounts,
            'instances' => $instances,
            'instanceCount' => count($instances),
            'running' => $running,
            'alerts' => $alerts,
            'accountStats' => $accountStats,
            'grandTotal' => $grandTotal,
            'quotaTotal' => $quotaTotal,
            'chartLabels' => $labels,
            'chartSeries' => $series,
            'events' => $events,
            'lastRun' => $lastRun,
            'lastResult' => $lastResult,
            'guardEnabled' => setting('guard_enabled', '1'),
            'guardThreshold' => setting('guard_threshold_gb', 180),
        ]);
    }

    public static function instances(): void
    {
        $filters = [
            'account_id' => (int)query('account', 0),
            'status' => (string)query('status', ''),
            'search' => trim((string)query('q', '')),
        ];
        $instances = EcsService::instances($filters);
        $usage = [];
        foreach (EcsService::allAccounts() as $a) {
            foreach (TrafficService::usageByInstances((int)$a['id']) as $iid => $bytes) {
                $usage[$a['id'] . ':' . $iid] = $bytes;
            }
        }
        render_page('instances', [
            'page' => 'instances',
            'pageTitle' => '实例管理',
            'accounts' => EcsService::allAccounts(),
            'instances' => $instances,
            'usage' => $usage,
            'filters' => $filters,
        ]);
    }

    public static function instanceDetail(): void
    {
        $id = (int)query('id', 0);
        $instance = EcsService::instanceById($id);
        if ($instance === null) {
            flash('实例不存在或已被移除', 'err');
            redirect(url('?page=instances'));
        }
        $used = TrafficService::instanceUsage((int)$instance['account_id'], (string)$instance['instance_id']);
        $series = TrafficService::instanceSeries((int)$instance['account_id'], (string)$instance['instance_id'], 30);
        $labels = array_map(fn ($r) => substr($r['day'], 5), $series);
        $inSeries = array_map(fn ($r) => $r['in'], $series);
        $outSeries = array_map(fn ($r) => $r['out'], $series);
        $events = Db::pdo()->prepare('SELECT * FROM events WHERE instance_id = ? ORDER BY id DESC LIMIT 10');
        $events->execute([$instance['instance_id']]);

        // CPU 最近值（演示模式直接取；真实账号仅在手动刷新时可用）
        $cpu = null;
        if ((int)$instance['account_demo'] === 1) {
            $account = EcsService::account((int)$instance['account_id']);
            if ($account !== null) {
                $cpu = EcsService::clientForAccount($account)->cpuPoint((string)$instance['instance_id']);
            }
        }
        render_page('instance_detail', [
            'page' => 'instances',
            'pageTitle' => '实例详情',
            'instance' => $instance,
            'used' => $used,
            'cpu' => $cpu,
            'chartLabels' => $labels,
            'chartIn' => $inSeries,
            'chartOut' => $outSeries,
            'events' => $events->fetchAll(),
        ]);
    }

    public static function createInstance(): void
    {
        render_page('create_instance', [
            'page' => 'instances',
            'pageTitle' => '创建实例',
            'accounts' => EcsService::enabledAccounts(),
        ]);
    }

    public static function traffic(): void
    {
        [$stats, $grandTotal] = TrafficService::allAccountStats();
        $accountId = (int)query('account', 0);
        if ($accountId <= 0 && count($stats) > 0) {
            $accountId = (int)$stats[0]['account']['id'];
        }
        $series = [];
        $labels = [];
        $breakdown = [];
        if ($accountId > 0) {
            $rows = TrafficService::accountSeries($accountId, 30);
            foreach ($rows as $row) {
                $labels[] = substr($row['day'], 5);
                $series[] = $row['total'];
            }
            $breakdown = TrafficService::usageByInstances($accountId);
        }
        render_page('traffic', [
            'page' => 'traffic',
            'pageTitle' => '流量监控',
            'stats' => $stats,
            'grandTotal' => $grandTotal,
            'accountId' => $accountId,
            'labels' => $labels,
            'series' => $series,
            'breakdown' => $breakdown,
            'accounts' => EcsService::allAccounts(),
            'lastSync' => (string)setting('traffic_last_sync', '从未同步'),
        ]);
    }

    public static function tasks(): void
    {
        $rows = Db::pdo()->query('SELECT * FROM tasks ORDER BY id DESC')->fetchAll();
        $tasks = array_map(fn ($t) => SchedulerService::withNextRun($t), $rows);
        render_page('tasks', [
            'page' => 'tasks',
            'pageTitle' => '定时任务',
            'tasks' => $tasks,
            'accounts' => EcsService::allAccounts(),
            'instances' => EcsService::instances(),
        ]);
    }

    public static function accounts(): void
    {
        render_page('accounts', [
            'page' => 'accounts',
            'pageTitle' => '账号管理',
            'accounts' => EcsService::allAccounts(),
            'costs' => \App\Services\CostService::allAccountCosts(),
        ]);
    }

    public static function events(): void
    {
        $kind = (string)query('kind', '');
        $level = (string)query('level', '');
        $sql = 'SELECT * FROM events WHERE 1=1';
        $args = [];
        if ($kind !== '') {
            $sql .= ' AND kind = ?';
            $args[] = $kind;
        }
        if ($level !== '') {
            $sql .= ' AND level = ?';
            $args[] = $level;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        render_page('events', [
            'page' => 'events',
            'pageTitle' => '事件中心',
            'events' => $stmt->fetchAll(),
            'kinds' => Db::pdo()->query('SELECT DISTINCT kind FROM events ORDER BY kind')->fetchAll(\PDO::FETCH_COLUMN),
            'levels' => Db::pdo()->query('SELECT DISTINCT level FROM events ORDER BY level')->fetchAll(\PDO::FETCH_COLUMN),
            'filters' => ['kind' => $kind, 'level' => $level],
        ]);
    }

    public static function settings(): void
    {
        render_page('settings', [
            'page' => 'settings',
            'pageTitle' => '系统设置',
            'lastResult' => json_decode((string)setting('scheduler_last_result', '{}'), true) ?: [],
        ]);
    }
}
