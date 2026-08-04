<?php
declare(strict_types=1);
namespace App\Services;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Db;
use App\Http;

/**
 * 通知服务：Telegram、Server酱、Webhook、SMTP 邮件。
 */
final class NotificationService
{
    /** 按配置的渠道发送，返回 [渠道 => 成功与否] */
    public static function send(string $title, string $message): array
    {
        $channels = array_filter(array_map('trim', explode(',', (string)setting('notify_channels', ''))));
        $results = [];
        foreach ($channels as $channel) {
            try {
                $ok = match ($channel) {
                    'telegram' => self::sendTelegram($title, $message),
                    'serverchan' => self::sendServerChan($title, $message),
                    'webhook' => self::sendWebhook($title, $message),
                    'email' => self::sendEmail($title, $message),
                    default => false,
                };
            } catch (\Throwable $e) {
                $ok = false;
                $results[$channel] = false;
                self::log($channel, $title, false, $e->getMessage());
                continue;
            }
            $results[$channel] = $ok;
            self::log($channel, $title, $ok, $ok ? '' : '发送失败');
        }
        return $results;
    }

    public static function testChannel(string $channel): array
    {
        $title = '【OpsDeck】测试通知';
        $message = "这是一条来自 OpsDeck ECS 面板的测试消息。\n发送时间：" . date('Y-m-d H:i:s');
        try {
            $ok = match ($channel) {
                'telegram' => self::sendTelegram($title, $message),
                'serverchan' => self::sendServerChan($title, $message),
                'webhook' => self::sendWebhook($title, $message),
                'email' => self::sendEmail($title, $message),
                default => false,
            };
        } catch (\Throwable $e) {
            self::log($channel, $title, false, $e->getMessage());
            return [false, $e->getMessage()];
        }
        self::log($channel, $title, $ok, $ok ? '' : '发送失败');
        return [$ok, $ok ? '发送成功' : '发送失败'];
    }

    private static function sendTelegram(string $title, string $message): bool
    {
        $token = trim((string)setting('telegram_bot_token', ''));
        $chatId = trim((string)setting('telegram_chat_id', ''));
        if ($token === '' || $chatId === '') {
            throw new \RuntimeException('未配置 Telegram Bot Token 或 Chat ID');
        }
        $res = Http::postForm('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage', [
            'chat_id' => $chatId,
            'text' => "{$title}\n\n{$message}",
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => 'true',
        ], [], 20);
        $data = json_decode($res['body'], true);
        if (!isset($data['ok']) || $data['ok'] !== true) {
            throw new \RuntimeException($data['description'] ?? ($res['error'] !== null ? $res['error'] : ('HTTP ' . $res['status'])));
        }
        return true;
    }

    private static function sendServerChan(string $title, string $message): bool
    {
        $key = trim((string)setting('serverchan_key', ''));
        if ($key === '') {
            throw new \RuntimeException('未配置 Server酱 SendKey');
        }
        $res = Http::postForm('https://sctapi.ftqq.com/' . rawurlencode($key) . '.send', [
            'title' => $title,
            'desp' => $message,
        ], [], 20);
        $data = json_decode($res['body'], true);
        if (!isset($data['code']) || (int)$data['code'] !== 0) {
            throw new \RuntimeException($data['message'] ?? ($res['error'] !== null ? $res['error'] : ('HTTP ' . $res['status'])));
        }
        return true;
    }

    private static function sendWebhook(string $title, string $message): bool
    {
        $url = trim((string)setting('webhook_url', ''));
        if ($url === '') {
            throw new \RuntimeException('未配置 Webhook 地址');
        }
        $res = Http::request('POST', $url, [
            'Content-Type: application/json',
            'User-Agent: OpsDeck-ECS-Panel/1.0',
        ], json_encode([
            'title' => $title,
            'message' => $message,
            'time' => date('Y-m-d H:i:s'),
            'source' => 'opsdeck',
        ], JSON_UNESCAPED_UNICODE), 20);
        if ($res['status'] >= 400 || $res['error'] !== null) {
            throw new \RuntimeException($res['error'] ?? ('HTTP ' . $res['status']));
        }
        return true;
    }

    private static function sendEmail(string $title, string $message): bool
    {
        $host = trim((string)setting('smtp_host', ''));
        $port = (int)setting('smtp_port', 465);
        $user = trim((string)setting('smtp_user', ''));
        $passEnc = trim((string)setting('smtp_pass_enc', ''));
        $pass = $passEnc !== '' ? Crypt::decrypt($passEnc) : '';
        $from = trim((string)setting('smtp_from', $user));
        $to = trim((string)setting('smtp_to', ''));
        $enc = trim((string)setting('smtp_encryption', 'ssl'));
        if ($host === '' || $to === '') {
            throw new \RuntimeException('未配置 SMTP 服务器或收件人');
        }
        if (!function_exists('stream_socket_client')) {
            throw new \RuntimeException('当前 PHP 环境未启用 stream_socket_client');
        }
        $scheme = $enc === 'ssl' ? 'tls://' : 'tcp://';
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 15);
        if ($fp === false) {
            throw new \RuntimeException('SMTP 连接失败：' . $errstr);
        }
        stream_set_timeout($fp, 15);
        self::smtpExpect($fp, 220);

        self::smtpCmd($fp, 'EHLO opsdeck.local', 250);
        if ($enc === 'tls') {
            self::smtpCmd($fp, 'STARTTLS', 220);
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                fclose($fp);
                throw new \RuntimeException('STARTTLS 握手失败');
            }
            self::smtpCmd($fp, 'EHLO opsdeck.local', 250);
        }
        if ($user !== '') {
            self::smtpCmd($fp, 'AUTH LOGIN', 334);
            self::smtpCmd($fp, base64_encode($user), 334);
            self::smtpCmd($fp, base64_encode($pass), 235);
        }
        self::smtpCmd($fp, 'MAIL FROM:<' . $from . '>', 250);
        self::smtpCmd($fp, 'RCPT TO:<' . $to . '>', 250);
        self::smtpCmd($fp, 'DATA', 354);

        $subject = '=?UTF-8?B?' . base64_encode($title) . '?=';
        $bodyBase64 = chunk_split(base64_encode($message . "\n"), 76, "\r\n");
        $raw = "From: {$from}\r\n"
            . "To: {$to}\r\n"
            . "Subject: {$subject}\r\n"
            . "Date: " . date(DATE_RFC2822) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "\r\n"
            . $bodyBase64
            . "\r\n.";
        self::smtpCmd($fp, $raw, 250);
        self::smtpCmd($fp, 'QUIT', 221);
        fclose($fp);
        return true;
    }

    private static function smtpCmd($fp, string $cmd, int $expect): void
    {
        fwrite($fp, $cmd . "\r\n");
        self::smtpExpect($fp, $expect);
    }

    private static function smtpExpect($fp, int $expect): void
    {
        $line = fgets($fp, 512);
        if ($line === false) {
            throw new \RuntimeException('SMTP 响应读取失败');
        }
        // 多行响应
        while (isset($line[3]) && $line[3] === '-') {
            $line = fgets($fp, 512);
            if ($line === false) {
                break;
            }
        }
        if ((int)substr($line, 0, 3) !== $expect) {
            throw new \RuntimeException('SMTP 错误：' . trim($line) . '（期望 ' . $expect . '）');
        }
    }

    private static function log(string $channel, string $title, bool $ok, string $message): void
    {
        Db::pdo()->prepare(
            'INSERT INTO notify_log (ts, channel, target, title, ok, message) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([date('Y-m-d H:i:s'), $channel, '', $title, $ok ? 1 : 0, $message]);
    }
}
