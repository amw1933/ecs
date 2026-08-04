<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

final class Crypt
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $envKey = (string)cfg('app_secret', '');
        if ($envKey !== '') {
            $raw = base64_decode($envKey, true);
            if ($raw !== false && strlen($raw) === 32) {
                return self::$key = $raw;
            }
            if (strlen($envKey) === 32) {
                return self::$key = $envKey;
            }
        }
        $file = storage_path('app.key');
        if (is_file($file)) {
            $raw = base64_decode(trim((string)file_get_contents($file)), true);
            if ($raw !== false && strlen($raw) === 32) {
                return self::$key = $raw;
            }
        }
        $key = random_bytes(32);
        @file_put_contents($file, base64_encode($key), LOCK_EX);
        @chmod($file, 0600);
        return self::$key = $key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('加密失败：请确认已启用 openssl 扩展');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('密钥密文无效');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('密钥解密失败');
        }
        return $plain;
    }
}
