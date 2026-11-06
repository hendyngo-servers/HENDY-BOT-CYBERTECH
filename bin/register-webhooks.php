<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/BotIdentity.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

$autoRegister = Hendy\Env::bool('WEBHOOK_AUTO_REGISTER', true);
$baseUrl = rtrim((string) (Hendy\Env::get('APP_URL') ?: Hendy\Env::get('RENDER_EXTERNAL_URL', '')), '/');
$baseParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
$validBaseUrl = is_array($baseParts)
    && strtolower((string) ($baseParts['scheme'] ?? '')) === 'https'
    && !empty($baseParts['host'])
    && !isset($baseParts['user'])
    && !isset($baseParts['pass']);

$bots = [
    'primary' => [
        'token' => Hendy\TelegramAuth::token('primary'),
        'secret' => Hendy\TelegramAuth::webhookSecret('primary'),
        'path' => '/webhook/telegram',
    ],
    'secondary' => [
        'token' => Hendy\TelegramAuth::token('secondary'),
        'secret' => Hendy\TelegramAuth::webhookSecret('secondary'),
        'path' => '/webhook/telegram/secondary',
    ],
];

$failed = [];

foreach ($bots as $key => $config) {
    if ($config['token'] === '') {
        if ($key === 'secondary') {
            try {
                Hendy\Database::run('DELETE FROM bot_runtime_identity WHERE bot_key = ?', ['secondary']);
            } catch (Throwable) {
                // The optional bot may never have been provisioned.
            }
            fwrite(STDOUT, "BOT2_TOKEN đang trống; bỏ qua bot thử nghiệm.\n");
        } else {
            $failed[] = 'BOT1_TOKEN đang trống; chưa thể đồng bộ danh tính bot chính.';
        }
        continue;
    }

    try {
        $api = new Hendy\TelegramApi($config['token']);

        // getMe() is the source of truth for the current username after a token change.
        $me = $api->getMe();
        $username = Hendy\BotIdentity::sync($key, $config['token'], $me);
        fwrite(STDOUT, "Đã tự động đồng bộ username @{$username} cho {$key}.\n");

        if (!$autoRegister) {
            continue;
        }
        if (!$validBaseUrl) {
            $failed[] = $key . ': chưa đăng ký webhook vì APP_URL/RENDER_EXTERNAL_URL chưa là HTTPS hợp lệ.';
            continue;
        }

        $secret = $config['secret'];
        if (!preg_match('~^[A-Za-z0-9_-]{32,256}$~D', $secret)) {
            $failed[] = 'Thiếu webhook secret hợp lệ cho bot ' . $key . '.';
            continue;
        }

        $api->setWebhook($baseUrl . $config['path'], $secret);
        fwrite(STDOUT, "Đã đăng ký webhook {$config['path']} cho @{$username}.\n");
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if (preg_match('/(?:Telegram API lỗi )?401\b|Unauthorized/i', $message)) {
            $failed[] = $key . ': Telegram từ chối token (401 Unauthorized). Hãy kiểm tra hoặc tạo lại biến ' .
                ($key === 'primary' ? 'BOT1_TOKEN' : 'BOT2_TOKEN') . ' trong Render Environment.';
        } else {
            $failed[] = $key . ': không đồng bộ được danh tính/đăng ký webhook: ' . $message;
        }
    }
}

if (!$autoRegister) {
    fwrite(STDOUT, "WEBHOOK_AUTO_REGISTER=false; username vẫn được đồng bộ từ getMe(), bỏ qua đăng ký webhook.\n");
}
if ($autoRegister && !$validBaseUrl) {
    fwrite(STDERR, "APP_URL/RENDER_EXTERNAL_URL cần HTTPS để đăng ký webhook.\n");
}
if ($failed !== []) {
    foreach ($failed as $warning) {
        fwrite(STDERR, "CẢNH BÁO BOT: " . $warning . "\n");
    }
    fwrite(STDERR, "Web Service vẫn tiếp tục khởi động. Kiểm tra lại token, database và webhook secrets trong Render Environment.\n");
}
