<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class TelegramApi
{
    public function __construct(private readonly string $token)
    {
        if ($token === '' || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new RuntimeException('Token Telegram không hợp lệ hoặc chưa được cấu hình.');
        }
    }

    public function call(string $method, array $parameters = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP extension curl chưa được cài đặt.');
        }

        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Không thể khởi tạo kết nối Telegram.');
        }

        $body = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 35,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $error !== '') {
            throw new RuntimeException('Kết nối Telegram thất bại: ' . $error);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false) || $status < 200 || $status >= 300) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? 'Telegram API trả về lỗi.') : 'Phản hồi Telegram không hợp lệ.';
            throw new RuntimeException($description);
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : ['value' => $decoded['result'] ?? null];
    }

    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            ...$options,
        ]);
    }

    public function sendPhoto(string $chatId, string $photo, string $caption, array $options = []): array
    {
        return $this->call('sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            ...$options,
        ]);
    }

    public function answerCallback(string $callbackId, string $text = ''): void
    {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => false,
        ]);
    }

    public function setWebhook(string $url, string $secret): void
    {
        $secret = preg_replace('/[^A-Za-z0-9_-]/', '', $secret) ?? '';
        if ($secret === '') {
            throw new RuntimeException('Webhook secret không hợp lệ.');
        }
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => substr($secret, 0, 256),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function getUpdates(int $offset = 0, int $timeout = 20): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        return array_is_list($result) ? $result : [];
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }
}
