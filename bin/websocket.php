<?php
declare(strict_types=1);

use Hendy\Database;
use Hendy\Env;
use Hendy\Realtime;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

require dirname(__DIR__) . '/vendor/autoload.php';
foreach (['Env.php', 'Database.php', 'Realtime.php'] as $file) {
    require dirname(__DIR__) . '/app/' . $file;
}
Env::load(dirname(__DIR__) . '/.env');

/**
 * The primary web service waits for PostgreSQL and applies the idempotent schema
 * during startup. The realtime service waits for that schema instead of racing migrations.
 */
$waitSeconds = max(0, Env::int('DB_STARTUP_WAIT_SECONDS', 90));
$deadline = microtime(true) + $waitSeconds;
$lastDatabaseError = null;
$schemaReady = false;

do {
    try {
        Database::check();
        $schemaReady = Database::value("SELECT to_regclass('public.realtime_events')") !== null;
        if ($schemaReady) {
            break;
        }
    } catch (Throwable $error) {
        $lastDatabaseError = $error;
    }

    if (microtime(true) >= $deadline) {
        $reason = $lastDatabaseError !== null ? ' Chi tiết: ' . $lastDatabaseError->getMessage() : '';
        throw new RuntimeException('PostgreSQL hoặc bảng realtime_events chưa sẵn sàng sau thời gian chờ cấu hình.' . $reason);
    }
    sleep(2);
} while (true);

final class AdminRealtimeServer implements MessageComponentInterface
{
    private SplObjectStorage $clients;
    private int $lastId = 0;

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
        $this->lastId = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM realtime_events');
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $conn->authenticated = false;
        $this->clients->attach($conn);
        $conn->send(json_encode(['type' => 'auth.required']));
    }

    public function onMessage(ConnectionInterface $from, \Ratchet\RFC6455\Messaging\MessageInterface $msg): void
    {
        $data = json_decode((string) $msg, true);
        if (!$from->authenticated && is_array($data) && ($data['type'] ?? '') === 'auth') {
            if (Realtime::consumeAdminTicket((string) ($data['ticket'] ?? ''))) {
                $from->authenticated = true;
                $from->send(json_encode(['type' => 'realtime.ready']));
            } else {
                $from->send(json_encode(['type' => 'auth.failed']));
                $from->close();
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log('WebSocket: ' . $e->getMessage());
        $conn->close();
    }

    public function publish(): void
    {
        $events = Database::all(
            'SELECT id, event_type, payload, created_at FROM realtime_events WHERE id > ? ORDER BY id ASC LIMIT 250',
            [$this->lastId]
        );

        foreach ($events as $event) {
            $this->lastId = max($this->lastId, (int) $event['id']);
            $message = json_encode([
                'type' => $event['event_type'],
                'payload' => is_string($event['payload']) ? json_decode($event['payload'], true) : $event['payload'],
                'createdAt' => $event['created_at'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($this->clients as $client) {
                if ($client->authenticated) {
                    $client->send($message);
                }
            }
        }

        Database::run("DELETE FROM realtime_events WHERE created_at < NOW() - INTERVAL '2 days'");
        Database::run("DELETE FROM realtime_tickets WHERE expires_at < NOW() OR consumed_at IS NOT NULL");
    }
}

$host = (string) Env::get('WEBSOCKET_BIND', '0.0.0.0');
$port = max(1, min(65535, Env::int('WEBSOCKET_PORT', 10000)));
$server = new AdminRealtimeServer();
$socket = new SocketServer($host . ':' . $port);
$io = IoServer::factory(new HttpServer(new WsServer($server)), $socket, Loop::get());

Loop::addPeriodicTimer(1.0, static function () use ($server): void {
    try {
        $server->publish();
    } catch (Throwable $error) {
        error_log('Realtime poll failed: ' . $error->getMessage());
    }
});

echo "Hendy realtime WebSocket listening on {$host}:{$port}\n";
$io->run();
