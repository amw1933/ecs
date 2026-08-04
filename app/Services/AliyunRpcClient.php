<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Http;

/**
 * 阿里云 RPC 风格 OpenAPI 客户端（纯 PHP 实现，无 SDK 依赖）。
 * 参考官方签名规范：HMAC-SHA1 + 规范化查询串。
 */
final class AliyunRpcClient
{
    private string $accessKeyId;
    private string $accessKeySecret;
    private string $endpoint;
    private string $version;

    public function __construct(string $accessKeyId, string $accessKeySecret, string $endpoint, string $version)
    {
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->endpoint = $endpoint;
        $this->version = $version;
    }

    public function call(string $action, array $params = [], int $timeout = 25): array
    {
        $common = [
            'AccessKeyId' => $this->accessKeyId,
            'Action' => $action,
            'Format' => 'JSON',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => $this->version,
        ];
        $all = array_merge($common, $params);
        ksort($all, SORT_STRING);

        $pairs = [];
        foreach ($all as $k => $v) {
            $pairs[] = self::percentEncode((string)$k) . '=' . self::percentEncode((string)$v);
        }
        $canonical = implode('&', $pairs);
        $stringToSign = 'GET&%2F&' . self::percentEncode($canonical);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));
        $url = 'https://' . $this->endpoint . '/?' . $canonical . '&Signature=' . self::percentEncode($signature);

        $res = Http::request('GET', $url, ['Accept: application/json'], null, $timeout);
        if ($res['error'] !== null) {
            throw new \RuntimeException('请求失败：' . $res['error']);
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('响应解析失败（HTTP ' . $res['status'] . '）');
        }
        if (isset($data['Code']) && !in_array((string)$data['Code'], ['200', 'Success'], true)) {
            $code = (string)$data['Code'];
            $msg = isset($data['Message']) ? (string)$data['Message'] : '未知错误';
            throw new \RuntimeException('[' . $action . '] ' . $code . ': ' . $msg);
        }
        if (isset($data['Success']) && $data['Success'] === false) {
            throw new \RuntimeException('[' . $action . '] ' . ($data['Message'] ?? '请求失败'));
        }
        return $data;
    }

    /** 阿里云签名使用的百分号编码规则 */
    public static function percentEncode(string $s): string
    {
        return str_replace(['+', '*'], ['%20', '%2A'], rawurlencode($s));
    }
}
