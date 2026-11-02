<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

$errors = [];
$bot1 = Hendy\TelegramAuth::token('primary');
$bot2 = Hendy\TelegramAuth::token('secondary');
$admin = Hendy\TelegramAuth::adminId();

if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $bot1)) {
    $errors[] = 'BOT1_TOKEN chưa có hoặc không đúng định dạng.';
}
if ($bot2 !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $bot2)) {
    $errors[] = 'BOT2_TOKEN không đúng định dạng.';
}
if ($bot1 !== '' && $bot2 !== '' && hash_equals($bot1, $bot2)) {
    $errors[] = 'BOT1_TOKEN và BOT2_TOKEN phải là hai token khác nhau.';
}
if (!preg_match('/^\d{5,20}$/', $admin)) {
    $errors[] = 'ADMIN_ID phải là Telegram user ID dạng số.';
}
if (Hendy\Env::get('DATABASE_URL') === null) {
    $errors[] = 'Thiếu DATABASE_URL.';
}

if ($errors !== []) {
    fwrite(STDERR, "Cấu hình chưa hợp lệ:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Cấu hình hợp lệ. BOT2_TOKEN: " . ($bot2 !== '' ? 'đã bật' : 'đang tắt') . ".\n";
