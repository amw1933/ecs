<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/**
 * 演示模式客户端：无需阿里云密钥即可体验完整功能。
 * 实例状态持久化到本地数据库，流量数据按日期确定性生成。
 */
final class MockCloudApi
{
    private int $accountId;

    private const SEED_INSTANCES = [
        ['cn-hangzhou', 'i-demo-001', 'web-01', 'Running', 'ecs.g7.large', 2, 8192, '47.98.10.21', '172.16.0.11', 'PostPaid', 'NoSpot'],
        ['cn-hangzhou', 'i-demo-002', 'api-01', 'Running', 'ecs.g7.2xlarge', 8, 32768, '47.98.10.22', '172.16.0.12', 'PostPaid', 'SpotWithPriceLimit'],
        ['cn-shanghai', 'i-demo-003', 'db-01', 'Running', 'ecs.r7.large', 2, 16384, '101.132.8.31', '10.0.0.3', 'PrePaid', 'NoSpot'],
        ['cn-shanghai', 'i-demo-004', 'build-01', 'Stopped', 'ecs.c7.4xlarge', 16, 32768, '101.132.8.32', '10.0.0.4', 'PostPaid', 'NoSpot'],
        ['cn-qingdao', 'i-demo-005', 'cdn-edge-01', 'Running', 'ecs.g7.large', 2, 8192, '139.9.2.51', '172.18.0.5', 'PostPaid', 'SpotAsPriceGo'],
        ['cn-qingdao', 'i-demo-006', 'log-01', 'Running', 'ecs.g6.large', 2, 8192, '139.9.2.52', '172.18.0.6', 'PostPaid', 'NoSpot'],
        ['cn-beijing', 'i-demo-007', 'gateway-01', 'Running', 'ecs.g7.2xlarge', 8, 16384, '39.97.6.71', '172.20.0.7', 'PrePaid', 'NoSpot'],
        ['cn-beijing', 'i-demo-008', 'worker-01', 'Running', 'ecs.c7.2xlarge', 8, 16384, '39.97.6.72', '172.20.0.8', 'PostPaid', 'SpotWithPriceLimit'],
        ['cn-shenzhen', 'i-demo-009', 'backup-01', 'Stopped', 'ecs.g6.xlarge', 4, 16384, '120.78.9.91', '172.22.0.9', 'PrePaid', 'NoSpot'],
        ['cn-hangzhou', 'i-demo-010', 'cache-01', 'Running', 'ecs.g7.xlarge', 4, 16384, '47.98.10.23', '172.16.0.10', 'PostPaid', 'NoSpot'],
    ];

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->seedIfEmpty();
    }

    private function seedIfEmpty(): void
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) AS c FROM instances WHERE account_id = ?');
        $stmt->execute([$this->accountId]);
        if ((int)$stmt->fetch()['c'] > 0) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        $ins = Db::pdo()->prepare(
            'INSERT INTO instances
             (account_id, instance_id, region_id, instance_name, status, instance_type, cpu, memory_mb,
              public_ip, private_ip, eip, charge_type, spot_strategy, expired_at, created_time, image_id,
              os_name, tags_json, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach (self::SEED_INSTANCES as $i => $row) {
            $expired = $row[9] === 'PrePaid' ? date('Y-m-d', strtotime('+' . (8 + $i) . ' days')) : null;
            $ins->execute([
                $this->accountId, $row[1], $row[0], $row[2], $row[3], $row[4], $row[5], $row[6],
                $row[7], $row[8], '', $row[9], $row[10], $expired,
                date('Y-m-d', strtotime('-' . (30 + $i * 17) . ' days')),
                'aliyun_3_x64', 'Aliyun Linux 3.2104 LTS 64位',
                json_encode(['ecs-panel:managed' => '1', 'ecs-panel:recipe' => 'recipe-demo-' . $row[1]], JSON_UNESCAPED_UNICODE),
                $now,
            ]);
        }
    }

    public function testConnection(): void
    {
        // 演示模式始终可用
    }

    /** 演示模式账号级 CDT 出网流量：按当月天数确定性累计（字节） */
    public function cdtTraffic(): int
    {
        $seed = crc32('cdt-demo-' . $this->accountId . '-' . date('Y-m'));
        mt_srand($seed);
        $dayGb = 0.5 + (int)mt_rand(0, 30) / 10;       // 每天 0.5~3.5 GB
        $bytes = (int)round($dayGb * 1073741824 * (int)date('j'));
        mt_srand();
        return $bytes;
    }

    /** 演示模式成本汇总：按当月天数确定性生成 */
    public function costSummary(): array
    {
        $seed = crc32('cost-demo-' . $this->accountId . '-' . date('Y-m'));
        mt_srand($seed);
        $perDay = (int)mt_rand(20, 90) / 10;            // 每天 2~9 元
        mt_srand();
        return [
            'month' => date('Y-m'),
            'amount' => round($perDay * (int)date('j'), 2),
            'items' => 1,
            'balance' => 100.00,
            'currency' => 'CNY',
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function listRegions(): array
    {
        return [
            ['id' => 'cn-hangzhou', 'name' => '华东1（杭州）'],
            ['id' => 'cn-shanghai', 'name' => '华东2（上海）'],
            ['id' => 'cn-qingdao', 'name' => '华北1（青岛）'],
            ['id' => 'cn-beijing', 'name' => '华北2（北京）'],
            ['id' => 'cn-shenzhen', 'name' => '华南1（深圳）'],
            ['id' => 'cn-chengdu', 'name' => '西南1（成都）'],
            ['id' => 'cn-hongkong', 'name' => '中国香港'],
        ];
    }

    public function listInstances(string $region = ''): array
    {
        $sql = 'SELECT * FROM instances WHERE account_id = ?';
        $args = [$this->accountId];
        if ($region !== '') {
            $sql .= ' AND region_id = ?';
            $args[] = $region;
        }
        $sql .= ' ORDER BY instance_id';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->rowToNormalized($row);
        }
        return $out;
    }

    public function start(string $instanceId): void
    {
        $this->updateStatus($instanceId, 'Running');
    }

    public function stop(string $instanceId, bool $force = false): void
    {
        $this->updateStatus($instanceId, 'Stopped');
    }

    public function reboot(string $instanceId, bool $force = false): void
    {
        $this->updateStatus($instanceId, 'Running');
    }

    public function release(string $instanceId, bool $force = false): void
    {
        $this->updateStatus($instanceId, 'Released');
    }

    public function allocatePublicIp(string $instanceId): string
    {
        return '47.99.' . (1 + abs(crc32($instanceId)) % 250) . '.' . (1 + abs(crc32($instanceId . 'x')) % 250);
    }

    public function createInstance(array $r): string
    {
        $id = 'i-demo-' . str_pad((string)(100 + abs(crc32((string)($r['client_token'] ?? microtime(true)))) % 899), 3, '0', STR_PAD_LEFT);
        $tags = ['ecs-panel:managed' => '1'];
        if (!empty($r['recipe_id'])) {
            $tags['ecs-panel:recipe'] = (string)$r['recipe_id'];
        }
        $expired = ($r['charge'] ?? 'postpaid') === 'prepaid'
            ? date('Y-m-d', strtotime('+' . (int)($r['period'] ?? 1) . ' months'))
            : null;
        $ip = $this->allocatePublicIp($id);
        Db::pdo()->prepare(
            'INSERT INTO instances
             (account_id, instance_id, region_id, instance_name, status, instance_type, cpu, memory_mb,
              public_ip, private_ip, eip, charge_type, spot_strategy, expired_at, created_time, image_id,
              os_name, tags_json, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $this->accountId, $id, (string)$r['region'], (string)($r['name'] ?? 'OpsDeck-ECS'),
            'Running', (string)$r['instance_type'],
            (int)($r['cpu'] ?? 2), (int)($r['memory_mb'] ?? 8192),
            $ip, '172.16.' . (1 + abs(crc32($id)) % 250) . '.' . (1 + abs(crc32($id . 'p')) % 250),
            '', ($r['charge'] ?? 'postpaid') === 'prepaid' ? 'PrePaid' : 'PostPaid',
            (string)($r['spot'] ?? 'NoSpot'), $expired, date('Y-m-d H:i:s'),
            (string)$r['image_id'], '演示系统镜像',
            json_encode($tags, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    public function describeInstanceTypes(string $region): array
    {
        $types = [];
        foreach (['ecs.g7.large' => [2, 8], 'ecs.g7.xlarge' => [4, 16], 'ecs.g7.2xlarge' => [8, 32],
                  'ecs.c7.large' => [2, 4], 'ecs.c7.xlarge' => [4, 8], 'ecs.c7.2xlarge' => [8, 16],
                  'ecs.c7.4xlarge' => [16, 32], 'ecs.r7.large' => [2, 16], 'ecs.r7.xlarge' => [4, 32],
                  'ecs.r7.2xlarge' => [8, 64]] as $id => $spec) {
            $types[] = ['id' => $id, 'family' => explode('.', $id)[1] ?? '', 'cpu' => $spec[0], 'memory' => $spec[1]];
        }
        return $types;
    }

    public function describeImages(string $region): array
    {
        return [
            ['id' => 'aliyun_3_x64', 'name' => 'Aliyun Linux 3.2104 LTS 64位', 'os' => 'Aliyun Linux 3.2104', 'size' => 40, 'arch' => 'x86_64'],
            ['id' => 'centos_7_9_x64', 'name' => 'CentOS 7.9 64位', 'os' => 'CentOS 7.9', 'size' => 40, 'arch' => 'x86_64'],
            ['id' => 'ubuntu_22_04_x64', 'name' => 'Ubuntu 22.04 64位', 'os' => 'Ubuntu 22.04', 'size' => 40, 'arch' => 'x86_64'],
            ['id' => 'debian_12_x64', 'name' => 'Debian 12 64位', 'os' => 'Debian 12', 'size' => 40, 'arch' => 'x86_64'],
            ['id' => 'alibaba_cloud_linux_3_x64', 'name' => 'Alibaba Cloud Linux 3.2104 LTS 64位', 'os' => 'Alibaba Cloud Linux 3', 'size' => 40, 'arch' => 'x86_64'],
        ];
    }

    public function describeSecurityGroups(string $region): array
    {
        return [
            ['id' => 'sg-demo-001', 'name' => '默认安全组'],
            ['id' => 'sg-demo-002', 'name' => 'Web 服务组'],
            ['id' => 'sg-demo-003', 'name' => '数据库组'],
        ];
    }

    public function describeVSwitches(string $region, string $zone = ''): array
    {
        return [
            ['id' => 'vsw-demo-001', 'name' => '默认交换机', 'zone' => $region . '-a', 'available' => 248],
            ['id' => 'vsw-demo-002', 'name' => '业务交换机', 'zone' => $region . '-b', 'available' => 200],
        ];
    }

    public function describeZones(string $region): array
    {
        return [
            ['id' => $region . '-a', 'name' => $region . ' 可用区 A'],
            ['id' => $region . '-b', 'name' => $region . ' 可用区 B'],
        ];
    }

    /** 确定性生成每日出入流量（字节） */
    public function trafficRates(string $instanceId, string $startIso, string $endIso): array
    {
        $out = [];
        $start = strtotime($startIso);
        $end = strtotime($endIso);
        $day = (int)gmdate('Ymd', $start);
        $ts = $start;
        while ($ts <= $end) {
            $seed = crc32($instanceId . '-' . $day);
            mt_srand($seed);
            $base = 2 + (int)mt_rand(0, 130) / 10;      // 2~15 GB
            if ($seed % 37 === 0) {
                $base *= 3.2;                            // 偶发流量尖峰
            }
            $outGb = $base * (0.75 + (int)mt_rand(0, 20) / 100);
            $inGb = $base - $outGb;
            $key = gmdate('Y-m-d', $ts);
            $out[$key] = [
                'in' => $inGb * 1073741824,
                'out' => $outGb * 1073741824,
            ];
            $ts += 86400;
            $day = (int)gmdate('Ymd', $ts);
        }
        return $out;
    }

    public function cpuPoint(string $instanceId): ?float
    {
        mt_srand(crc32($instanceId . date('YmdH')));
        return 3 + (float)mt_rand(0, 920) / 10;
    }

    private function rowToNormalized(array $row): array
    {
        return [
            'id' => (string)$row['instance_id'],
            'name' => (string)$row['instance_name'],
            'region' => (string)$row['region_id'],
            'status' => (string)$row['status'],
            'type' => (string)$row['instance_type'],
            'cpu' => (int)$row['cpu'],
            'memory_mb' => (int)$row['memory_mb'],
            'public_ip' => (string)$row['public_ip'],
            'private_ip' => (string)$row['private_ip'],
            'eip' => (string)$row['eip'],
            'charge_type' => (string)$row['charge_type'],
            'spot_strategy' => (string)$row['spot_strategy'],
            'expired_at' => (string)$row['expired_at'],
            'created_time' => (string)$row['created_time'],
            'image_id' => (string)$row['image_id'],
            'os_name' => (string)$row['os_name'],
            'tags' => json_decode((string)$row['tags_json'], true) ?: [],
        ];
    }

    private function updateStatus(string $instanceId, string $status): void
    {
        Db::pdo()->prepare(
            'UPDATE instances SET status = ?, updated_at = ? WHERE account_id = ? AND instance_id = ?'
        )->execute([$status, date('Y-m-d H:i:s'), $this->accountId, $instanceId]);
    }
}
