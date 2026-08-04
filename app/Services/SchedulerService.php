<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/**
 * 调度服务：定时任务（开机/关机/保活）+ 流量熔断保护。
 */
final class SchedulerService
{
    /** 计算 5 段 cron 表达式（分 时 日 月 周）的下一次执行时间 */
    public static function cronNext(string $expr, int $from): ?int
    {
        $fields = preg_split('/\s+/', trim($expr));
        if (count($fields) !== 5) {
            return null;
        }
        $specs = [
            self::parseField($fields[0], 0, 59),
            self::parseField($fields[1], 0, 23),
            self::parseField($fields[2], 1, 31),
            self::parseField($fields[3], 1, 12),
            self::parseField($fields[4], 0, 7),
        ];
        if (in_array(null, $specs, true)) {
            return null;
        }
        for ($t = $from + 60; $t < $from + 366 * 86400; $t += 60) {
            if (in_array((int)date('i', $t), $specs[0], true)
                && in_array((int)date('G', $t), $specs[1], true)
                && in_array((int)date('j', $t), $specs[2], true)
                && in_array((int)date('n', $t), $specs[3], true)
                && in_array((int)date('w', $t) === 0 ? 7 : (int)date('w', $t), $specs[4], true)) {
                return $t;
            }
        }
        return null;
    }

    /** 解析 cron 字段为允许值数组 */
    private static function parseField(string $field, int $min, int $max): ?array
    {
        if ($field === '*' || $field === '?') {
            return range($min, $max);
        }
        $values = [];
        foreach (explode(',', $field) as $part) {
            if (preg_match('#^(\*|\d+)(?:/(\d+))?$#', $part, $m)) {
                $step = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 1;
                $range = $m[1] === '*' ? range($min, $max) : [(int)$m[1]];
                foreach ($range as $v) {
                    if ($v >= $min && $v <= $max && $v % $step === 0) {
                        $values[] = $v;
                    }
                }
            } elseif (preg_match('#^(\d+)-(\d+)(?:/(\d+))?$#', $part, $m)) {
                $a = (int)$m[1];
                $b = (int)$m[2];
                $step = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 1;
                for ($v = $a; $v <= $b; $v += $step) {
                    if ($v >= $min && $v <= $max) {
                        $values[] = $v;
                    }
                }
            } else {
                return null;
            }
        }
        return array_values(array_unique($values));
    }

    /** 全量运行：定时任务 + 流量熔断，带进程锁 */
    public static function run(): array
    {
        $lockFile = storage_path('scheduler.lock');
        $fp = @fopen($lockFile, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            return ['busy' => true, 'tasks' => 0, 'stopped' => 0, 'notified' => 0, 'errors' => []];
        }
        $started = microtime(true);
        $errors = [];
        try {
            $taskResult = self::runTasks($errors);
            $monthlyStarted = self::runMonthlyPowerOn($errors);
            $autoKeepalive = self::runAutoKeepAlive($errors);
            $guardResult = self::runGuard($errors);
            $costSynced = self::runCostSync($errors);
            $result = [
                'busy' => false,
                'tasks' => $taskResult,
                'monthly_started' => $monthlyStarted,
                'auto_keepalive' => $autoKeepalive,
                'stopped' => $guardResult['stopped'],
                'notified' => $guardResult['notified'] + $autoKeepalive,
                'cost_synced' => $costSynced,
                'errors' => $errors,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
            set_setting('scheduler_last_run', date('Y-m-d H:i:s'));
            set_setting('scheduler_last_result', json_encode($result, JSON_UNESCAPED_UNICODE));
            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function runTasks(array &$errors): int
    {
        $now = time();
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM tasks WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?) ORDER BY id'
        );
        $stmt->execute([date('Y-m-d H:i:s', $now)]);
        $tasks = $stmt->fetchAll();
        $done = 0;
        foreach ($tasks as $task) {
            try {
                self::executeTask($task);
                $result = 'ok';
            } catch (\Throwable $e) {
                $errors[] = $task['name'] . '：' . $e->getMessage();
                $result = '错误：' . $e->getMessage();
                log_event('task', 'error', "任务「{$task['name']}」执行失败", $e->getMessage());
            }
            $next = self::cronNext((string)$task['cron_expr'], $now);
            Db::pdo()->prepare(
                'UPDATE tasks SET last_run_at = ?, last_result = ?, next_run_at = ? WHERE id = ?'
            )->execute([date('Y-m-d H:i:s'), $result, $next ? date('Y-m-d H:i:s', $next) : null, (int)$task['id']]);
            $done++;
        }
        return $done;
    }

    public static function executeTask(array $task): void
    {
        $accountId = (int)$task['account_id'];
        $instanceId = (string)$task['instance_id'];
        switch ($task['kind']) {
            case 'power_on':
            case 'power_off':
                if ($accountId <= 0 || $instanceId === '') {
                    throw new \RuntimeException('缺少账号或实例');
                }
                EcsService::operate($task['kind'] === 'power_on' ? 'start' : 'stop', $accountId, $instanceId);
                break;

            case 'keepalive':
                if ($accountId <= 0) {
                    throw new \RuntimeException('缺少账号');
                }
                self::keepAlive($task, $accountId);
                break;

            default:
                throw new \RuntimeException('未知任务类型：' . $task['kind']);
        }
    }

    /** 抢占式实例保活：按 recipe 标签检查实例是否存在，缺失则重建 */
    private static function keepAlive(array $task, int $accountId): void
    {
        $payload = json_decode((string)$task['payload_json'], true) ?: [];
        $recipeId = (string)($payload['recipe_id'] ?? '');
        $account = EcsService::account($accountId);
        if ($account === null) {
            throw new \RuntimeException('账号不存在');
        }
        // 先实时同步实例缓存，确保能第一时间发现阿里云回收/释放（缓存不再依赖手动同步）
        EcsService::syncInstances($account);

        // 无重建参数（非面板创建的实例）：降级为“仅拉起”模式
        if ($recipeId === '') {
            self::keepAliveStartOnly($task, $accountId, $account);
            return;
        }

        $existing = EcsService::findByRecipe($accountId, $recipeId);
        if ($existing !== null) {
            $row = EcsService::instanceByRemoteId($accountId, (string)$existing['instance_id']);
            // 实例已被释放/过期：视为丢失，走重建
            if ($row !== null && in_array($row['status'], ['Released', 'Expired', 'Deleted'], true)) {
                $existing = null;
            }
        }
        if ($existing !== null) {
            // 实例还在：若已停止则尝试拉起
            $row = EcsService::instanceByRemoteId($accountId, (string)$existing['instance_id']);
            if ($row !== null && in_array($row['status'], ['Stopped', 'Starting', 'Pending'], true)) {
                $wasStopped = $row['status'] === 'Stopped';
                EcsService::operate('start', $accountId, (string)$existing['instance_id']);
                log_event('task', 'info', "保活任务「{$task['name']}」已拉起实例",
                    (string)$existing['instance_id'], $accountId, (string)$existing['instance_id']);
                if ($wasStopped) {
                    $title = '抢占式实例已自动开机';
                    $message = "任务：{$task['name']}\n账号：{$account['name']}\n实例：{$existing['instance_id']}\n"
                        . "实例此前已停止，保活任务已自动拉起开机。";
                    NotificationService::send($title, $message);
                    log_event('task', 'info', $title, $message, $accountId, (string)$existing['instance_id']);
                }
            }
            return;
        }
        $recipe = $payload['recipe'] ?? null;
        if (!is_array($recipe)) {
            throw new \RuntimeException('保活任务缺少重建参数');
        }
        $newId = EcsService::create($account, $recipe);
        $title = "抢占式实例已自动重建";
        $message = "任务：{$task['name']}\n账号：{$account['name']}\n新实例：{$newId}";
        NotificationService::send($title, $message);
        log_event('task', 'success', $title, $message, $accountId, $newId);
    }

    /** 无重建参数的保活：实例存在则停止后自动拉起；已释放则发通知提醒（无法重建） */
    private static function keepAliveStartOnly(array $task, int $accountId, array $account): void
    {
        $instanceId = (string)$task['instance_id'];
        if ($instanceId === '') {
            throw new \RuntimeException('保活任务缺少实例');
        }
        $row = EcsService::instanceByRemoteId($accountId, $instanceId);
        if ($row === null) {
            $title = '抢占式实例已释放，无法自动重建';
            $message = "任务：{$task['name']}\n账号：{$account['name']}\n实例：{$instanceId}\n"
                . "该实例不是由面板创建（未保存重建参数），被回收后无法自动重建，请手动处理。";
            NotificationService::send($title, $message);
            log_event('task', 'warn', $title, $message, $accountId, $instanceId);
            return;
        }
        if ($row['status'] === 'Stopped') {
            EcsService::operate('start', $accountId, $instanceId);
            $title = '抢占式实例已自动开机';
            $message = "任务：{$task['name']}\n账号：{$account['name']}\n实例：{$instanceId}\n"
                . "实例此前已停止，保活任务已自动拉起。";
            NotificationService::send($title, $message);
            log_event('task', 'info', $title, $message, $accountId, $instanceId);
        }
    }

    /** 每月 1 号自动开机：对开启该开关且处于停止状态的实例执行开机并通知 */
    private static function runMonthlyPowerOn(array &$errors): int
    {
        $month = date('Y-m');
        if (date('j') !== '1' || (string)setting('monthly_power_on_' . $month, '') === '1') {
            return 0;
        }
        $rows = Db::pdo()->query(
            "SELECT i.*, a.name AS account_name FROM instances i
             JOIN accounts a ON a.id = i.account_id
             WHERE i.auto_power_on_monthly = 1 AND i.status = 'Stopped'"
        )->fetchAll();
        $started = 0;
        foreach ($rows as $it) {
            try {
                EcsService::operate('start', (int)$it['account_id'], (string)$it['instance_id']);
                $title = '每月 1 号自动开机';
                $message = "实例：{$it['instance_name']}（{$it['account_name']}）\n"
                    . "实例ID：{$it['instance_id']}\n已自动开机。";
                NotificationService::send($title, $message);
                log_event('task', 'info', $title, $message, (int)$it['account_id'], (string)$it['instance_id']);
                $started++;
            } catch (\Throwable $e) {
                $errors[] = $it['instance_name'] . '：' . $e->getMessage();
            }
        }
        set_setting('monthly_power_on_' . $month, '1');
        return $started;
    }

    /** 自动保活：账号开启开关后，每轮调度自动检查该账号下所有抢占式实例 */
    private static function runAutoKeepAlive(array &$errors): int
    {
        $handled = 0;
        foreach (EcsService::enabledAccounts() as $account) {
            if (!AccountConfig::keepaliveAuto((int)$account['id'])) {
                continue;
            }
            try {
                EcsService::syncInstances($account);
                $handled += self::autoKeepAliveAccount($account);
            } catch (\Throwable $e) {
                $errors[] = $account['name'] . ' 自动保活失败：' . $e->getMessage();
            }
        }
        return $handled;
    }

    /** 单个账号的自动保活检查：存在则拉起，已释放则重建（有参数）或通知（无参数） */
    private static function autoKeepAliveAccount(array $account): int
    {
        $accountId = (int)$account['id'];
        $live = [];
        foreach (EcsService::instances(['account_id' => $accountId]) as $it) {
            $live[$it['instance_id']] = $it;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM spot_instances WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $count = 0;
        foreach ($stmt->fetchAll() as $sp) {
            $iid = (string)$sp['instance_id'];
            if (isset($live[$iid])) {
                // 实例仍在：停止则自动拉起
                $it = $live[$iid];
                if ($it['status'] === 'Stopped') {
                    EcsService::operate('start', $accountId, $iid);
                    $title = '抢占式实例已自动开机（自动保活）';
                    $message = "账号：{$account['name']}\n实例：{$it['instance_name']}（{$iid}）\n实例此前已停止，自动保活已将其拉起。";
                    NotificationService::send($title, $message);
                    log_event('task', 'info', $title, $message, $accountId, $iid);
                    $count++;
                }
                continue;
            }
            // 实例已释放/删除
            $recipe = json_decode((string)$sp['recipe_json'], true);
            if (is_array($recipe) && !empty($recipe['recipe_id'])) {
                try {
                    $newId = EcsService::create($account, $recipe);
                    Db::pdo()->prepare('DELETE FROM spot_instances WHERE account_id = ? AND instance_id = ?')
                        ->execute([$accountId, $iid]);
                    $title = '抢占式实例已自动重建（自动保活）';
                    $message = "账号：{$account['name']}\n原实例：{$iid}\n新实例：{$newId}\n已按保存的配置自动重建。";
                    NotificationService::send($title, $message);
                    log_event('task', 'success', $title, $message, $accountId, $newId);
                    $count++;
                } catch (\Throwable $e) {
                    log_event('task', 'error', '自动保活重建失败',
                        $iid . '：' . $e->getMessage(), $accountId, $iid);
                }
            } else {
                // 无重建参数：通知一次后移除跟踪，避免重复提醒
                $title = '抢占式实例已释放，无法自动重建（自动保活）';
                $message = "账号：{$account['name']}\n实例：{$iid}\n该实例未保存重建参数，请手动处理。";
                NotificationService::send($title, $message);
                log_event('task', 'warn', $title, $message, $accountId, $iid);
                Db::pdo()->prepare('DELETE FROM spot_instances WHERE account_id = ? AND instance_id = ?')
                    ->execute([$accountId, $iid]);
                $count++;
            }
        }
        return $count;
    }

    /** 成本分析同步：仅同步开启开关的账号，默认 6 小时内不重复调用 */
    private static function runCostSync(array &$errors): int
    {
        $synced = 0;
        foreach (EcsService::enabledAccounts() as $account) {
            if (!CostService::enabled((int)$account['id'])) {
                continue;
            }
            try {
                if (CostService::syncStale($account)) {
                    $synced++;
                }
            } catch (\Throwable $e) {
                $errors[] = $account['name'] . ' 成本同步失败：' . $e->getMessage();
            }
        }
        return $synced;
    }

    /** 流量熔断保护：账号/实例用量达到阈值时自动关机并通知 */
    private static function runGuard(array &$errors): array
    {
        $result = ['stopped' => 0, 'notified' => 0];
        $accounts = EcsService::enabledAccounts();
        $now = time();
        $month = date('Y-m');

        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            // 按账号独立配置；未单独设置时回退全局默认
            $cfg = AccountConfig::guardConfig($accountId);
            if ((string)($cfg['enabled'] ?? '1') !== '1') {
                continue;
            }
            $cooldownMin = max(1, (int)$cfg['cooldown_min']);
            $warnPct = max(50, min(99, (int)$cfg['warn_pct']));
            $accountThresholdGb = max(1, (float)$cfg['threshold_gb']);
            // 熔断判断前按需刷新 CDT 账号级出网流量（10 分钟内不重复调用）
            try {
                TrafficService::syncCdtIfStale($account);
            } catch (\Throwable $e) {
                $errors[] = $account['name'] . ' CDT 刷新失败：' . $e->getMessage();
            }
            $used = TrafficService::accountUsage($accountId);
            $quotaGb = max(0, (float)$account['quota_gb']);
            $limitBytes = $quotaGb > 0 ? $quotaGb * TrafficService::GB : $accountThresholdGb * TrafficService::GB;
            $pct = $limitBytes > 0 ? $used / $limitBytes : 0;
            $instances = EcsService::instances(['account_id' => $accountId]);
            $stoppedIds = [];

            // 单实例阈值：独立于账号总量判断
            foreach ($instances as $it) {
                if ((int)$it['auto_shutdown'] !== 1) {
                    continue;
                }
                if (!in_array($it['status'], ['Running', 'Starting'], true)) {
                    continue;
                }
                if ($it['traffic_limit_gb'] === null || (float)$it['traffic_limit_gb'] <= 0) {
                    continue;
                }
                $instUsed = TrafficService::instanceUsage($accountId, (string)$it['instance_id']);
                if ($instUsed >= (float)$it['traffic_limit_gb'] * TrafficService::GB) {
                    $cdKey = 'guard_cd_inst_' . $it['instance_id'];
                    if ((int)setting($cdKey, 0) > $now) {
                        continue;
                    }
                    EcsService::operate('stop', $accountId, (string)$it['instance_id']);
                    set_setting($cdKey, (string)($now + $cooldownMin * 60));
                    $stoppedIds[$it['instance_id']] = true;
                    $result['stopped']++;
                    $title = '单实例流量阈值熔断';
                    $message = "实例：{$it['instance_name']}（{$account['name']}）\n"
                        . '本月流量：' . fmt_gb($instUsed) . ' GB\n'
                        . '阈值：' . number_format((float)$it['traffic_limit_gb'], 2) . " GB\n"
                        . "已自动关机，冷却 {$cooldownMin} 分钟";
                    NotificationService::send($title, $message);
                    log_event('guard', 'warn', $title, $message, $accountId, (string)$it['instance_id']);
                    $result['notified']++;
                }
            }

            // 用量预警（每个自然月提醒一次）
            if ($pct >= $warnPct / 100) {
                $warnKey = 'guard_warn_' . $accountId;
                if ((string)setting($warnKey, '') !== $month) {
                    set_setting($warnKey, $month);
                    $title = '流量用量预警';
                    $message = "账号：{$account['name']}\n"
                        . '本月已用：' . fmt_gb($used) . ' GB / ' . number_format($quotaGb > 0 ? $quotaGb : $accountThresholdGb, 0) . " GB\n"
                        . '占比：' . fmt_pct($used, $limitBytes);
                    NotificationService::send($title, $message);
                    $result['notified']++;
                }
            }

            // 达到 100% 触发熔断（按账号冷却时间防抖）
            if ($pct < 1.0) {
                continue;
            }
            $cdKey = 'guard_cd_' . $accountId;
            $cdUntil = (int)setting($cdKey, 0);
            if ($cdUntil > $now) {
                continue;
            }
            $stopped = [];
            foreach ($instances as $it) {
                if ((int)$it['auto_shutdown'] !== 1) {
                    continue;
                }
                if (!in_array($it['status'], ['Running', 'Starting'], true)) {
                    continue;
                }
                if (isset($stoppedIds[$it['instance_id']])) {
                    continue;
                }
                $stopped[] = $it['instance_name'];
                EcsService::operate('stop', $accountId, (string)$it['instance_id']);
                $result['stopped']++;
            }
            set_setting($cdKey, (string)($now + $cooldownMin * 60));
            $title = '流量熔断：账号 ' . $account['name'] . ' 已自动关机';
            $message = "账号：{$account['name']}\n"
                . '本月用量：' . fmt_gb($used) . ' GB（' . fmt_pct($used, $limitBytes) . "）\n"
                . '已停止实例：' . (count($stopped) > 0 ? implode('、', $stopped) : '无（实例均在保护名单外）') . "\n"
                . "冷却时间：{$cooldownMin} 分钟";
            NotificationService::send($title, $message);
            log_event('guard', 'warn', $title, $message, $accountId);
            $result['notified']++;
        }
        return $result;
    }

    /** 各任务下一次执行时间（供界面展示） */
    public static function withNextRun(array $task): array
    {
        $next = self::cronNext((string)$task['cron_expr'], time());
        $task['next_human'] = $next ? date('Y-m-d H:i:s', $next) : '表达式无效';
        return $task;
    }
}
