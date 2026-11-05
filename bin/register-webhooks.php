<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

if (!Hendy\Env::bool('WEBHOOK_AUTO_REGISTER', true)) {
    fwrite(STDOUT, "WEBHOOK_AUTO_REGISTER=false; bỏ qua đăng ký webhook tự động.\n");
    exit(0);
}

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

$failed = [];

foreach ($bots as $key => $config) {
    if ($config['token'] === '') {
        if ($key === 'primary') {
            $failed[] = 'BOT1_TOKEN đang trống; bot chính chưa thể nhận update.';
        } else {
            fwrite(STDOUT, "BOT2_TOKEN trống; bỏ qua bot thứ hai.\n");
        }
        continue;
    }

    $secret = (string) $config['secret'];
    if (!preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $secret)) {
        $failed[] = 'Thiếu webhook secret cho bot ' . $key . '.';
        continue;
    }

    try {
        $api = new Hendy\TelegramApi($config['token']);

        // Call getMe first to verify the bot token before changing webhook settings.
        $me = $api->getMe();
        $api->setWebhook($baseUrl . $config['path'], $secret);

        fwrite(
            STDOUT,
            'Đã đăng ký webhook cho @' . ($me['username'] ?? $key) . " ({$config['path']}).\n"
        );
    } catch (Throwable $error) {
        $message = $error->getMessage();

        if (preg_match('/(?:Telegram API lỗi )?401\b|Unauthorized/i', $message)) {
            $failed[] = $key . ': Telegram từ chối token (401 Unauthorized). Token trong Render Environment không đúng, đã bị thu hồi hoặc thuộc bot khác. Hãy tạo/lấy token hiện hành từ @BotFather và cập nhật đúng biến ' . ($key === 'primary' ? 'BOT1_TOKEN' : 'BOT2_TOKEN') . '.';
        } else {
            $failed[] = $key . ': không đăng ký được webhook: ' . $message;
        }
    }
}

if ($failed !== []) {
    foreach ($failed as $warning) {
        fwrite(STDERR, "CẢNH BÁO WEBHOOK: " . $warning . "\n");
    }
    fwrite(STDERR, "Web Service vẫn tiếp tục khởi động. Bot chưa đăng ký được webhook sẽ chưa nhận update cho tới khi sửa cấu hình và chạy lại đăng ký.\n");
}
