<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/TelegramAuth.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');

$errors = [];
$bot1 = Hendy\TelegramAuth::token('primary');
$bot2 = Hendy\TelegramAuth::token('secondary');
$admin = Hendy\TelegramAuth::adminId();

$botTokenPattern = '~\A[0-9]+:[A-Za-z0-9_-]{20,}\z~D';
$adminPattern = '~\A[0-9]{5,20}\z~D';
$secretPattern = '~\A[A-Za-z0-9_-]{32,256}\z~D';

// Use explicit ASCII ranges and ~ delimiters. This avoids shell/YAML escaping
// ambiguities and the PCRE shorthand escape differences users encountered.
if (preg_match($botTokenPattern, $bot1) !== 1) {
    $errors[] = 'BOT1_TOKEN chưa có hoặc không đúng định dạng.';
}
if ($bot2 !== '' && preg_match($botTokenPattern, $bot2) !== 1) {
    $errors[] = 'BOT2_TOKEN không đúng định dạng.';
}
if ($bot1 !== '' && $bot2 !== '' && hash_equals($bot1, $bot2)) {
    $errors[] = 'BOT1_TOKEN và BOT2_TOKEN phải là hai token khác nhau.';
}
if (preg_match($adminPattern, $admin) !== 1) {
    $errors[] = 'ADMIN_ID phải là Telegram user ID dạng số.';
}

$dbUrl = Hendy\Env::get('DATABASE_URL');
$dbParts = false;
if ($dbUrl !== null) {
    try {
        $dbParts = parse_url($dbUrl);
    } catch (Throwable) {
        $dbParts = false;
    }
}
$dbScheme = is_array($dbParts) ? strtolower((string) ($dbParts['scheme'] ?? '')) : '';
$dbPath = is_array($dbParts) ? (string) ($dbParts['path'] ?? '') : '';
if (
    !is_array($dbParts) ||
    !in_array($dbScheme, ['postgres', 'postgresql'], true) ||
    empty($dbParts['host']) ||
    trim($dbPath, '/') === ''
) {
    $errors[] = 'DATABASE_URL phải là URI PostgreSQL dạng postgresql://USER:PASSWORD@HOST:5432/DB. Không dán câu lệnh PGPASSWORD=... psql -h ... vào biến này.';
}

$sslValue = (string) Hendy\Env::get('DATABASE_SSL', 'true');
if (!in_array(strtolower($sslValue), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
    $errors[] = 'DATABASE_SSL chỉ nhận true hoặc false.';
}

$autoWebhook = Hendy\Env::bool('WEBHOOK_AUTO_REGISTER', true);
if ($autoWebhook) {
    $appUrl = trim((string) (Hendy\Env::get('APP_URL') ?: Hendy\Env::get('RENDER_EXTERNAL_URL', '')));
    $appParts = $appUrl !== '' ? parse_url($appUrl) : false;
    if (!is_array($appParts) || strtolower((string) ($appParts['scheme'] ?? '')) !== 'https' || empty($appParts['host'])) {
        $errors[] = 'APP_URL hoặc RENDER_EXTERNAL_URL phải là URL HTTPS khi bật WEBHOOK_AUTO_REGISTER.';
    }

    $primarySecret = (string) Hendy\Env::get('TELEGRAM_WEBHOOK_SECRET', '');
    if (preg_match($secretPattern, $primarySecret) !== 1) {
        $errors[] = 'TELEGRAM_WEBHOOK_SECRET phải có 32–256 ký tự chữ, số, gạch dưới hoặc gạch nối.';
    }

    if ($bot2 !== '') {
        $secondarySecret = (string) Hendy\Env::get('TELEGRAM_SECOND_WEBHOOK_SECRET', '');
        if (preg_match($secretPattern, $secondarySecret) !== 1) {
            $errors[] = 'BOT2_TOKEN đang bật nhưng TELEGRAM_SECOND_WEBHOOK_SECRET chưa hợp lệ.';
        }
        if ($primarySecret !== '' && $secondarySecret !== '' && hash_equals($primarySecret, $secondarySecret)) {
            $errors[] = 'Hai Telegram webhook secret phải khác nhau.';
        }
    }
}

$bankSecret = (string) Hendy\Env::get('BANK_WEBHOOK_SECRET', '');
if ($bankSecret !== '' && preg_match($secretPattern, $bankSecret) !== 1) {
    $errors[] = 'BANK_WEBHOOK_SECRET phải có ít nhất 32 ký tự an toàn.';
}

if ($errors !== []) {
    fwrite(STDERR, "Cấu hình chưa hợp lệ:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Cấu hình hợp lệ. BOT2_TOKEN: " . ($bot2 !== '' ? 'đã bật' : 'đang tắt') .
    "; Telegram webhook: " . ($autoWebhook ? 'đã bật kiểm tra' : 'đang tắt') . ".\n";
