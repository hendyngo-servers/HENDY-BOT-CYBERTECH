<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/MatchmakingService.php';
require dirname(__DIR__) . '/app/PaymentService.php';
require dirname(__DIR__) . '/app/VideoJobService.php';
require dirname(__DIR__) . '/app/VoucherService.php';
require dirname(__DIR__) . '/app/VipService.php';

use Hendy\Database;
use Hendy\MatchmakingService;
use Hendy\PaymentService;
use Hendy\VideoJobService;
use Hendy\VoucherService;
use Hendy\VipService;
use Hendy\Env;

Env::load(dirname(__DIR__) . '/.env');
Database::migrate();

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Database smoke test thất bại: ' . $message);
    }
};

$assert(Database::check(), 'PostgreSQL connection');

$accountUser = '95' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
Database::run('INSERT INTO users (user_id, name) VALUES (?, ?) ON CONFLICT (user_id) DO NOTHING', [$accountUser, 'Account Inventory Smoke']);
foreach (['RR88', 'MM88', 'GG88', 'AD88'] as $platform) {
    Database::run(
        'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
        [$accountUser, $platform, 'test_' . strtolower($platform)]
    );
}
$accountPlatforms = Database::all(
    'SELECT brand FROM linked_accounts WHERE user_id = ? ORDER BY brand',
    [$accountUser]
);
$accountPlatformNames = array_column($accountPlatforms, 'brand');
$assert(count(array_intersect(['RR88', 'MM88', 'GG88', 'AD88'], $accountPlatformNames)) === 4, 'legacy minigame platforms map to the existing linked account inventory');

$abuseTicket = Database::one(
    "INSERT INTO support_tickets (user_id, ticket_type, subject, message) VALUES (?, 'dinh_lam', ?, ?) RETURNING id, ticket_type",
    [$accountUser, 'Hỗ trợ dính lạm dụng (60%) · Đơn TEST', 'Kiểm tra quy trình ticket dính lạm dụng smoke test.']
);
$assert($abuseTicket !== null && $abuseTicket['ticket_type'] === 'dinh_lam', 'Telegram customer centre can store the abuse-support ticket type');


$vipUserId = (string) (900000000000 + random_int(100000, 999999));
Database::run('INSERT INTO users (user_id, name, username) VALUES (?, ?, ?)', [$vipUserId, 'VIP Checkin Smoke', 'vip_checkin_smoke']);
$vipBefore = (int) Database::value('SELECT point_old FROM users WHERE user_id = ?', [$vipUserId]);
$vipCheckin = VipService::checkin($vipUserId);
$vipAfter = (int) Database::value('SELECT point_old FROM users WHERE user_id = ?', [$vipUserId]);
$assert(!$vipCheckin['alreadyCheckedIn'] && $vipAfter === $vipBefore + 1, 'VIP daily check-in credits the configured internal point');
$vipDuplicate = VipService::checkin($vipUserId);
$vipAfterDuplicate = (int) Database::value('SELECT point_old FROM users WHERE user_id = ?', [$vipUserId]);
$assert($vipDuplicate['alreadyCheckedIn'] && $vipAfterDuplicate === $vipAfter, 'VIP check-in is idempotent for the same Vietnam-local day');
$vipStatus = VipService::status($vipUserId);
$assert($vipStatus['checkedInToday'] && $vipStatus['pointOld'] === $vipAfter, 'VIP status reports daily check-in and point balance');
$userId = '987654321012';
Database::run(
    'INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET name = EXCLUDED.name, username = EXCLUDED.username',
    [$userId, 'PHP Smoke Test', 'php_smoke']
);
$user = Database::one('SELECT user_id, name, balance, vip_level FROM users WHERE user_id = ?', [$userId]);
$assert($user !== null && $user['name'] === 'PHP Smoke Test', 'create/read users');

$rollbackId = '987654321013';
try {
    Database::transaction(function () use ($rollbackId): void {
        Database::run('INSERT INTO users (user_id, name) VALUES (?, ?)', [$rollbackId, 'Rollback Test']);
        throw new RuntimeException('expected rollback');
    });
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'expected rollback') {
        throw $error;
    }
}
$assert(Database::one('SELECT user_id FROM users WHERE user_id = ?', [$rollbackId]) === null, 'rollback transaction');

$product = Database::one(
    "INSERT INTO products (slug, name, category, description, price, stock, is_active) VALUES (?, ?, 'tools', ?, ?, ?, TRUE) RETURNING id",
    ['ci-smoke-' . bin2hex(random_bytes(3)), 'PHP Smoke Tool', 'Test product', 15000, 4]
);
$assert($product !== null, 'create product');
$orderCode = 'CI' . strtoupper(bin2hex(random_bytes(5)));
$order = Database::one(
    "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) VALUES (?, ?, ?, ?, 'tools', 15000, 'pending') RETURNING id",
    [$orderCode, $userId, $product['id'], 'PHP Smoke Tool']
);
$assert($order !== null, 'create order');

// Customer center: legacy-compatible wallet fields and 72-hour voucher eligibility.
Database::run("UPDATE orders SET status = 'processing', created_at = NOW() - INTERVAL '73 hours' WHERE id = ?", [$order['id']]);
$eligible = VoucherService::eligibleOrders($userId);
$assert(count(array_filter($eligible, static fn (array $row): bool => (int) $row['id'] === (int) $order['id'])) === 1, 'only processing orders older than 72 hours are voucher eligible');
$voucher = VoucherService::create($userId, (int) $order['id'], 'Đơn hàng đã quá 72 giờ chưa hoàn tất.');
$assert($voucher['status'] === 'pending' && $voucher['order_code'] === $orderCode, 'create pending voucher request for eligible order');
$duplicateVoucherRejected = false;
try {
    VoucherService::create($userId, (int) $order['id'], 'Gửi trùng yêu cầu voucher.');
} catch (RuntimeException) {
    $duplicateVoucherRejected = true;
}
$assert($duplicateVoucherRejected, 'one voucher request per order');
$mainBalanceBeforeVoucher = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$voucherBalanceBefore = (int) Database::value('SELECT voucher_wallet FROM users WHERE user_id = ?', [$userId]);
$reviewedVoucher = VoucherService::review((int) $voucher['id'], 'approve', '99887766', 25000, 'Đã đối chiếu đơn hàng.');
$mainBalanceAfterVoucher = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$voucherBalanceAfter = (int) Database::value('SELECT voucher_wallet FROM users WHERE user_id = ?', [$userId]);
$assert($reviewedVoucher['status'] === 'approved' && $voucherBalanceAfter === $voucherBalanceBefore + 25000, 'voucher approval credits voucher wallet');
$assert($mainBalanceAfterVoucher === $mainBalanceBeforeVoucher, 'voucher approval never credits main wallet');
$voucherLedger = Database::one("SELECT wallet_type, amount FROM wallet_ledger WHERE reference_code = ?", ['voucher-request:' . $voucher['id']]);
$assert($voucherLedger !== null && $voucherLedger['wallet_type'] === 'VOUCHER' && (int) $voucherLedger['amount'] === 25000, 'voucher credit has a separate idempotent ledger entry');
$secondVoucherApprovalRejected = false;
try {
    VoucherService::review((int) $voucher['id'], 'approve', '99887766', 25000);
} catch (RuntimeException) {
    $secondVoucherApprovalRejected = true;
}
$assert($secondVoucherApprovalRejected, 'approved voucher cannot be credited twice');

$rejectOrderCode = 'CIVR' . strtoupper(bin2hex(random_bytes(4)));
$rejectOrder = Database::one(
    "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status, created_at) VALUES (?, ?, ?, ?, 'tools', 15000, 'pending', NOW() - INTERVAL '80 hours') RETURNING id",
    [$rejectOrderCode, $userId, $product['id'], 'Voucher rejection smoke']
);
$rejectVoucher = VoucherService::create($userId, (int) $rejectOrder['id'], 'Kiểm tra quy trình từ chối voucher.');
$rejectedVoucher = VoucherService::review((int) $rejectVoucher['id'], 'reject', '99887766', 0, 'Không đủ điều kiện.');
$assert($rejectedVoucher['status'] === 'rejected' && (int) Database::value('SELECT voucher_wallet FROM users WHERE user_id = ?', [$userId]) === $voucherBalanceAfter, 'rejecting voucher request never changes voucher wallet');


$reference = 'ci-ledger-' . bin2hex(random_bytes(5));
Database::run(
    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'TEST', 'CI smoke') ON CONFLICT (reference_code) DO NOTHING",
    [$reference, $userId, 1000]
);
Database::run(
    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'TEST', 'CI duplicate') ON CONFLICT (reference_code) DO NOTHING",
    [$reference, $userId, 1000]
);
$count = (int) Database::value('SELECT COUNT(*) FROM wallet_ledger WHERE reference_code = ?', [$reference]);
$assert($count === 1, 'idempotent wallet ledger reference');

$giftCode = 'CI' . strtoupper(bin2hex(random_bytes(4)));
Database::run('INSERT INTO giftcodes (code, reward_amount, max_redemptions) VALUES (?, 5000, 1)', [$giftCode]);
Database::run('INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, 5000)', [$giftCode, $userId]);
$duplicate = Database::run(
    'INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, 5000) ON CONFLICT DO NOTHING RETURNING code',
    [$giftCode, $userId]
);
$assert($duplicate->rowCount() === 0, 'giftcode can only be redeemed once per user');

Database::run(
    "INSERT INTO bot_menu_configs (bot_key, menu_mode, button_labels) VALUES ('primary', 'reply_keyboard', ?::jsonb) ON CONFLICT (bot_key) DO UPDATE SET menu_mode = EXCLUDED.menu_mode",
    [json_encode(['wallet' => 'NẠP TIỀN'], JSON_UNESCAPED_UNICODE)]
);
$menu = Database::one('SELECT menu_mode FROM bot_menu_configs WHERE bot_key = ?', ['primary']);
$assert($menu !== null && $menu['menu_mode'] === 'reply_keyboard', 'bot menu config');

Database::run(
    "INSERT INTO support_tickets (user_id, subject, message) VALUES (?, ?, ?)",
    [$userId, 'CI smoke ticket', 'Testing support ticket schema']
);
$ticketCount = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
$assert($ticketCount === 1, 'support ticket schema');

// Telegram payment request: request creation does not credit wallet; admin approval is idempotent.
$balanceBeforePayment = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$payment = PaymentService::create($userId, 50000, 'secondary', 'MB', '123456789', 'HENDY TEST');
$assert($payment['status'] === 'pending' && $payment['transfer_content'] !== '', 'payment request is created in pending state');
$assert((int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]) === $balanceBeforePayment, 'creating a payment request never credits the wallet');
$approvedPayment = PaymentService::approve((string) $payment['payment_id'], '99887766');
$balanceAfterPayment = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
$assert($approvedPayment['newBalance'] === $balanceBeforePayment + 50000 && $balanceAfterPayment === $balanceBeforePayment + 50000, 'admin payment approval credits the exact amount');
$assert($approvedPayment['botKey'] === 'secondary', 'payment approval retains originating Telegram bot');
$duplicateApprovalRejected = false;
try {
    PaymentService::approve((string) $payment['payment_id'], '99887766');
} catch (RuntimeException) {
    $duplicateApprovalRejected = true;
}
$assert($duplicateApprovalRejected, 'payment approval cannot credit wallet twice');
$rejectedRequest = PaymentService::create($userId, 10000, 'primary', 'MB', '123456789', 'HENDY TEST');
$rejected = PaymentService::reject((string) $rejectedRequest['payment_id'], '99887766', 'CI reject');
$assert($rejected['botKey'] === 'primary', 'payment rejection retains originating Telegram bot');
$assert((int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]) === $balanceAfterPayment, 'rejecting payment never changes wallet balance');

$videoJob = VideoJobService::create($userId, 'https://youtu.be/dQw4w9WgXcQ', 'primary');
$assert($videoJob['status'] === 'queued' && $videoJob['progress'] === 0, 'video job enters PostgreSQL queue');
$processingJob = VideoJobService::updateFromCallback(['jobId' => $videoJob['id'], 'status' => 'processing', 'progress' => 55]);
$assert($processingJob['status'] === 'processing' && (int) $processingJob['progress'] === 55, 'signed callback state is reflected as processing');
$completedJob = VideoJobService::updateFromCallback(['jobId' => $videoJob['id'], 'status' => 'completed', 'progress' => 100, 'outputUrl' => 'https://cdn.example.com/subtitles/job.vtt']);
$assert($completedJob['status'] === 'completed' && $completedJob['output_url'] === 'https://cdn.example.com/subtitles/job.vtt', 'completed video job requires an HTTPS output URL');
$failedJob = VideoJobService::create($userId, 'https://youtu.be/abcDEF12345', 'secondary');
VideoJobService::markFailed((string) $failedJob['id'], 'Provider error');
Database::run('UPDATE video_jobs SET attempts = 3 WHERE id = ?', [$failedJob['id']]);
$retriedJob = VideoJobService::retry((string) $failedJob['id']);
$assert($retriedJob['status'] === 'queued' && (int) $retriedJob['progress'] === 0 && (int) $retriedJob['attempts'] === 0, 'admin retry starts a fresh dispatch attempt cycle');

$staleRequeueJob = VideoJobService::create($userId, 'https://youtu.be/staleAAA123', 'primary');
Database::run(
    "UPDATE video_jobs SET status = 'processing', progress = 40, attempts = 1, claimed_at = NOW() - INTERVAL '120 minutes' WHERE id = ?",
    [$staleRequeueJob['id']]
);
$recovery = VideoJobService::recoverStale(60);
$staleRequeued = Database::one('SELECT status, progress, attempts FROM video_jobs WHERE id = ?', [$staleRequeueJob['id']]);
$assert($recovery['requeued'] >= 1 && $staleRequeued['status'] === 'queued' && (int) $staleRequeued['progress'] === 0, 'stale video job is requeued after worker restart');

$staleFailedJob = VideoJobService::create($userId, 'https://youtu.be/staleBBB123', 'secondary');
Database::run(
    "UPDATE video_jobs SET status = 'processing', progress = 80, attempts = 3, claimed_at = NOW() - INTERVAL '120 minutes' WHERE id = ?",
    [$staleFailedJob['id']]
);
$failedRecovery = VideoJobService::recoverStale(60);
$staleFailed = Database::one('SELECT status, error_message FROM video_jobs WHERE id = ?', [$staleFailedJob['id']]);
$assert($failedRecovery['failed'] >= 1 && $staleFailed['status'] === 'failed', 'stale video job reaches failed state after maximum attempts');

// Matchmaking: report → bilateral confirmation → Elo and internal points.
$matchUserA = '98' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
$matchUserB = '97' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
$adminUser = '96' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
foreach ([[$matchUserA, 'Match Player A'], [$matchUserB, 'Match Player B'], [$adminUser, 'Match Admin']] as [$id, $name]) {
    Database::run('INSERT INTO users (user_id, name) VALUES (?, ?) ON CONFLICT (user_id) DO NOTHING', [$id, $name]);
}
$initialA = MatchmakingService::ensureStats($matchUserA);
$initialB = MatchmakingService::ensureStats($matchUserB);
$quickMatch = Database::one(
    "INSERT INTO matchmaking_matches (player_one, player_two, mode, status) VALUES (?, ?, 'quick', 'matched') RETURNING id",
    [$matchUserA, $matchUserB]
);
$submission = MatchmakingService::report((int) $quickMatch['id'], $matchUserA, 2, 1);
$assert($submission['opponentId'] === $matchUserB && $submission['result']['status'] === 'pending', 'match score report waits for opponent');
$finalResult = MatchmakingService::confirm((int) $quickMatch['id'], $matchUserB, true);
$assert($finalResult['status'] === 'completed' && $finalResult['ratingDeltaOne'] === -$finalResult['ratingDeltaTwo'], 'confirmed match finalizes and keeps Elo delta zero-sum');
$afterA = MatchmakingService::ensureStats($matchUserA);
$afterB = MatchmakingService::ensureStats($matchUserB);
$assert((int) $afterA['points'] === (int) $initialA['points'] + 10, 'winner receives internal leaderboard points');
$assert((int) $afterB['points'] === (int) $initialB['points'] + 3, 'loser receives participation leaderboard points');
$duplicateReportRejected = false;
try {
    MatchmakingService::report((int) $quickMatch['id'], $matchUserA, 2, 1);
} catch (RuntimeException) {
    $duplicateReportRejected = true;
}
$assert($duplicateReportRejected, 'completed match cannot accept duplicate score report');

$disputedMatch = Database::one(
    "INSERT INTO matchmaking_matches (player_one, player_two, mode, status) VALUES (?, ?, 'quick', 'matched') RETURNING id",
    [$matchUserA, $matchUserB]
);
MatchmakingService::report((int) $disputedMatch['id'], $matchUserA, 3, 0);
$dispute = MatchmakingService::confirm((int) $disputedMatch['id'], $matchUserB, false);
$assert($dispute['status'] === 'disputed', 'opponent can freeze an incorrect score as disputed');
$beforeResolve = MatchmakingService::ensureStats($matchUserA);
$resolved = MatchmakingService::resolveDispute((int) $disputedMatch['id'], $adminUser, 1, 0);
$afterResolve = MatchmakingService::ensureStats($matchUserA);
$assert($resolved['status'] === 'completed' && (int) $afterResolve['matches_played'] === (int) $beforeResolve['matches_played'] + 1, 'admin resolution finalizes disputed match exactly once');

$practiceBeforeA = MatchmakingService::ensureStats($matchUserA);
$practiceMatch = Database::one(
    "INSERT INTO matchmaking_matches (player_one, player_two, mode, status) VALUES (?, ?, 'practice', 'matched') RETURNING id",
    [$matchUserA, $matchUserB]
);
MatchmakingService::report((int) $practiceMatch['id'], $matchUserA, 1, 0);
$practiceResult = MatchmakingService::confirm((int) $practiceMatch['id'], $matchUserB, true);
$practiceAfterA = MatchmakingService::ensureStats($matchUserA);
$assert($practiceResult['ratingDeltaOne'] === 0 && (int) $practiceAfterA['rating'] === (int) $practiceBeforeA['rating'], 'practice match does not change Elo');


$queueUserA = '95' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
$queueUserB = '94' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
$queueUserC = '93' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT);
foreach ([$queueUserA, $queueUserB, $queueUserC] as $id) {
    Database::run('INSERT INTO users (user_id, name) VALUES (?, ?) ON CONFLICT (user_id) DO NOTHING', [$id, 'Queue test']);
}
$queueWaiting = MatchmakingService::join($queueUserA, 'quick');
$assert($queueWaiting['status'] === 'waiting', 'first Telegram player enters quick queue');
$queueMatched = MatchmakingService::join($queueUserB, 'quick');
$assert($queueMatched['status'] === 'matched' && ($queueMatched['match']['status'] ?? '') === 'matched', 'second Telegram player is paired');
$practiceWaiting = MatchmakingService::join($queueUserC, 'practice');
$assert($practiceWaiting['status'] === 'waiting', 'practice player enters separate queue');
MatchmakingService::leaveQueue($queueUserC);
$queueState = Database::value("SELECT status FROM matchmaking_queue WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", [$queueUserC]);
$assert($queueState === 'cancelled', 'Telegram member can leave waiting queue');

echo "Đã chạy {$checks} PostgreSQL smoke checks.\n";
