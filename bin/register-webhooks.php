<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');
$baseUrl = rtrim((string) (Hendy\Env::get('APP_URL') ?: Hendy\Env::get('RENDER_EXTERNAL_URL', '')), '/');

if (!str_starts_with($baseUrl, 'https://')) {
    fwrite(STDOUT, "APP_URL/RENDER_EXTERNAL_URL chưa có HTTPS; bỏ qua đăng ký webhook. Dùng php bin/polling.php khi chạy local.\n");
    exit(0);
}

$bots = [
    'primary' => [
        'token' => Hendy\TelegramAuth::token('primary'),
        'secret' => (string) Hendy\Env::get('TELEGRAM_WEBHOOK_SECRET', ''),
        'path' => '/webhook/telegram',
    ],
    'secondary' => [
        'token' => Hendy\TelegramAuth::token('secondary'),
        'secret' => (string) Hendy\Env::get('TELEGRAM_SECOND_WEBHOOK_SECRET', ''),
        'path' => '/webhook/telegram/secondary',
    ],
];

foreach ($bots as $key => $config) {
    if ($config['token'] === '') {
        if ($key === 'primary') {
            throw new RuntimeException('BOT1_TOKEN là bắt buộc.');
        }
        echo "BOT2_TOKEN trống; bỏ qua bot thứ hai.\n";
        continue;
    }
    $secret = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $config['secret']);
    if ($secret === '') {
        throw new RuntimeException('Thiếu webhook secret cho bot ' . $key . '.');
    }
    $api = new Hendy\TelegramApi($config['token']);
    $api->setWebhook($baseUrl . $config['path'], $secret);
    $me = $api->getMe();
    echo 'Đã đăng ký webhook cho @' . ($me['username'] ?? $key) . " ({$config['path']}).\n";
}
