<?php
declare(strict_types=1);

// cron.php 仅允许命令行运行；通过网址直接访问一律拒绝
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: cron.php 只能通过命令行运行');
}

/**
 * OpsDeck 调度入口（CLI）
 *
 * 用法：
 *   php cron.php run        # 运行定时任务 + 流量熔断检查（建议每分钟）
 *   php cron.php sync       # 同步实例缓存与流量
 *   php cron.php traffic    # 仅同步流量
 *   php cron.php guard      # 仅执行流量熔断检查
 */

require __DIR__ . '/app/bootstrap.php';

use App\Services\EcsService;
use App\Services\SchedulerService;
use App\Services\TrafficService;

$cmd = $argv[1] ?? 'run';

switch ($cmd) {
    case 'run':
        $result = SchedulerService::run();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        if (!empty($result['busy'])) {
            exit(2);
        }
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                fwrite(STDERR, '  [error] ' . $err . PHP_EOL);
            }
        }
        break;

    case 'sync':
        $accounts = EcsService::enabledAccounts();
        foreach ($accounts as $account) {
            try {
                $n = EcsService::syncInstances($account);
                echo '[' . $account['name'] . "] 实例同步完成（{$n} 台）" . PHP_EOL;
                [$ok, $errors] = TrafficService::syncAll($account);
                echo '[' . $account['name'] . "] 流量同步完成（{$ok} 台）" . PHP_EOL;
                foreach ($errors as $err) {
                    fwrite(STDERR, '  [error] ' . $err . PHP_EOL);
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, '[' . $account['name'] . '] 同步失败：' . $e->getMessage() . PHP_EOL);
            }
        }
        break;

    case 'traffic':
        foreach (EcsService::enabledAccounts() as $account) {
            [$ok, $errors] = TrafficService::syncAll($account);
            echo '[' . $account['name'] . "] 流量同步完成（{$ok} 台）" . PHP_EOL;
            foreach ($errors as $err) {
                fwrite(STDERR, '  [error] ' . $err . PHP_EOL);
            }
        }
        break;

    case 'guard':
        $result = SchedulerService::run();
        echo '熔断检查完成：停机 ' . $result['stopped'] . ' 台，通知 ' . $result['notified'] . ' 条' . PHP_EOL;
        break;

    default:
        fwrite(STDERR, "未知命令：{$cmd}\n用法：php cron.php [run|sync|traffic|guard]\n");
        exit(1);
}
