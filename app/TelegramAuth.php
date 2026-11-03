<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

final class TelegramAuth
{
    public static function token(string $botKey = 'primary'): string
    {
        $name = $botKey === 'secondary' ? 'BOT2_TOKEN' : 'BOT1_TOKEN';
        return trim((string) Env::get($name, ''));
    }

    public static function adminIds(): array
    {
        $configured = [
            trim((string) Env::get('ADMIN_ID', '')),
            ...array_map('trim', explode(',', (string) Env::get('ADMIN_IDS', ''))),
        ];
        $ids = [];
        foreach ($configured as $id) {
            if (preg_match('/^[0-9]{5,20}$/', $id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function adminId(): string
    {
        return self::adminIds()[0] ?? '';
    }

    public static function isAdmin(string $telegramId): bool
    {
        return $telegramId !== '' && in_array($telegramId, self::adminIds(), true);
    }

    public static function safeEquals(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && hash_equals($right, $left);
    }

    public static function validateInitData(string $initData, string $botToken): array
    {
        if ($initData === '' || strlen($initData) > 8192 || $botToken === '') {
            throw new RuntimeException('Thiếu dữ liệu xác thực Telegram.');
        }

        parse_str($initData, $params);
        $hash = strtolower((string) ($params['hash'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('Chữ ký Telegram không hợp lệ.');
        }

        $authDate = filter_var($params['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $now = time();
        $maxAge = max(60, min(604800, Env::int('TELEGRAM_INIT_DATA_MAX_AGE_SECONDS', 86400)));
        if ($authDate === false || $authDate > $now + 60 || $now - $authDate > $maxAge) {
            throw new RuntimeException('Phiên Telegram đã hết hạn.');
        }

        unset($params['hash']);
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $pairs[] = $key . '=' . (string) $value;
            }
        }
        $checkString = implode("\n", $pairs);

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $checkString, $secret);
        if (!hash_equals($calculated, $hash)) {
            throw new RuntimeException('Không thể xác minh chữ ký Telegram.');
        }

        $user = json_decode((string) ($params['user'] ?? ''), true);
        if (!is_array($user) || !preg_match('/^\d{5,20}$/', (string) ($user['id'] ?? ''))) {
            throw new RuntimeException('Không tìm thấy Telegram user ID hợp lệ.');
        }
        return $user;
    }

    public static function authenticateRequest(): array
    {
        $requestedBotKey = trim((string) ($_SERVER['HTTP_X_TELEGRAM_BOT_KEY'] ?? 'primary'));
        if (!in_array($requestedBotKey, ['primary', 'secondary'], true)) {
            throw new RuntimeException('Bot Key không hợp lệ.');
        }
        $botKey = $requestedBotKey;
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $initData = str_starts_with($authorization, 'tma ')
            ? substr($authorization, 4)
            : (string) ($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '');

        $token = self::token($botKey);
        if ($token === '') {
            throw new RuntimeException('Bot được yêu cầu chưa được cấu hình.');
        }

        $user = self::validateInitData($initData, $token);
        $user['_bot_key'] = $botKey;
        $user['_is_admin'] = self::isAdmin((string) ($user['id'] ?? ''));
        return $user;
    }
}
