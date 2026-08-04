<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

/**
 * 阿里云 ECS + 云监控(CMS) 封装。
 * 所有方法返回标准化数组，失败时抛出 RuntimeException。
 */
final class AliyunCloud
{
    private AliyunRpcClient $ecs;
    private AliyunRpcClient $cms;
    private AliyunRpcClient $cdt;
    private AliyunRpcClient $bss;

    public function __construct(string $accessKeyId, string $accessKeySecret)
    {
        $this->ecs = new AliyunRpcClient($accessKeyId, $accessKeySecret, 'ecs.aliyuncs.com', '2014-05-26');
        $this->cms = new AliyunRpcClient($accessKeyId, $accessKeySecret, 'metrics.cn-hangzhou.aliyuncs.com', '2019-01-01');
        $this->cdt = new AliyunRpcClient($accessKeyId, $accessKeySecret, 'cdt.aliyuncs.com', '2021-08-13');
        $this->bss = new AliyunRpcClient($accessKeyId, $accessKeySecret, 'business.aliyuncs.com', '2017-12-14');
    }

    public function testConnection(): void
    {
        $this->ecs->call('DescribeRegions');
    }

    /**
     * 账号级 CDT（云数据传输）出网流量，单位字节。
     *
     * ListCdtInternetTraffic 返回当前账单周期的账号级公网出网流量累计值，
     * 覆盖共享带宽包（cbwp）、共享流量包、跨地域带宽等计费口径；
     * 接口暂不支持按时间过滤或按天拆分，时间参数会被忽略。
     */
    public function cdtTraffic(): int
    {
        $data = $this->cdt->call('ListCdtInternetTraffic');
        $total = 0;
        foreach ((array)($data['TrafficDetails'] ?? []) as $detail) {
            $total += (int)($detail['Traffic'] ?? 0);
        }
        return $total;
    }

    /**
     * 费用中心（BSS）成本汇总：
     * 当月已出账费用（按实际应付金额累加）+ 账户余额。
     *
     * 依赖 RAM 权限：bss:DescribeInstanceBill、bss:QueryAccountBalance。
     */
    public function costSummary(): array
    {
        $balanceData = $this->bss->call('QueryAccountBalance');
        $balance = (float)($balanceData['Data']['AvailableAmount'] ?? 0);
        $currency = (string)($balanceData['Data']['Currency'] ?? 'CNY');

        $month = date('Y-m');
        $amount = 0.0;
        $items = 0;
        $page = 1;
        do {
            $data = $this->bss->call('DescribeInstanceBill', [
                'BillingCycle' => $month,
                'Granularity' => 'MONTHLY',
                'PageNum' => (string)$page,
                'PageSize' => '100',
            ]);
            $rows = (array)($data['Data']['Items'] ?? []);
            foreach ($rows as $row) {
                $amount += (float)($row['AfterDiscountAmount'] ?? 0);
                $items++;
            }
            $total = (int)($data['Data']['TotalCount'] ?? count($rows));
            $page++;
        } while ($items < $total && $page <= 10);

        return [
            'month' => $month,
            'amount' => round($amount, 4),
            'items' => $items,
            'balance' => round($balance, 2),
            'currency' => $currency,
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function listRegions(): array
    {
        $data = $this->ecs->call('DescribeRegions');
        $out = [];
        foreach ($data['Regions']['Region'] ?? [] as $r) {
            $out[] = ['id' => (string)$r['RegionId'], 'name' => (string)($r['LocalName'] ?? $r['RegionId'])];
        }
        usort($out, fn ($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    public function listInstances(string $region = ''): array
    {
        if ($region !== '') {
            return $this->listInstancesInRegion($region);
        }
        // 不指定地域时：先列出全部地域，再逐地域拉取，避免 MissingParameter
        $out = [];
        $errors = [];
        foreach ($this->listRegions() as $r) {
            try {
                foreach ($this->listInstancesInRegion($r['id']) as $it) {
                    $out[] = $it;
                }
            } catch (\Throwable $e) {
                $errors[] = $r['id'] . '：' . $e->getMessage();
            }
        }
        if (count($out) === 0 && count($errors) > 0) {
            throw new \RuntimeException(implode('；', array_slice($errors, 0, 3)));
        }
        return $out;
    }

    private function listInstancesInRegion(string $region): array
    {
        $out = [];
        $page = 1;
        do {
            $data = $this->ecs->call('DescribeInstances', [
                'RegionId' => $region,
                'PageSize' => '100',
                'PageNumber' => (string)$page,
            ]);
            $items = $data['Instances']['Instance'] ?? [];
            foreach ($items as $raw) {
                $out[] = self::normalize($raw);
            }
            $total = (int)($data['TotalCount'] ?? count($items));
            $page++;
        } while (count($out) < $total && $page <= 50);
        return $out;
    }

    public function start(string $instanceId): void
    {
        $this->ecs->call('StartInstance', ['InstanceId' => $instanceId]);
    }

    public function stop(string $instanceId, bool $force = false): void
    {
        $this->ecs->call('StopInstance', [
            'InstanceId' => $instanceId,
            'ForceStop' => $force ? 'true' : 'false',
        ]);
    }

    public function reboot(string $instanceId, bool $force = false): void
    {
        $this->ecs->call('RebootInstance', [
            'InstanceId' => $instanceId,
            'ForceStop' => $force ? 'true' : 'false',
        ]);
    }

    public function release(string $instanceId, bool $force = false): void
    {
        $this->ecs->call('DeleteInstance', [
            'InstanceId' => $instanceId,
            'Force' => $force ? 'true' : 'false',
        ]);
    }

    public function allocatePublicIp(string $instanceId): string
    {
        $data = $this->ecs->call('AllocatePublicIpAddress', ['InstanceId' => $instanceId]);
        return (string)($data['IpAddress'] ?? '');
    }

    /**
     * @param array $r 创建参数（recipe）
     */
    public function createInstance(array $r): string
    {
        $p = [
            'RegionId' => (string)$r['region'],
            'InstanceType' => (string)$r['instance_type'],
            'ImageId' => (string)$r['image_id'],
            'SecurityGroupId' => (string)$r['security_group_id'],
            'InstanceChargeType' => ($r['charge'] ?? 'postpaid') === 'prepaid' ? 'PrePaid' : 'PostPaid',
            'InternetChargeType' => ($r['internet_charge'] ?? 'traffic') === 'bandwidth' ? 'PayByBandwidth' : 'PayByTraffic',
            'InternetMaxBandwidthOut' => (string)max(1, (int)($r['bandwidth_out'] ?? 5)),
            'InstanceName' => (string)($r['name'] ?? 'OpsDeck-ECS'),
            'ClientToken' => (string)($r['client_token'] ?? bin2hex(random_bytes(8))),
            'SystemDisk.Category' => (string)($r['disk_category'] ?? 'cloud_essd'),
            'SystemDisk.Size' => (string)max(20, min(500, (int)($r['disk_size'] ?? 40))),
        ];
        if (($r['charge'] ?? 'postpaid') === 'prepaid') {
            $p['Period'] = (string)max(1, min(60, (int)($r['period'] ?? 1)));
            $p['PeriodUnit'] = 'Month';
        }
        if (!empty($r['vswitch_id'])) {
            $p['VSwitchId'] = (string)$r['vswitch_id'];
        }
        if (!empty($r['zone_id'])) {
            $p['ZoneId'] = (string)$r['zone_id'];
        }
        if (!empty($r['password'])) {
            $p['Password'] = (string)$r['password'];
        } elseif (!empty($r['key_pair'])) {
            $p['KeyPairName'] = (string)$r['key_pair'];
        }
        if (($r['spot'] ?? '') === 'SpotWithPriceLimit') {
            $p['SpotStrategy'] = 'SpotWithPriceLimit';
            $p['SpotPriceLimit'] = (string)max(0.001, (float)($r['spot_price'] ?? 0.5));
        }
        if (($r['data_disk_size'] ?? 0) > 0) {
            $p['DataDisk.1.Size'] = (string)max(20, min(32768, (int)$r['data_disk_size']));
            $p['DataDisk.1.Category'] = (string)($r['data_disk_category'] ?? 'cloud_essd');
        }
        if (!empty($r['recipe_id'])) {
            $p['Tag.1.Key'] = 'ecs-panel:recipe';
            $p['Tag.1.Value'] = (string)$r['recipe_id'];
            $p['Tag.2.Key'] = 'ecs-panel:managed';
            $p['Tag.2.Value'] = '1';
        }
        $data = $this->ecs->call('CreateInstance', $p);
        return (string)($data['InstanceId'] ?? '');
    }

    public function describeInstanceTypes(string $region): array
    {
        $data = $this->ecs->call('DescribeInstanceTypes', ['RegionId' => $region]);
        $out = [];
        foreach ($data['InstanceTypes']['InstanceType'] ?? [] as $t) {
            $out[] = [
                'id' => (string)$t['InstanceTypeId'],
                'family' => (string)($t['InstanceTypeFamily'] ?? ''),
                'cpu' => (int)($t['CpuCoreCount'] ?? 0),
                'memory' => (float)($t['MemorySize'] ?? 0),
            ];
        }
        usort($out, fn ($a, $b) => [$a['cpu'], $a['memory']] <=> [$b['cpu'], $b['memory']]);
        return $out;
    }

    public function describeImages(string $region): array
    {
        $out = [];
        foreach (['system', 'self'] as $owner) {
            $data = $this->ecs->call('DescribeImages', [
                'RegionId' => $region,
                'ImageOwnerAlias' => $owner,
                'PageSize' => '50',
                'PageNumber' => '1',
                'Status' => 'Available',
            ]);
            foreach ($data['Images']['Image'] ?? [] as $img) {
                $out[] = [
                    'id' => (string)$img['ImageId'],
                    'name' => (string)($img['ImageName'] ?? $img['ImageId']),
                    'os' => (string)($img['OSName'] ?? ''),
                    'size' => (int)($img['Size'] ?? 0),
                    'arch' => (string)($img['Architecture'] ?? ''),
                ];
            }
        }
        return $out;
    }

    public function describeSecurityGroups(string $region): array
    {
        $data = $this->ecs->call('DescribeSecurityGroups', [
            'RegionId' => $region,
            'PageSize' => '50',
        ]);
        $out = [];
        foreach ($data['SecurityGroups']['SecurityGroup'] ?? [] as $g) {
            $out[] = [
                'id' => (string)$g['SecurityGroupId'],
                'name' => (string)($g['SecurityGroupName'] ?? $g['SecurityGroupId']),
            ];
        }
        return $out;
    }

    public function describeVSwitches(string $region, string $zone = ''): array
    {
        $params = ['RegionId' => $region, 'PageSize' => '100'];
        if ($zone !== '') {
            $params['ZoneId'] = $zone;
        }
        $data = $this->ecs->call('DescribeVSwitches', $params);
        $out = [];
        foreach ($data['VSwitches']['VSwitch'] ?? [] as $v) {
            $out[] = [
                'id' => (string)$v['VSwitchId'],
                'name' => (string)($v['VSwitchName'] ?? $v['VSwitchId']),
                'zone' => (string)($v['ZoneId'] ?? ''),
                'available' => (int)($v['AvailableIpAddressCount'] ?? 0),
            ];
        }
        return $out;
    }

    public function describeZones(string $region): array
    {
        $data = $this->ecs->call('DescribeZones', ['RegionId' => $region]);
        $out = [];
        foreach ($data['Zones']['Zone'] ?? [] as $z) {
            $out[] = ['id' => (string)$z['ZoneId'], 'name' => (string)($z['LocalName'] ?? $z['ZoneId'])];
        }
        return $out;
    }

    /**
     * 查询实例公网出入流量（每日字节数）。
     * - 实例绑定了 EIP 时，公网流量走 EIP 指标（acs_vpc_eip 的 net_rx.rate/net_tx.rate，单位 bps）
     * - 未绑定 EIP 时，走 ECS 公网指标（acs_ecs_dashboard 的 InternetInRate/InternetOutRate，单位 bps）
     *
     * @return array<string, array{in:float,out:float}> key 为 Y-m-d(UTC)
     */
    public function trafficRates(string $instanceId, string $startIso, string $endIso, string $eip = '', string $region = ''): array
    {
        $result = [];
        if ($eip !== '') {
            $namespace = 'acs_vpc_eip';
            $metrics = ['net_rx.rate' => 'in', 'net_tx.rate' => 'out'];
            $dimensions = json_encode([['eip' => $eip]], JSON_UNESCAPED_UNICODE);
        } else {
            $namespace = 'acs_ecs_dashboard';
            $metrics = ['InternetInRate' => 'in', 'InternetOutRate' => 'out'];
            $dimensions = json_encode([['instanceId' => $instanceId]], JSON_UNESCAPED_UNICODE);
        }
        foreach ($metrics as $metric => $key) {
            $data = $this->cms->call('DescribeMetricList', [
                'Namespace' => $namespace,
                'MetricName' => $metric,
                'Period' => '86400',
                'StartTime' => $startIso,
                'EndTime' => $endIso,
                'Dimensions' => $dimensions,
                'Length' => '100',
                'RegionId' => $region !== '' ? $region : 'cn-hangzhou',
            ]);
            $points = json_decode((string)($data['Datapoints'] ?? '[]'), true);
            foreach ((array)$points as $p) {
                if (!isset($p['timestamp'], $p['Average'])) {
                    continue;
                }
                $day = gmdate('Y-m-d', (int)((int)$p['timestamp'] / 1000));
                $seconds = self::daySeconds($day);
                // CMS 指标单位为 bits/s（bps），换算成字节：bps × 秒 ÷ 8
                $bytes = (float)$p['Average'] * $seconds / 8;
                $result[$day][$key] = $bytes;
            }
        }
        ksort($result);
        return $result;
    }

    public function cpuPoint(string $instanceId): ?float
    {
        $end = time();
        $start = $end - 600;
        $data = $this->cms->call('DescribeMetricLast', [
            'Namespace' => 'acs_ecs_dashboard',
            'MetricName' => 'CPUUtilization',
            'Dimensions' => json_encode([['instanceId' => $instanceId]], JSON_UNESCAPED_UNICODE),
            'StartTime' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'EndTime' => gmdate('Y-m-d\TH:i:s\Z', $end),
            'Length' => '5',
            'RegionId' => 'cn-hangzhou',
        ]);
        $points = json_decode((string)($data['Datapoints'] ?? '[]'), true);
        if (empty($points)) {
            return null;
        }
        $last = end($points);
        return isset($last['Average']) ? (float)$last['Average'] : null;
    }

    private static function daySeconds(string $dayUtc): int
    {
        $dayStart = strtotime($dayUtc . ' UTC');
        if ($dayStart === false) {
            return 86400;
        }
        $todayStart = strtotime(gmdate('Y-m-d') . ' UTC');
        if ($dayStart >= $todayStart) {
            return max(60, time() - $dayStart);
        }
        return 86400;
    }

    private static function normalize(array $raw): array
    {
        $publicIps = (array)($raw['PublicIpAddress']['IpAddress'] ?? []);
        $privateIps = (array)($raw['VpcAttributes']['PrivateIpAddress']['IpAddress'] ?? []);
        $tags = [];
        $tagList = $raw['Tags']['Tag'] ?? [];
        // 阿里云返回单个标签时为关联数组，多个标签时为列表
        if (isset($tagList['Key'])) {
            $tagList = [$tagList];
        }
        foreach ((array)$tagList as $t) {
            if (is_array($t)) {
                $tags[(string)($t['Key'] ?? '')] = (string)($t['Value'] ?? '');
            }
        }
        return [
            'id' => (string)$raw['InstanceId'],
            'name' => (string)($raw['InstanceName'] ?? ''),
            'region' => (string)($raw['RegionId'] ?? ''),
            'status' => (string)($raw['Status'] ?? ''),
            'type' => (string)($raw['InstanceType'] ?? ''),
            'cpu' => (int)($raw['Cpu'] ?? 0),
            'memory_mb' => (int)round((float)($raw['Memory'] ?? 0) * 1024),
            'public_ip' => implode(',', $publicIps),
            'private_ip' => implode(',', $privateIps),
            'eip' => (string)($raw['EipAddress']['IpAddress'] ?? ''),
            'charge_type' => (string)($raw['InstanceChargeType'] ?? ''),
            'spot_strategy' => (string)($raw['SpotStrategy'] ?? 'NoSpot'),
            'expired_at' => (string)($raw['ExpiredTime'] ?? ''),
            'created_time' => (string)($raw['CreationTime'] ?? ''),
            'image_id' => (string)($raw['ImageId'] ?? ''),
            'os_name' => (string)($raw['OSName'] ?? ''),
            'tags' => $tags,
        ];
    }
}
