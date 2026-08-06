<?php
declare(strict_types=1);
namespace App\Controllers;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;
use App\Services\CostService;
use App\Services\EcsService;
use App\Services\NotificationService;
use App\Services\SchedulerService;
use App\Services\TrafficService;

final class ApiController
{
    private static array $postOnly = [
        'sync', 'account_add', 'account_delete', 'account_toggle', 'account_test',
        'instance_start', 'instance_stop', 'instance_reboot', 'instance_release',
        'instance_rule', 'instance_traffic_refresh', 'instance_create',
        'traffic_refresh', 'task_add', 'task_delete', 'task_toggle', 'task_run',
        'events_clear', 'settings_save', 'notify_test', 'scheduler_run',
        'traffic_reset_demo', 'clear_notify_log', 'account_save',
        'cost_toggle',
    ];

    public static function handle(string $action): void
    {
        if (in_array($action, self::$postOnly, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            csrf_check();
        }
        if (!method_exists(self::class, $action)) {
            json_error('未知操作：' . $action, 404);
        }
        self::$action();
    }

    /* ---------------- 总览 ---------------- */

    private static function dashboard_stats(): void
    {
        $pdo = Db::pdo();
        $instances = EcsService::instances();
        $running = 0;
        foreach ($instances as $it) {
            if (in_array($it['status'], ['Running', 'Starting', 'Pending'], true)) {
                $running++;
            }
        }
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM events WHERE level IN ('warn','error') AND ts >= date('now','-1 day')");
        json_ok([
            'accounts' => count(EcsService::allAccounts()),
            'instances' => count($instances),
            'running' => $running,
            'alerts' => (int)$stmt->fetch()['c'],
            'clock' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ---------------- 同步 ---------------- */

    private static function sync(): void
    {
        $accounts = EcsService::enabledAccounts();
        $ok = 0;
        $errors = [];
        foreach ($accounts as $a) {
            try {
                EcsService::syncInstances($a);
                [$tOk, $tErrors] = TrafficService::syncAll($a);
                foreach ($tErrors as $te) {
                    $errors[] = $a['name'] . '：' . $te;
                }
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = $a['name'] . '：' . $e->getMessage();
            }
        }
        log_event('instance', 'info', '实例与流量同步完成', "成功 {$ok} 个账号" . (count($errors) ? '，失败 ' . count($errors) . ' 个' : ''));
        json_ok(['ok_accounts' => $ok, 'failed' => count($errors), 'errors' => $errors], '实例与流量同步完成');
    }

      /* ---------------- 账号 ---------------- */

      private static function cost_toggle(): void
      {
          $id = (int)post('id', 0);
          $account = EcsService::account($id);
          if ($account === null) {
              json_error('账号不存在');
          }
          $on = !CostService::enabled($id);
          CostService::saveEnabled($id, $on);
          log_event('account', 'info', $on ? '已开启成本分析' : '已关闭成本分析',
              "账号：{$account['name']}", $id);
          if ($on) {
              try {
                  CostService::syncAccount($account);
              } catch (\Throwable $e) {
                  json_error('成本分析已开启，但首次同步失败：' . $e->getMessage(), 422);
              }
          }
          json_ok(['enabled' => $on ? 1 : 0], $on ? '成本分析已开启，正在同步费用…' : '成本分析已关闭');
      }

      private static function account_add(): void
    {
        $name = trim((string)post('name', ''));
        $isDemo = post('is_demo') === '1' ? 1 : 0;
        $ak = trim((string)post('access_key_id', ''));
        $sk = (string)post('access_key_secret', '');
        $region = trim((string)post('region', 'cn-hangzhou'));
        $quota = max(0, (float)post('quota_gb', 200));
        if ($name === '') {
            json_error('账号名称不能为空');
        }
        if ($isDemo !== 1) {
            if ($ak === '' || $sk === '') {
                json_error('AccessKey ID 与 AccessKey Secret 均为必填');
            }
            if (mb_strlen($sk) < 8) {
                json_error('AccessKey Secret 长度异常，请检查');
            }
        }
        $enc = $isDemo === 1 ? '' : \App\Services\Crypt::encrypt($sk);
        Db::pdo()->prepare(
            'INSERT INTO accounts (name, access_key_id, access_key_secret_enc, region, quota_gb, enabled, is_demo, note, created_at, updated_at)
             VALUES (?,?,?,?,?,1,?,?,?,?)'
          )->execute([$name, $ak, $enc, $region, $quota, $isDemo, (string)post('note', ''), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
          $accountId = (int)Db::pdo()->lastInsertId();
          // 新添加的真实账号默认开启自动保活（演示账号除外），可在「设置 → 账号设置」随时关闭
          if ($isDemo !== 1) {
              \App\Services\AccountConfig::saveKeepaliveAuto($accountId, true);
          }
          log_event('account', 'info', "已添加账号「{$name}」", $isDemo === 1 ? '演示账号' : 'Region：' . $region);
        $account = EcsService::account($accountId);
        if ($account !== null) {
            // 添加后自动同步实例缓存与本月流量
            try {
                EcsService::syncInstances($account);
                TrafficService::syncAll($account);
                $syncMsg = '，实例缓存已自动同步';
            } catch (\Throwable $e) {
                $syncMsg = '，实例自动同步失败：' . $e->getMessage();
                log_event('account', 'error', "账号「{$name}」自动同步失败", $e->getMessage(), $accountId);
            }
        }
        json_ok([], '账号已添加' . ($syncMsg ?? ''));
    }

    private static function account_save(): void
    {
        $id = (int)post('id', 0);
        $account = EcsService::account($id);
        if ($account === null) {
            json_error('账号不存在');
        }
        $name = trim((string)post('name', $account['name']));
        $region = trim((string)post('region', $account['region']));
        $quota = max(0, (float)post('quota_gb', $account['quota_gb']));
        $note = trim((string)post('note', $account['note']));
        $ak = trim((string)post('access_key_id', $account['access_key_id']));
        $sk = (string)post('access_key_secret', '');
        if ($name === '') {
            json_error('账号名称不能为空');
        }
        if ((int)$account['is_demo'] !== 1 && $ak === '') {
            json_error('AccessKey ID 不能为空');
        }
        $sql = 'UPDATE accounts SET name = ?, region = ?, quota_gb = ?, note = ?, access_key_id = ?, updated_at = ?';
        $args = [$name, $region, $quota, $note, $ak, date('Y-m-d H:i:s')];
        if ($sk !== '' && (int)$account['is_demo'] !== 1) {
            if (mb_strlen($sk) < 8) {
                json_error('AccessKey Secret 长度异常，请检查');
            }
            $sql .= ', access_key_secret_enc = ?';
            $args[] = \App\Services\Crypt::encrypt($sk);
        }
        $sql .= ' WHERE id = ?';
        $args[] = $id;
        Db::pdo()->prepare($sql)->execute($args);
        log_event('account', 'info', "已更新账号「{$name}」");
        json_ok([], '账号已更新');
    }

    private static function account_delete(): void
    {
        $id = (int)post('id', 0);
        $account = EcsService::account($id);
        if ($account === null) {
            json_error('账号不存在');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM traffic_daily WHERE account_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM instances WHERE account_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM tasks WHERE account_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            json_error('删除失败：' . $e->getMessage());
        }
        log_event('account', 'warn', "已删除账号「{$account['name']}」及其数据");
        json_ok([], '账号及其数据已删除');
    }

    private static function account_toggle(): void
    {
        $id = (int)post('id', 0);
        $account = EcsService::account($id);
        if ($account === null) {
            json_error('账号不存在');
        }
        $enabled = (int)$account['enabled'] === 1 ? 0 : 1;
        Db::pdo()->prepare('UPDATE accounts SET enabled = ?, updated_at = ? WHERE id = ?')
            ->execute([$enabled, date('Y-m-d H:i:s'), $id]);
        json_ok(['enabled' => $enabled], $enabled ? '账号已启用' : '账号已停用');
    }

    private static function account_test(): void
    {
        $id = (int)post('id', 0);
        $account = EcsService::account($id);
        if ($account === null) {
            json_error('账号不存在');
        }
        try {
            $client = EcsService::clientForAccount($account);
            $client->testConnection();
            Db::pdo()->prepare('UPDATE accounts SET last_test_at = ?, last_test_ok = 1, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            log_event('account', 'success', "账号「{$account['name']}」连接测试通过");
            json_ok([], '连接成功');
          } catch (\Throwable $e) {
              Db::pdo()->prepare('UPDATE accounts SET last_test_at = ?, last_test_ok = 0, updated_at = ? WHERE id = ?')
                  ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
              $msg = $e->getMessage();
              if (str_contains($msg, 'IncompleteSignature') || str_contains($msg, 'SignatureDoesNotMatch')) {
                  $msg .= '（通常是 AccessKey Secret 不正确：请检查是否抄写错误、首尾含空格或换行，或密钥已轮换）';
              } elseif (str_contains($msg, 'InvalidAccessKeyId')) {
                  $msg .= '（AccessKey ID 不存在，请检查）';
              } elseif (str_contains($msg, 'Forbidden')) {
                  $msg .= '（该密钥缺少所需权限，请检查 RAM 授权）';
              }
              json_error('连接失败：' . $msg, 422);
          }
      }

    /* ---------------- 实例操作 ---------------- */

    private static function runInstanceOp(string $op): void
    {
        $accountId = (int)post('account_id', 0);
        $instanceId = trim((string)post('instance_id', ''));
        $force = post('force') === '1';
        if ($accountId <= 0 || $instanceId === '') {
            json_error('参数不完整');
        }
          try {
              $label = EcsService::operate($op, $accountId, $instanceId, $force);
              json_ok([], "实例已{$label}");
          } catch (\Throwable $e) {
              $msg = $e->getMessage();
              if (preg_match('/IncorrectInstanceStatus|InvalidStatus|InvalidInstanceStatus|OperationDenied/i', $msg)) {
                  $msg .= '（实例当前状态不支持该操作：已停止的实例不能重启，请先启动；刚执行的操作请等待状态稳定后再试）';
              }
              json_error($msg, 422);
          }
      }

    private static function instance_start(): void
    {
        self::runInstanceOp('start');
    }

    private static function instance_stop(): void
    {
        self::runInstanceOp('stop');
    }

    private static function instance_reboot(): void
    {
        self::runInstanceOp('reboot');
    }

    private static function instance_release(): void
    {
        // 为防止误删服务器，释放功能已禁用
        json_error('释放功能已禁用（为防止误删服务器已移除）', 403);
    }

    private static function instance_rule(): void
    {
        $id = (int)post('id', 0);
        $instance = EcsService::instanceById($id);
        if ($instance === null) {
            json_error('实例不存在');
        }
          $limitRaw = trim((string)post('traffic_limit_gb', ''));
          $limit = $limitRaw === '' ? null : max(0, (float)$limitRaw);
          $auto = post('auto_shutdown') === '1' ? 1 : 0;
          $monthlyPowerOn = post('auto_power_on_monthly') === '1' ? 1 : 0;
          Db::pdo()->prepare(
            'UPDATE instances SET traffic_limit_gb = ?, auto_shutdown = ?, auto_power_on_monthly = ?, updated_at = ? WHERE id = ?'
          )->execute([$limit, $auto, $monthlyPowerOn, date('Y-m-d H:i:s'), $id]);
          json_ok([], '实例保护规则已保存');
      }

    private static function instance_traffic_refresh(): void
    {
        $id = (int)post('id', 0);
        $instance = EcsService::instanceById($id);
        if ($instance === null) {
            json_error('实例不存在');
        }
        $account = EcsService::account((int)$instance['account_id']);
        try {
            $days = TrafficService::syncInstance($account, $instance);
            json_ok(['days' => $days], '流量已刷新（' . $days . ' 天数据）');
        } catch (\Throwable $e) {
            json_error('流量同步失败：' . $e->getMessage(), 422);
        }
    }

    private static function instance_create(): void
    {
        $accountId = (int)post('account_id', 0);
        $account = EcsService::account($accountId);
        if ($account === null) {
            json_error('账号不存在');
        }
        try {
            $password = trim((string)post('password', ''));
            if ($password === '' && trim((string)post('key_pair', '')) === '') {
                $password = random_token(12) . 'aA1!';
            }
            $instanceId = EcsService::create($account, array_merge($_POST, ['password' => $password]));
            json_ok(['instance_id' => $instanceId, 'password' => $password], '实例创建请求已提交');
        } catch (\Throwable $e) {
            json_error('创建失败：' . $e->getMessage(), 422);
        }
    }

    /* ---------------- 选项 ---------------- */

    private static function options(): void
    {
        $kind = (string)query('kind', '');
        $region = (string)query('region', '');
        $zone = (string)query('zone', '');
        $accountId = (int)query('account', 0);
        if (!in_array($kind, ['regions', 'types', 'images', 'security_groups', 'vswitches', 'zones'], true)) {
            json_error('未知选项类型');
        }
        try {
            $data = EcsService::options($accountId, $kind, $region, $zone);
            json_ok(['items' => $data]);
        } catch (\Throwable $e) {
            json_error($e->getMessage(), 422);
        }
    }

    /* ---------------- 流量 ---------------- */

    private static function traffic_refresh(): void
    {
        $accountId = (int)post('account_id', 0);
        $account = EcsService::account($accountId);
        if ($account === null) {
            json_error('账号不存在');
        }
        [$ok, $errors] = TrafficService::syncAll($account);
        json_ok(['ok' => $ok, 'errors' => $errors], "流量同步完成：{$ok} 台实例");
    }

    private static function traffic_reset_demo(): void
    {
        $accountId = (int)post('account_id', 0);
        $account = EcsService::account($accountId);
        if ($account === null || (int)$account['is_demo'] !== 1) {
            json_error('仅演示账号支持重置');
        }
        Db::pdo()->prepare('DELETE FROM traffic_daily WHERE account_id = ?')->execute([$accountId]);
        foreach (EcsService::instances(['account_id' => $accountId]) as $it) {
            TrafficService::syncInstance($account, $it);
        }
        TrafficService::syncCdt($account);
        json_ok([], '演示流量已重新生成');
    }

    /* ---------------- 任务 ---------------- */

      private static function task_add(): void
      {
          $name = trim((string)post('name', ''));
          $kind = (string)post('kind', '');
          $accountId = (int)post('account_id', 0);
          $instanceId = trim((string)post('instance_id', ''));
          $cron = trim((string)post('cron_expr', '0 3 * * *'));
          $fail = function (string $msg) use ($name, $kind, $accountId, $instanceId, $cron): void {
              app_log('task_add FAIL: ' . $msg
                  . ' | name=' . var_export($name, true)
                  . ' kind=' . var_export($kind, true)
                  . ' account=' . $accountId
                  . ' instance=' . var_export($instanceId, true)
                  . ' cron=' . var_export($cron, true));
              json_error($msg);
          };
          if ($name === '' || !in_array($kind, ['power_on', 'power_off', 'keepalive'], true)) {
              $fail('请填写任务名称并选择任务类型');
          }
          if (SchedulerService::cronNext($cron, time()) === null) {
              $fail('cron 表达式无效，示例：0 3 * * *（每天 03:00）');
          }
          $payload = [];
          if ($kind === 'keepalive') {
              $instance = EcsService::instanceByRemoteId($accountId, $instanceId);
              if ($instance === null) {
                  $fail('账号与实例不匹配：该实例不属于所选账号，请重新选择账号或实例');
              }
              $recipe = json_decode((string)$instance['recipe_json'], true);
              if (!is_array($recipe) || empty($recipe['recipe_id'])) {
                  // 非面板创建：允许创建，降级为“仅拉起”模式（释放后无法自动重建，会发通知提醒）
                  $payload = ['mode' => 'start_only'];
              } else {
                  $payload = ['recipe_id' => $recipe['recipe_id'], 'recipe' => $recipe];
              }
          }
        $next = SchedulerService::cronNext($cron, time());
        Db::pdo()->prepare(
            'INSERT INTO tasks (name, kind, account_id, instance_id, cron_expr, enabled, next_run_at, payload_json, created_at)
             VALUES (?,?,?,?,?,1,?,?,?)'
        )->execute([
            $name, $kind, $accountId, $instanceId, $cron,
            $next ? date('Y-m-d H:i:s', $next) : null,
            json_encode($payload, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'),
        ]);
        log_event('task', 'info', "已添加任务「{$name}」", "类型：{$kind} / cron：{$cron}");
        json_ok([], '任务已添加');
    }

    private static function task_delete(): void
    {
        $id = (int)post('id', 0);
        Db::pdo()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        json_ok([], '任务已删除');
    }

    private static function task_toggle(): void
    {
        $id = (int)post('id', 0);
        $stmt = Db::pdo()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $task = $stmt->fetch();
        if ($task === false) {
            json_error('任务不存在');
        }
        $enabled = (int)$task['enabled'] === 1 ? 0 : 1;
        Db::pdo()->prepare('UPDATE tasks SET enabled = ? WHERE id = ?')->execute([$enabled, $id]);
        json_ok(['enabled' => $enabled], $enabled ? '任务已启用' : '任务已停用');
    }

    private static function task_run(): void
    {
        $id = (int)post('id', 0);
        $stmt = Db::pdo()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $task = $stmt->fetch();
        if ($task === false) {
            json_error('任务不存在');
        }
        try {
            SchedulerService::executeTask($task);
            Db::pdo()->prepare('UPDATE tasks SET last_run_at = ?, last_result = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), 'ok', $id]);
            json_ok([], '任务执行成功');
        } catch (\Throwable $e) {
            Db::pdo()->prepare('UPDATE tasks SET last_run_at = ?, last_result = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), '错误：' . $e->getMessage(), $id]);
            json_error('执行失败：' . $e->getMessage(), 422);
        }
    }

    /* ---------------- 事件与日志 ---------------- */

    private static function events_clear(): void
    {
        Db::pdo()->exec('DELETE FROM events');
        json_ok([], '事件记录已清空');
    }

    private static function clear_notify_log(): void
    {
        Db::pdo()->exec('DELETE FROM notify_log');
        json_ok([], '通知记录已清空');
    }

    /* ---------------- 设置 ---------------- */

    private static function settings_save(): void
    {
        $section = (string)post('section', '');
        switch ($section) {
            case 'general':
                set_setting('timezone', trim((string)post('timezone', 'Asia/Shanghai')));
                set_setting('billing_cycle_day', (string)max(1, min(28, (int)post('billing_cycle_day', 1))));
                break;

              case 'guard':
              case 'guard_defaults':
                  set_setting('guard_enabled', post('guard_enabled') === '1' ? '1' : '0');
                  set_setting('guard_threshold_gb', (string)max(1, (float)post('guard_threshold_gb', 180)));
                  set_setting('guard_warn_pct', (string)max(50, min(99, (int)post('guard_warn_pct', 80))));
                  set_setting('guard_cooldown_min', (string)max(1, (int)post('guard_cooldown_min', 120)));
                  break;

              case 'account_cfg':
                  $accountId = (int)post('account_id', 0);
                  if (EcsService::account($accountId) === null) {
                      json_error('账号不存在');
                  }
                  \App\Services\AccountConfig::saveBillingCycleDay($accountId, (int)post('billing_cycle_day', 1));
                  \App\Services\AccountConfig::saveGuardConfig($accountId, [
                      'enabled' => post('guard_enabled') === '1' ? 1 : 0,
                      'threshold_gb' => (float)post('guard_threshold_gb', 180),
                      'warn_pct' => (int)post('guard_warn_pct', 80),
                      'cooldown_min' => (int)post('guard_cooldown_min', 120),
                  ]);
                  \App\Services\CostService::saveEnabled($accountId, post('cost_enabled') === '1');
                  \App\Services\AccountConfig::saveKeepaliveAuto($accountId, post('keepalive_auto') === '1');
                  log_event('account', 'info', "账号「" . (string)EcsService::account($accountId)['name'] . "」配置已更新",
                      '计费周期、熔断参数、成本分析与自动保活按账号独立保存', $accountId);
                  break;

            case 'notify':
                $channels = [];
                foreach (['telegram', 'serverchan', 'webhook', 'email'] as $ch) {
                    if (post('ch_' . $ch) === '1') {
                        $channels[] = $ch;
                    }
                }
                set_setting('notify_channels', implode(',', $channels));
                set_setting('telegram_bot_token', trim((string)post('telegram_bot_token', '')));
                set_setting('telegram_chat_id', trim((string)post('telegram_chat_id', '')));
                set_setting('serverchan_key', trim((string)post('serverchan_key', '')));
                  set_setting('webhook_url', trim((string)post('webhook_url', '')));
                  set_setting('http_proxy', trim((string)post('http_proxy', '')));
                  set_setting('smtp_host', trim((string)post('smtp_host', '')));
                set_setting('smtp_port', (string)(int)post('smtp_port', 465));
                set_setting('smtp_encryption', (string)post('smtp_encryption', 'ssl'));
                set_setting('smtp_user', trim((string)post('smtp_user', '')));
                $pass = (string)post('smtp_pass', '');
                if ($pass !== '') {
                    set_setting('smtp_pass_enc', \App\Services\Crypt::encrypt($pass));
                }
                set_setting('smtp_from', trim((string)post('smtp_from', '')));
                set_setting('smtp_to', trim((string)post('smtp_to', '')));
                break;

            default:
                json_error('未知设置分组');
        }
        json_ok([], '设置已保存');
    }

    private static function notify_test(): void
    {
        $channel = (string)post('channel', '');
        [$ok, $msg] = NotificationService::testChannel($channel);
        if ($ok) {
            json_ok([], $msg);
        }
        json_error($msg, 422);
    }

    private static function scheduler_run(): void
    {
        $result = SchedulerService::run();
        if (!empty($result['busy'])) {
            json_error('调度器正在运行中，请稍后再试', 429);
        }
        json_ok($result, '调度完成：任务 ' . $result['tasks'] . ' 个，停机 ' . $result['stopped'] . ' 台');
    }
}
