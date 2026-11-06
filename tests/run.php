<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';
require dirname(__DIR__) . '/app/BotIdentity.php';
require dirname(__DIR__) . '/app/BotMenuDefinition.php';
require dirname(__DIR__) . '/app/WebhookSecurity.php';
require dirname(__DIR__) . '/app/TelegramApi.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/WebServiceContract.php';
require dirname(__DIR__) . '/app/WebServiceKernel.php';
require dirname(__DIR__) . '/app/WebServices/SystemWebService.php';

use Hendy\VideoJobService;
use Hendy\TelegramAuth;
use Hendy\BotIdentity;
use Hendy\BotMenuDefinition;
use Hendy\WebhookSecurity;
use Hendy\WebServiceKernel;

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
$assert(BotIdentity::normalizeUsername('@HendyCyber_Bot') === 'HendyCyber_Bot', 'username bot chuẩn hóa dấu @');
$assert(BotIdentity::normalizeUsername('not a username') === '', 'username bot sai định dạng bị loại');
$assert(BotIdentity::tokenFingerprintMatches(hash('sha256', 'sample-token-for-tests'), 'sample-token-for-tests'), 'token fingerprint khớp');
$assert(!BotIdentity::tokenFingerprintMatches(hash('sha256', 'old-token'), 'new-token'), 'token mới không dùng identity cache của token cũ');
$assert(array_column(BotMenuDefinition::ITEMS, 'action') === ['live','otp','wallet','vip','codes','customer','group','news','vip-coming-soon'], 'menu chứa đủ chín chức năng mới');
$assert(BotMenuDefinition::LAYOUT === [['live','otp'],['wallet','vip'],['codes'],['customer'],['group','news'],['vip-coming-soon']], 'layout đúng sáu hàng theo mẫu');
$webhookSecret = 'sample-webhook-secret-for-tests-000001';
$assert(WebhookSecurity::verifySecret($webhookSecret, $webhookSecret), 'webhook secret đúng được chấp nhận');
$assert(!WebhookSecurity::verifySecret($webhookSecret, 'wrong-webhook-secret-for-tests-000001'), 'webhook secret sai bị từ chối');
$hmacBody = '{"update_id":123,"message":{"text":"hello"}}';
$hmac = 'sha256=' . hash_hmac('sha256', $hmacBody, $webhookSecret);
$assert(WebhookSecurity::verifyHmac($hmacBody, $hmac, $webhookSecret), 'HMAC callback hợp lệ được chấp nhận');
$assert(!WebhookSecurity::verifyHmac($hmacBody, 'sha256=' . str_repeat('0', 64), $webhookSecret), 'HMAC callback sai bị từ chối');
$assert(WebhookSecurity::decodeJson($hmacBody, 1024)['update_id'] === 123, 'JSON webhook được giải mã');

$previousAdminId = getenv('ADMIN_ID');
$previousAdminIds = getenv('ADMIN_IDS');
putenv('ADMIN_ID=9500000001');
putenv('ADMIN_IDS=9500000002,9500000001,invalid');
$assert(TelegramAuth::adminIds() === ['9500000001', '9500000002'], 'admin allowlist merges and deduplicates ADMIN_ID and ADMIN_IDS');
$assert(TelegramAuth::isAdmin('9500000002'), 'secondary admin is authorized by shared admin allowlist');
if ($previousAdminId === false) putenv('ADMIN_ID'); else putenv('ADMIN_ID=' . $previousAdminId);
if ($previousAdminIds === false) putenv('ADMIN_IDS'); else putenv('ADMIN_IDS=' . $previousAdminIds);


$assert(WebServiceKernel::handles('/api/services/system/health'), 'Web Service kernel nhận route đã đăng ký');
$assert(!WebServiceKernel::handles('/api/not-services/system/health'), 'Web Service kernel không nhận route ngoài namespace');
$assert(in_array('system', WebServiceKernel::registeredServices(), true), 'System Web Service được đăng ký trong allow-list');
$invalidServicePathRejected = false;
try {
    WebServiceKernel::dispatch('GET', '/api/services/unknown/health', [], []);
} catch (RuntimeException) {
    $invalidServicePathRejected = true;
}
$assert($invalidServicePathRejected, 'Web Service không được đăng ký sẽ bị từ chối');

$assert(VideoJobService::isAllowedMediaUrl('https://youtu.be/dQw4w9WgXcQ'), 'chấp nhận URL YouTube HTTPS');
$assert(VideoJobService::isAllowedMediaUrl('https://www.tiktok.com/@creator/video/123'), 'chấp nhận URL TikTok HTTPS');
$assert(!VideoJobService::isAllowedMediaUrl('http://youtube.com/watch?v=abc'), 'từ chối URL video HTTP');
$assert(!VideoJobService::isAllowedMediaUrl('https://youtube.com.evil.example/watch?v=abc'), 'từ chối domain giả mạo');
$assert(!VideoJobService::isAllowedMediaUrl('https://127.0.0.1/admin'), 'từ chối IP nội bộ thay vì domain video');

$token = sprintf('123456789:%s', str_repeat('A', 40));
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
