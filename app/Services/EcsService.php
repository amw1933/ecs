<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;

/**
 * ECS 实例业务门面：账号选择客户端、实例缓存、操作与选项。
 */
final class EcsService
{
    /**
     * @return AliyunCloud|MockCloudApi
     */
    public static function clientForAccount(array $account)
    {
        if ((int)($account['is_demo'] ?? 0) === 1) {
            return new MockCloudApi((int)$account['id']);
        }
        $secret = Crypt::decrypt((string)$account['access_key_secret_enc']);
        return new AliyunCloud((string)$account['access_key_id'], $secret);
    }

    /** 拉取账号下全部实例并刷新本地缓存；返回本次同步的实例数 */
    public static function syncInstances(array $account): int
    {
        $client = self::clientForAccount($account);
        // 账号下实例可能分布在不同地域，全量拉取后缓存
        $instances = $client->listInstances();
        $seen = [];
        $now = date('Y-m-d H:i:s');
        $stmt = Db::pdo()->prepare(
            'INSERT INTO instances
             (account_id, instance_id, region_id, instance_name, status, instance_type, cpu, memory_mb,
              public_ip, private_ip, eip, charge_type, spot_strategy, expired_at, created_time, image_id,
              os_name, tags_json, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT(account_id, instance_id) DO UPDATE SET
               region_id = excluded.region_id,
               instance_name = excluded.instance_name,
               status = excluded.status,
               instance_type = excluded.instance_type,
               cpu = excluded.cpu,
               memory_mb = excluded.memory_mb,
               public_ip = excluded.public_ip,
               private_ip = excluded.private_ip,
               eip = excluded.eip,
               charge_type = excluded.charge_type,
               spot_strategy = excluded.spot_strategy,
               expired_at = excluded.expired_at,
               created_time = excluded.created_time,
               image_id = excluded.image_id,
               os_name = excluded.os_name,
               tags_json = excluded.tags_json,
               updated_at = excluded.updated_at'
        );
        $del = Db::pdo()->prepare('DELETE FROM instances WHERE account_id = ? AND instance_id = ?');
        foreach ($instances as $it) {
            $stmt->execute([
                (int)$account['id'], $it['id'], $it['region'], $it['name'], $it['status'], $it['type'],
                $it['cpu'], $it['memory_mb'], $it['public_ip'], $it['private_ip'], $it['eip'],
                $it['charge_type'], $it['spot_strategy'], $it['expired_at'] ?: null,
                $it['created_time'] ?: null, $it['image_id'], $it['os_name'],
                json_encode($it['tags'], JSON_UNESCAPED_UNICODE), $now,
            ]);
            $seen[$it['id']] = true;
        }
        // 跟踪抢占式实例到自动保活表：释放后记录保留，供自动保活检测重建/提醒
        $now = date('Y-m-d H:i:s');
        $spotIns = Db::pdo()->prepare(
            "INSERT INTO spot_instances (account_id, instance_id, instance_name, recipe_json, last_seen_at, created_at)
             SELECT account_id, instance_id, instance_name, COALESCE(recipe_json, '{}'), ?, ?
             FROM instances
             WHERE account_id = ? AND spot_strategy <> 'NoSpot'
             ON CONFLICT(account_id, instance_id) DO UPDATE SET
               instance_name = excluded.instance_name,
               recipe_json = excluded.recipe_json,
               last_seen_at = excluded.last_seen_at"
        );
        $spotIns->execute([$now, $now, (int)$account['id']]);
        // 同步成功时清理已被释放/删除的实例
        $rows = Db::pdo()->prepare('SELECT instance_id FROM instances WHERE account_id = ?');
        $rows->execute([(int)$account['id']]);
        foreach ($rows->fetchAll() as $row) {
            if (!isset($seen[$row['instance_id']])) {
                $del->execute([(int)$account['id'], $row['instance_id']]);
            }
        }
        return count($instances);
    }

    public static function allAccounts(): array
    {
        return Db::pdo()->query('SELECT * FROM accounts ORDER BY id')->fetchAll();
    }

    public static function enabledAccounts(): array
    {
        return Db::pdo()->query('SELECT * FROM accounts WHERE enabled = 1 ORDER BY id')->fetchAll();
    }

    public static function account(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function instances(array $filters = []): array
    {
        $sql = 'SELECT i.*, a.name AS account_name, a.is_demo AS account_demo
                FROM instances i JOIN accounts a ON a.id = i.account_id WHERE 1=1';
        $args = [];
        if (!empty($filters['account_id'])) {
            $sql .= ' AND i.account_id = ?';
            $args[] = (int)$filters['account_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND i.status = ?';
            $args[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (i.instance_name LIKE ? OR i.instance_id LIKE ? OR i.public_ip LIKE ? OR i.private_ip LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            $args[] = $like; $args[] = $like; $args[] = $like; $args[] = $like;
        }
        $sql .= ' ORDER BY i.status ASC, i.instance_name ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public static function instanceById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.*, a.name AS account_name, a.is_demo AS account_demo
             FROM instances i JOIN accounts a ON a.id = i.account_id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function instanceByRemoteId(int $accountId, string $instanceId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT i.*, a.name AS account_name, a.is_demo AS account_demo
             FROM instances i JOIN accounts a ON a.id = i.account_id
             WHERE i.account_id = ? AND i.instance_id = ?'
        );
        $stmt->execute([$accountId, $instanceId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function operate(string $op, int $accountId, string $instanceId, bool $force = false): string
    {
        $account = self::account($accountId);
        $instance = self::instanceByRemoteId($accountId, $instanceId);
        if ($account === null || $instance === null) {
            throw new \RuntimeException('账号或实例不存在');
        }
        $client = self::clientForAccount($account);
        switch ($op) {
            case 'start':
                $client->start($instanceId);
                $newStatus = 'Running';
                break;
            case 'stop':
                $client->stop($instanceId, $force);
                $newStatus = 'Stopped';
                break;
            case 'reboot':
                $client->reboot($instanceId, $force);
                $newStatus = 'Starting';
                break;
            case 'release':
                $client->release($instanceId, $force);
                $newStatus = 'Released';
                break;
            default:
                throw new \RuntimeException('未知操作');
        }
        Db::pdo()->prepare(
            'UPDATE instances SET status = ?, updated_at = ? WHERE account_id = ? AND instance_id = ?'
        )->execute([$newStatus, date('Y-m-d H:i:s'), $accountId, $instanceId]);

        $opLabel = ['start' => '启动', 'stop' => '停止', 'reboot' => '重启', 'release' => '释放'][$op];
        log_event('instance', 'info', "实例「{$instance['instance_name']}」已{$opLabel}",
            "实例 {$instanceId}（{$account['name']}）", (int)$account['id'], $instanceId);
        return $opLabel;
    }

    public static function create(array $account, array $form): string
    {
        $recipe = [
            'region' => (string)$form['region'],
            'instance_type' => (string)$form['instance_type'],
            'image_id' => (string)$form['image_id'],
            'security_group_id' => (string)$form['security_group_id'],
            'name' => trim((string)($form['name'] ?? '')),
            'charge' => ($form['charge'] ?? 'postpaid') === 'prepaid' ? 'prepaid' : 'postpaid',
            'period' => max(1, min(60, (int)($form['period'] ?? 1))),
            'internet_charge' => ($form['internet_charge'] ?? 'traffic') === 'bandwidth' ? 'bandwidth' : 'traffic',
            'bandwidth_out' => max(1, min(100, (int)($form['bandwidth_out'] ?? 5))),
            'disk_category' => (string)($form['disk_category'] ?? 'cloud_essd'),
            'disk_size' => max(20, min(500, (int)($form['disk_size'] ?? 40))),
            'data_disk_size' => max(0, min(32768, (int)($form['data_disk_size'] ?? 0))),
            'data_disk_category' => (string)($form['data_disk_category'] ?? 'cloud_essd'),
            'vswitch_id' => (string)($form['vswitch_id'] ?? ''),
            'zone_id' => (string)($form['zone_id'] ?? ''),
            'password' => (string)($form['password'] ?? ''),
            'key_pair' => (string)($form['key_pair'] ?? ''),
            'spot' => (string)($form['spot'] ?? 'NoSpot'),
            'spot_price' => max(0.001, (float)($form['spot_price'] ?? 0.5)),
            // 保活重建时复用原 recipe_id，保证新实例能被原任务识别
            'recipe_id' => !empty($form['recipe_id']) ? (string)$form['recipe_id'] : 'recipe-' . bin2hex(random_bytes(6)),
            'client_token' => 'opsdeck-' . bin2hex(random_bytes(8)),
        ];
        $client = self::clientForAccount($account);
        $instanceId = $client->createInstance($recipe);
        $recipe['instance_id'] = $instanceId;
        // 先同步实例缓存，再记录 recipe，供抢占式实例保活使用
        self::syncInstances($account);
        Db::pdo()->prepare(
            'UPDATE instances SET recipe_json = ?, updated_at = ? WHERE account_id = ? AND instance_id = ?'
        )->execute([json_encode($recipe, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), (int)$account['id'], $instanceId]);
        log_event('instance', 'success', "已创建实例 {$instanceId}",
            "名称：{$recipe['name']} / 规格：{$recipe['instance_type']} / 账号：{$account['name']}",
            (int)$account['id'], $instanceId);
        return $instanceId;
    }

    public static function findByRecipe(int $accountId, string $recipeId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT instance_id FROM instances
             WHERE account_id = ? AND tags_json LIKE ?
             ORDER BY CASE WHEN status IN (\'Released\', \'Expired\', \'Deleted\') THEN 1 ELSE 0 END, id
             LIMIT 1'
        );
        $stmt->execute([$accountId, '%"ecs-panel:recipe":"' . $recipeId . '"%']);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function options(int $accountId, string $kind, string $region, string $zone = ''): array
    {
        $cacheFile = storage_path('cache/opt_' . $accountId . '_' . $kind . '_' . preg_replace('/[^a-z0-9-]/i', '', $region) . '.json');
        $ttl = $kind === 'regions' ? 86400 : 3600;
        if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
            $data = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $account = self::account($accountId);
        if ($account === null) {
            throw new \RuntimeException('账号不存在');
        }
        $client = self::clientForAccount($account);
        switch ($kind) {
            case 'regions':
                $data = $client->listRegions();
                break;
            case 'types':
                $data = $client->describeInstanceTypes($region);
                break;
            case 'images':
                $data = $client->describeImages($region);
                break;
            case 'security_groups':
                $data = $client->describeSecurityGroups($region);
                break;
            case 'vswitches':
                $data = $client->describeVSwitches($region, $zone);
                break;
            case 'zones':
                $data = $client->describeZones($region);
                break;
            default:
                throw new \RuntimeException('未知选项类型');
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }
}
