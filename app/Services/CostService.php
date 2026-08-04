<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/**
 * 成本分析（费用中心）服务。
 * 账号开启「成本分析」后，定期同步当月费用与账户余额并落库展示。
 */
final class CostService
{
    /** 账号是否开启成本分析 */
    public static function enabled(int $accountId): bool
    {
        return (string)Db::setting('cost_enabled_' . $accountId, '0') === '1';
    }

    public static function saveEnabled(int $accountId, bool $on): void
    {
        Db::setSetting('cost_enabled_' . $accountId, $on ? '1' : '0');
    }

    /** 同步单个账号成本；失败抛异常 */
    public static function syncAccount(array $account): array
    {
        $accountId = (int)$account['id'];
        $client = EcsService::clientForAccount($account);
        $data = $client->costSummary();
        $data['account_id'] = $accountId;
        Db::setSetting('cost_data_' . $accountId, json_encode($data, JSON_UNESCAPED_UNICODE));
        Db::setSetting('cost_synced_at_' . $accountId, (string)time());
        return $data;
    }

    /** 读取账号最近一次成本数据；未同步或未开启返回 null */
    public static function accountCost(int $accountId): ?array
    {
        $raw = (string)Db::setting('cost_data_' . $accountId, '');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** 全部真实账号成本（供页面展示） */
    public static function allAccountCosts(): array
    {
        $out = [];
        foreach (EcsService::allAccounts() as $a) {
            $id = (int)$a['id'];
            $out[$id] = [
                'enabled' => self::enabled($id),
                'data' => self::accountCost($id),
            ];
        }
        return $out;
    }

    /** 调度内按需刷新（默认 6 小时内不重复调用） */
    public static function syncStale(array $account, int $ttlSeconds = 21600): bool
    {
        $accountId = (int)$account['id'];
        if (!self::enabled($accountId) || (int)($account['is_demo'] ?? 0) === 1) {
            return false;
        }
        if (time() - (int)Db::setting('cost_synced_at_' . $accountId, 0) < $ttlSeconds) {
            return false;
        }
        self::syncAccount($account);
        return true;
    }
}
