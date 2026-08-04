<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/**
 * 按账号独立的配置（计费周期日、流量熔断参数）。
 * 账号未单独配置时回退到全局默认值，兼容旧版全局设置。
 */
final class AccountConfig
{
    /** 账号计费周期起始日（1~28）；未设置时使用全局 billing_cycle_day */
    public static function billingCycleDay(int $accountId): int
    {
        $own = (int)Db::setting('billing_cycle_day_' . $accountId, 0);
        if ($own >= 1 && $own <= 28) {
            return $own;
        }
        return max(1, min(28, (int)Db::setting('billing_cycle_day', 1)));
    }

    public static function saveBillingCycleDay(int $accountId, int $day): void
    {
        Db::setSetting('billing_cycle_day_' . $accountId, (string)max(1, min(28, $day)));
    }

    /** 账号熔断配置（默认继承全局设置） */
    public static function guardConfig(int $accountId): array
    {
        $defaults = [
            'enabled' => (string)Db::setting('guard_enabled', '1'),
            'threshold_gb' => (float)Db::setting('guard_threshold_gb', 180),
            'warn_pct' => (int)Db::setting('guard_warn_pct', 80),
            'cooldown_min' => (int)Db::setting('guard_cooldown_min', 120),
        ];
        $raw = (string)Db::setting('guard_cfg_' . $accountId, '');
        if ($raw === '') {
            return $defaults;
        }
        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) {
            return $defaults;
        }
        return array_merge($defaults, $cfg);
    }

    public static function saveGuardConfig(int $accountId, array $cfg): void
    {
        $data = [
            'enabled' => !empty($cfg['enabled']) ? 1 : 0,
            'threshold_gb' => max(1, (float)($cfg['threshold_gb'] ?? 180)),
            'warn_pct' => max(50, min(99, (int)($cfg['warn_pct'] ?? 80))),
            'cooldown_min' => max(1, (int)($cfg['cooldown_min'] ?? 120)),
        ];
        Db::setSetting('guard_cfg_' . $accountId, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** 账号是否开启“自动保活（所有抢占式实例）” */
    public static function keepaliveAuto(int $accountId): bool
    {
        return (string)Db::setting('keepalive_auto_' . $accountId, '0') === '1';
    }

    public static function saveKeepaliveAuto(int $accountId, bool $on): void
    {
        Db::setSetting('keepalive_auto_' . $accountId, $on ? '1' : '0');
    }
}
