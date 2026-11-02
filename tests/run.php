<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/TelegramApi.php';

use Hendy\TelegramAuth;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Test thất bại: ' . $message);
    }
};

$assert(TelegramAuth::safeEquals('same-secret', 'same-secret'), 'so sánh chuỗi đúng');
$assert(!TelegramAuth::safeEquals('different', 'same-secret'), 'so sánh chuỗi khác');
$assert(!TelegramAuth::safeEquals('', ''), 'secret rỗng bị từ chối');

$token = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi';
$authDate = time();
$user = json_encode(['id' => 12345678, 'first_name' => 'Hendy'], JSON_UNESCAPED_UNICODE);
$params = [
    'auth_date' => (string) $authDate,
    'query_id' => 'AAEAAAE',
    'user' => (string) $user,
];
ksort($params, SORT_STRING);
$checkString = implode("\n", array_map(
    static fn ($key, $value): string => $key . '=' . $value,
    array_keys($params),
    array_values($params)
));
$secret = hash_hmac('sha256', $token, 'WebAppData', true);
$hash = hash_hmac('sha256', $checkString, $secret);
$initData = http_build_query([...$params, 'hash' => $hash]);
$validated = TelegramAuth::validateInitData($initData, $token);
$assert((string) $validated['id'] === '12345678', 'xác thực Telegram initData hợp lệ');

$invalid = str_replace($hash, str_repeat('0', 64), $initData);
$thrown = false;
try {
    TelegramAuth::validateInitData($invalid, $token);
} catch (Throwable) {
    $thrown = true;
}
$assert($thrown, 'initData bị sửa phải bị từ chối');

$expiredParams = [
    'auth_date' => (string) ($authDate - 90000),
    'user' => (string) $user,
];
ksort($expiredParams, SORT_STRING);
$expiredString = implode("\n", array_map(
    static fn ($key, $value): string => $key . '=' . $value,
    array_keys($expiredParams),
    array_values($expiredParams)
));
$expiredHash = hash_hmac('sha256', $expiredString, $secret);
$expiredData = http_build_query([...$expiredParams, 'hash' => $expiredHash]);
$thrown = false;
try {
    TelegramAuth::validateInitData($expiredData, $token);
} catch (Throwable) {
    $thrown = true;
}
$assert($thrown, 'initData quá hạn phải bị từ chối');

echo "Đã chạy {$checks} kiểm tra PHP.\n";
