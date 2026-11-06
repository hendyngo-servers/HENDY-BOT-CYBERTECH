<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;

/**
 * Bot usernames come from Telegram getMe() and are cached in PostgreSQL.
 * The bot token itself is never stored in this table.
 */
final class BotIdentity
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function normalizeUsername(string $value): string
    {
        $username = ltrim(trim($value), '@');
        return preg_match('~^[A-Za-z][A-Za-z0-9_]{4,31}$~D', $username) === 1 ? $username : '';
    }

    public static function extractUsername(array $telegramMe): string
    {
        if (($telegramMe['is_bot'] ?? false) !== true
            || !preg_match('~^[0-9]{5,20}$~D', (string) ($telegramMe['id'] ?? ''))) {
            throw new RuntimeException('Telegram getMe() không trả về danh tính bot hợp lệ.');
        }
        $username = self::normalizeUsername((string) ($telegramMe['username'] ?? ''));
        if ($username === '') {
            throw new RuntimeException('Bot Telegram chưa có username hợp lệ.');
        }
        return $username;
    }

    public static function tokenFingerprintMatches(string $storedFingerprint, string $token): bool
    {
        if ($token === '' || !preg_match('~^[a-f0-9]{64}$~D', $storedFingerprint)) {
            return false;
        }
        return hash_equals($storedFingerprint, hash('sha256', $token));
    }

    public static function sync(string $botKey, string $token, array $telegramMe): string
    {
        $key = $botKey === 'secondary' ? 'secondary' : 'primary';
        if ($token === '') {
            throw new RuntimeException('Không thể đồng bộ username khi token rỗng.');
        }
        $username = self::extractUsername($telegramMe);
        $botId = (string) $telegramMe['id'];
        $fingerprint = hash('sha256', $token);

        Database::run(
            'INSERT INTO bot_runtime_identity (bot_key, bot_id, username, token_fingerprint, updated_at) ' .
            'VALUES (?, ?, ?, ?, NOW()) ' .
            'ON CONFLICT (bot_key) DO UPDATE SET bot_id = EXCLUDED.bot_id, username = EXCLUDED.username, ' .
            'token_fingerprint = EXCLUDED.token_fingerprint, updated_at = NOW()',
            [$key, $botId, $username, $fingerprint]
        );
        self::$cache[$key] = $username;
        return $username;
    }

    public static function username(string $botKey = 'primary'): string
    {
        $key = $botKey === 'secondary' ? 'secondary' : 'primary';
        $token = TelegramAuth::token($key);
        if ($token === '') {
            return '';
        }
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $row = Database::one(
            'SELECT username, token_fingerprint FROM bot_runtime_identity WHERE bot_key = ?',
            [$key]
        );
        if ($row === null || !self::tokenFingerprintMatches((string) $row['token_fingerprint'], $token)) {
            return '';
        }
        return self::$cache[$key] = self::normalizeUsername((string) $row['username']);
    }
}
