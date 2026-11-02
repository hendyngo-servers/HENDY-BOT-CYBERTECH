<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';

use Hendy\Database;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');

$timeout = max(0, min(300, Env::int('DB_STARTUP_WAIT_SECONDS', 120)));
$deadline = microtime(true) + $timeout;
$attempt = 0;
$lastError = null;
$nextNotice = 0.0;

do {
    $attempt++;
    try {
        Database::check();
        fwrite(STDOUT, "PostgreSQL đã sẵn sàng sau {$attempt} lần thử.\n");
        exit(0);
    } catch (Throwable $error) {
        $lastError = $error;
    }

    $now = microtime(true);
    if ($now >= $deadline) {
        break;
    }

    if ($now >= $nextNotice) {
        fwrite(STDOUT, "Đang chờ PostgreSQL... (lần thử {$attempt}/, tối đa {$timeout}s)\n");
        $nextNotice = $now + 10;
    }

    sleep(2);
} while (true);

$errorType = $lastError !== null ? $lastError::class : 'UnknownError';
fwrite(
    STDERR,
    "Không thể kết nối PostgreSQL sau {$timeout}s ({$errorType}). " .
    "Kiểm tra DATABASE_URL, workspace/region, External URL + TLS và IP allow-list. " .
    "Không ghi connection string hoặc mật khẩu vào log.\n"
);
exit(1);
