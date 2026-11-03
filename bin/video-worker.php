<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/VideoJobService.php';

use Hendy\Database;
use Hendy\Env;
use Hendy\TelegramApi;
use Hendy\TelegramAuth;
use Hendy\VideoJobService;

Env::load(dirname(__DIR__) . '/.env');

$once = in_array('--once', $argv, true);
$pollSeconds = max(3, min(60, Env::int('VIDEO_WORKER_POLL_SECONDS', 5)));
$tokenCache = [];

$notifyUser = static function (array $job, string $message) use (&$tokenCache): void {
    $botKey = ($job['bot_key'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary';
    $token = $tokenCache[$botKey] ??= TelegramAuth::token($botKey);
    if ($token === '') {
        return;
    }
    try {
        (new TelegramApi($token))->sendMessage((string) $job['user_id'], $message);
    } catch (Throwable $error) {
        error_log('Video worker: không gửi được thông báo user: ' . $error->getMessage());
    }
};

fwrite(STDOUT, "Hendy Video Worker khởi động. Chế độ: " . ($once ? 'once' : 'continuous') . ".\n");

do {
    try {
        $job = VideoJobService::dispatchOne();
        if ($job === null) {
            if ($once) {
                exit(0);
            }
            sleep($pollSeconds);
            continue;
        }

        $shortId = substr((string) $job['id'], 0, 8);
        if (($job['status'] ?? '') === 'failed') {
            $notifyUser($job, '❌ Video job ' . $shortId . ' không thể chuyển tới nhà xử lý video. Vui lòng liên hệ CSKH hoặc thử lại sau.');
            fwrite(STDERR, "Job {$shortId}: dispatch failed.\n");
        } else {
            fwrite(STDOUT, "Job {$shortId}: đã gửi tới nhà xử lý video, đang chờ callback.\n");
        }
    } catch (Throwable $error) {
        // Do not print the provider URL, credentials, callback signature or payload.
        error_log('Hendy Video Worker: ' . $error->getMessage());
        if ($once) {
            exit(1);
        }
    }

    if ($once) {
        exit(0);
    }
    sleep($pollSeconds);
} while (true);
