<?php
declare(strict_types=1);
namespace Hendy;

final class Realtime
{
    public static function publish(string $type, array $payload = [], string $audience = 'admin'): void
    {
        $type = preg_replace('/[^a-z0-9._-]/i', '', $type) ?: 'system.event';
        Database::run('INSERT INTO realtime_events (event_type, audience, payload, created_at) VALUES (?, ?, ?::jsonb, NOW())',
            [substr($type, 0, 80), $audience, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    public static function issueAdminTicket(string $adminId): string
    {
        $ticket = bin2hex(random_bytes(32));
        Database::run("INSERT INTO realtime_tickets (ticket_hash, admin_user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL '60 seconds')",
            [hash('sha256', $ticket), $adminId]);
        return $ticket;
    }
    public static function consumeAdminTicket(string $ticket): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $ticket)) return false;
        $row = Database::one('UPDATE realtime_tickets SET consumed_at = NOW() WHERE ticket_hash = ? AND consumed_at IS NULL AND expires_at > NOW() RETURNING admin_user_id', [hash('sha256', $ticket)]);
        return $row !== null && hash_equals((string) Env::get('ADMIN_ID', ''), (string) $row['admin_user_id']);
    }
}
