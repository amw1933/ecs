<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/**
 * 流量统计服务。
 *
 * 口径说明：
 * - 账号级流量以 CDT（云数据传输）出网流量为锚，通过 ListCdtInternetTraffic 获取
 *   当前账单周期累计值（覆盖共享带宽包、共享流量包等计费口径）。
 * - 实例级流量仍按云监控（CMS）公网出入指标积分汇总。
 * - 账号用量取两者较大值（哪个用的多算哪个），阈值保护同样基于该口径。
 */
final class TrafficService
{
    public const GB = 1073741824;

    /** CDT 账号级流量的保留 instance_id（不映射任何真实实例） */
    public const CDT_SENTINEL = '__cdt__';

    /** 账号账单周期起点（本地时区时间戳）与今日起点（按账号计费周期日） */
    public static function billingWindow(int $accountId): array
    {
        $cycleDay = AccountConfig::billingCycleDay($accountId);
        $now = time();
        $today = (int)date('j', $now);
        $thisMonthStart = strtotime(date('Y-m-01 00:00:00', $now));
        if ($today >= $cycleDay) {
            $cycleStart = $thisMonthStart + ($cycleDay - 1) * 86400;
        } else {
            $prevMonthStart = strtotime('first day of last month 00:00:00', $now);
            $cycleStart = $prevMonthStart + ($cycleDay - 1) * 86400;
        }
        return [$cycleStart, $now];
    }

    public static function windowDayStart(int $accountId): string
    {
        [$start] = self::billingWindow($accountId);
        return date('Y-m-d', $start);
    }

    /** 同步单个实例的流量（按账单周期覆盖），返回写入的天数 */
    public static function syncInstance(array $account, array $instance): int
    {
        [$start, $end] = self::billingWindow((int)$account['id']);
        $client = EcsService::clientForAccount($account);
        $rates = $client->trafficRates(
            (string)$instance['instance_id'],
            gmdate('Y-m-d\TH:i:s\Z', $start - 86400),
            gmdate('Y-m-d\TH:i:s\Z', $end + 3600),
            (string)($instance['eip'] ?? ''),
            (string)($instance['region_id'] ?? '')
        );
        $upsert = Db::pdo()->prepare(
            'INSERT INTO traffic_daily (account_id, instance_id, day, in_bytes, out_bytes, total_bytes, src)
             VALUES (?,?,?,?,?,?,?)
             ON CONFLICT(account_id, instance_id, day) DO UPDATE SET
               in_bytes = excluded.in_bytes,
               out_bytes = excluded.out_bytes,
               total_bytes = excluded.total_bytes,
               src = excluded.src'
        );
        $count = 0;
        foreach ($rates as $day => $v) {
            if ($day < self::windowDayStart((int)$account['id']) || $day > date('Y-m-d')) {
                continue;
            }
            $in = (int)round($v['in'] ?? 0);
            $out = (int)round($v['out'] ?? 0);
            $upsert->execute([
                (int)$account['id'], (string)$instance['instance_id'], $day,
                $in, $out, $in + $out, (int)($account['is_demo'] ?? 0) === 1 ? 'mock' : 'cms',
            ]);
            $count++;
        }
        return $count;
    }

    public static function syncAll(array $account): array
    {
        $instances = EcsService::instances(['account_id' => (int)$account['id']]);
        $ok = 0;
        $errors = [];
        foreach ($instances as $it) {
            try {
                self::syncInstance($account, $it);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = $it['instance_name'] . '：' . $e->getMessage();
            }
        }
        // 账号级 CDT 出网流量（共享带宽包等计费口径，实例监控可能统计不到）
        try {
            self::syncCdt($account);
        } catch (\Throwable $e) {
            $errors[] = 'CDT：' . $e->getMessage();
        }
        Db::setSetting('traffic_last_sync', date('Y-m-d H:i:s'));
        return [$ok, $errors];
    }

    /**
     * 同步账号级 CDT 出网流量。
     *
     * 接口返回当前账单周期的累计值（不支持按天拆分），因此按“增量”落库：
     * 每次同步记录自上次同步以来的增量，累计增量等于接口总量；
     * 账单周期重置或首次同步时直接记录全量。
     */
    public static function syncCdt(array $account): int
    {
        $accountId = (int)$account['id'];
        $client = EcsService::clientForAccount($account);
        $current = max(0, $client->cdtTraffic());
        $lastKey = 'cdt_last_' . $accountId;
        $last = max(0, (int)Db::setting($lastKey, 0));
        $delta = $current >= $last ? $current - $last : $current;

        $pdo = Db::pdo();
        $upd = $pdo->prepare(
            'UPDATE traffic_daily
             SET out_bytes = out_bytes + ?, total_bytes = total_bytes + ?, src = \'cdt\'
             WHERE account_id = ? AND instance_id = ? AND day = ?'
        );
        $upd->execute([$delta, $delta, $accountId, self::CDT_SENTINEL, date('Y-m-d')]);
        if ($upd->rowCount() === 0) {
            $ins = $pdo->prepare(
                'INSERT INTO traffic_daily (account_id, instance_id, day, in_bytes, out_bytes, total_bytes, src)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $ins->execute([$accountId, self::CDT_SENTINEL, date('Y-m-d'), 0, $delta, $delta, 'cdt']);
        }
        Db::setSetting($lastKey, (string)$current);
        Db::setSetting('cdt_synced_at_' . $accountId, (string)time());
        return 1;
    }

    /** 熔断/调度前按需刷新 CDT（默认 10 分钟内不重复调用） */
    public static function syncCdtIfStale(array $account, int $ttlSeconds = 600): bool
    {
        $key = 'cdt_synced_at_' . (int)$account['id'];
        if (time() - (int)Db::setting($key, 0) < $ttlSeconds) {
            return false;
        }
        self::syncCdt($account);
        return true;
    }

    public static function instanceUsage(int $accountId, string $instanceId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COALESCE(SUM(total_bytes), 0) AS total FROM traffic_daily
             WHERE account_id = ? AND instance_id = ? AND day >= ? AND day <= ?'
        );
        $stmt->execute([$accountId, $instanceId, self::windowDayStart($accountId), date('Y-m-d')]);
        return (int)$stmt->fetch()['total'];
    }

    public static function accountUsage(int $accountId): int
    {
        // 账号用量取 CDT 总量与实例汇总的较大值
        return max(self::cdtUsage($accountId), self::instanceUsageSum($accountId));
    }

    /** 账号级 CDT 当前账单周期用量（字节） */
    public static function cdtUsage(int $accountId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COALESCE(SUM(total_bytes), 0) AS total FROM traffic_daily
             WHERE account_id = ? AND instance_id = ? AND day >= ? AND day <= ?'
        );
        $stmt->execute([$accountId, self::CDT_SENTINEL, self::windowDayStart($accountId), date('Y-m-d')]);
        return (int)$stmt->fetch()['total'];
    }

    /** 账号下全部实例汇总用量（字节，不含 CDT） */
    public static function instanceUsageSum(int $accountId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COALESCE(SUM(total_bytes), 0) AS total FROM traffic_daily
             WHERE account_id = ? AND instance_id <> ? AND day >= ? AND day <= ?'
        );
        $stmt->execute([$accountId, self::CDT_SENTINEL, self::windowDayStart($accountId), date('Y-m-d')]);
        return (int)$stmt->fetch()['total'];
    }

    /** 最近 N 天账号总流量序列 */
    public static function accountSeries(int $accountId, int $days = 30): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT day,
                    COALESCE(SUM(CASE WHEN instance_id = ? THEN total_bytes ELSE 0 END), 0) AS cdt,
                    COALESCE(SUM(CASE WHEN instance_id <> ? THEN total_bytes ELSE 0 END), 0) AS inst
             FROM traffic_daily
             WHERE account_id = ? AND day >= ?
             GROUP BY day ORDER BY day'
        );
        $stmt->execute([
            self::CDT_SENTINEL,
            self::CDT_SENTINEL,
            $accountId,
            date('Y-m-d', strtotime('-' . ($days - 1) . ' days')),
        ]);
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = max((int)$row['cdt'], (int)$row['inst']);
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = ['day' => $day, 'total' => $byDay[$day] ?? 0];
        }
        return $out;
    }

    /** 最近 N 天单实例流量序列（出入分开） */
    public static function instanceSeries(int $accountId, string $instanceId, int $days = 30): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT day, in_bytes, out_bytes FROM traffic_daily
             WHERE account_id = ? AND instance_id = ? AND day >= ?
             ORDER BY day'
        );
        $stmt->execute([$accountId, $instanceId, date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))]);
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = $row;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $row = $byDay[$day] ?? ['in_bytes' => 0, 'out_bytes' => 0];
            $out[] = ['day' => $day, 'in' => (int)$row['in_bytes'], 'out' => (int)$row['out_bytes']];
        }
        return $out;
    }

    /** 各实例本月用量（用于表格展示） */
    public static function usageByInstances(int $accountId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT instance_id, SUM(total_bytes) AS total FROM traffic_daily
             WHERE account_id = ? AND instance_id <> ? AND day >= ? AND day <= ?
             GROUP BY instance_id'
        );
        $stmt->execute([$accountId, self::CDT_SENTINEL, self::windowDayStart($accountId), date('Y-m-d')]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['instance_id']] = (int)$row['total'];
        }
        return $out;
    }

    /** 全账号流量汇总（总览页） */
    public static function allAccountStats(): array
    {
        $accounts = EcsService::allAccounts();
        $stats = [];
        $grandTotal = 0;
        foreach ($accounts as $a) {
            $accountId = (int)$a['id'];
            $cdt = self::cdtUsage($accountId);
            $inst = self::instanceUsageSum($accountId);
            $used = max($cdt, $inst);
            $quota = max(0, (float)$a['quota_gb']) * self::GB;
            $stats[] = [
                'account' => $a,
                'used' => $used,
                'cdt' => $cdt,
                'inst' => $inst,
                'quota' => $quota,
                'pct' => $quota > 0 ? $used / $quota : 0,
                'guard' => AccountConfig::guardConfig($accountId),
            ];
            $grandTotal += $used;
        }
        return [$stats, $grandTotal];
    }
}
