<?php
declare(strict_types=1);
namespace App;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

final class Http
{
    /**
     * 发起 HTTP 请求，优先 cURL，退回流式封装。
     *
     * @return array{status:int, body:string, error:?string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 25
    ): array {
        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $headers, $body, $timeout);
        }
        return self::viaStreams($method, $url, $headers, $body, $timeout);
    }

    private static function viaCurl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'OpsDeck-ECS-Panel/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $proxy = self::proxyFor($url);
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [
            'status' => $status,
            'body' => $response === false ? '' : (string)$response,
            'error' => $err === '' ? null : $err,
        ];
    }

    private static function viaStreams(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $headerStr = '';
        foreach ($headers as $h) {
            $headerStr .= $h . "\r\n";
        }
        $opts = [
            'http' => [
                'method' => $method,
                'header' => $headerStr,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        $proxy = self::proxyFor($url);
        if ($proxy !== '') {
            $opts['http']['proxy'] = $proxy;
            $opts['http']['request_fulluri'] = true;
        }
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }
        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return [
            'status' => $status,
            'body' => $response === false ? '' : $response,
            'error' => $response === false ? '无法连接到远程服务器' : null,
        ];
    }

    public static function postForm(string $url, array $fields, array $headers = [], int $timeout = 25): array
    {
        $body = http_build_query($fields);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return self::request('POST', $url, $headers, $body, $timeout);
    }

    /**
     * 代理配置：优先使用设置里的 http_proxy，其次读环境变量；
     * 阿里云国内接口保持直连，不走代理。
     */
    private static function proxyFor(string $url): string
    {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if (str_ends_with($host, '.aliyuncs.com')) {
            return '';
        }
        $proxy = trim((string)Db::setting('http_proxy', ''));
        if ($proxy === '') {
            foreach (['HTTPS_PROXY', 'HTTP_PROXY', 'https_proxy', 'http_proxy'] as $key) {
                $env = getenv($key);
                if (is_string($env) && trim($env) !== '') {
                    $proxy = trim($env);
                    break;
                }
            }
        }
        return $proxy;
    }
}
