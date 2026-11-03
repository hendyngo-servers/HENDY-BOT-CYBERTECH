<?php
declare(strict_types=1);

namespace Hendy;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class ApiController
{
    private const CATEGORIES = ['minigame', 'tools', 'codes', 'otp', 'proxy', 'vip'];
    private const ORDER_STATUSES = ['pending', 'processing', 'completed', 'rejected', 'cancelled', 'refunded'];
    private const SETTINGS = [
        'homepage_title', 'announcement', 'website_url', 'VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO',
        'VIETQR_ACCOUNT_NAME', 'GROUP_URL', 'CHANNEL_URL',
        'referral_commission_percent', 'support_message',
    ];
    private const MENU_ACTIONS = [
        'live', 'tools', 'codes', 'wallet', 'otp', 'viptools', 'customer', 'duel',
        'ref', 'vip', 'group', 'news', 'gift', 'support', 'admin', 'vietsub', 'history', 'jobs',
    ];

    private array $query;
    private array $body;
    private array $headers;
    private ?array $user = null;
    private string $botKey = 'primary';

    public function __construct(array $query, array $body, array $headers)
    {
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function uploadModule(array $file, string $module): array
    {
        try { $this->user = TelegramAuth::authenticateRequest(); } catch (Throwable $error) { throw new HttpException(401, 'Yêu cầu xác thực Telegram.'); }
        $this->requireAdmin();
        try { $artifact = ModuleRegistry::stage($file, $module, (string) $this->user['id']); }
        catch (Throwable $error) { throw new HttpException(400, $error->getMessage()); }
        $this->audit('module.upload', (string) ($artifact['id'] ?? ''), ['module' => $module, 'status' => 'pending_review']);
        return self::response(['artifact' => $artifact, 'message' => 'Tệp đã vào vùng chờ kiểm duyệt; chưa được thực thi hoặc phát hành.'], 201);
    }

    public function handle(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/api/health') {
            return self::response([
                'service' => 'hendy-bot-tele-php',
                'status' => Database::check() ? 'ok' : 'degraded',
                'database' => 'ok',
                'telegram' => [
                    'primaryConfigured' => TelegramAuth::token('primary') !== '',
                    'secondaryConfigured' => TelegramAuth::token('secondary') !== '',
                ],
                'timestamp' => gmdate(DATE_ATOM),
            ]);
        }

        if ($method === 'POST' && $path === '/api/legacy/session/exchange') {
            return $this->exchangeLegacySession();
        }

        if (!str_starts_with($path, '/api/')) {
            throw new HttpException(404, 'Không tìm thấy API.');
        }

        try {
            $this->user = TelegramAuth::authenticateRequest();
            $this->botKey = (string) ($this->user['_bot_key'] ?? 'primary');
        } catch (Throwable $error) {
            throw new HttpException(401, $error->getMessage() ?: 'Yêu cầu xác thực Telegram.');
        }

        $userId = (string) $this->user['id'];

        if ($method === 'GET' && $path === '/api/bootstrap') {
            $user = $this->userRow();
            $referrals = (int) Database::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$userId]);
            return self::response([
                'user' => $user,
                'isAdmin' => $this->isAdmin(),
                'products' => $this->listProducts(null, false),
                'settings' => $this->settings(self::SETTINGS, true),
                'referralCount' => $referrals,
                'botUsername' => (string) Env::get('BOT_USERNAME', ''),
            ]);
        }

        if ($method === 'GET' && $path === '/api/products') {
            $category = $this->text($this->query['category'] ?? '', 32);
            return self::response(['products' => $this->listProducts($category !== '' ? $category : null, false)]);
        }

        if ($method === 'GET' && $path === '/api/me') {
            return self::response(['user' => $this->userRow(), 'isAdmin' => $this->isAdmin()]);
        }

        if ($method === 'GET' && $path === '/api/customer-center') {
            $profile = $this->userRow();
            $stats = Database::one(
                "SELECT COUNT(*) AS total_orders,
                        COUNT(*) FILTER (WHERE status = 'processing') AS processing_orders,
                        COUNT(*) FILTER (WHERE status = 'completed') AS completed_orders,
                        COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders,
                        COUNT(*) FILTER (
                            WHERE status = 'refunded'
                               OR EXISTS (
                                   SELECT 1 FROM transactions tx
                                   WHERE tx.user_id = orders.user_id
                                     AND tx.reference_code = orders.order_code
                                     AND tx.type = 'ORDER_REFUND'
                               )
                        ) AS refunded_orders
                 FROM orders WHERE user_id = ?",
                [$userId]
            ) ?? [];
            $ticketCounts = Database::one(
                "SELECT COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE ticket_type = 'dinh_lam') AS abuse,
                        COUNT(*) FILTER (WHERE ticket_type = 'support') AS support
                 FROM support_tickets WHERE user_id = ?",
                [$userId]
            ) ?? [];
            return self::response([
                'user' => $profile,
                'statistics' => [
                    'totalOrders' => (int) ($stats['total_orders'] ?? 0),
                    'processingOrders' => (int) ($stats['processing_orders'] ?? 0),
                    'completedOrders' => (int) ($stats['completed_orders'] ?? 0),
                    'cancelledOrders' => (int) ($stats['cancelled_orders'] ?? 0),
                    'refundedOrders' => (int) ($stats['refunded_orders'] ?? 0),
                ],
                'tickets' => [
                    'total' => (int) ($ticketCounts['total'] ?? 0),
                    'dinhLam' => (int) ($ticketCounts['abuse'] ?? 0),
                    'support' => (int) ($ticketCounts['support'] ?? 0),
                ],
                'recentOrders' => Database::all(
                    'SELECT id, order_code, product_name, amount, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
                    [$userId]
                ),
                'recentTransactions' => Database::all(
                    'SELECT id, amount, type, reference_code, status, wallet_type, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
                    [$userId]
                ),
                'walletHistory' => Database::all(
                    'SELECT id, amount, transaction_type, wallet_type, description, reference_code, created_at FROM wallet_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 10',
                    [$userId]
                ),
            ]);
        }

        if ($path === '/api/linked-accounts' && $method === 'GET') {
            return self::response(['accounts' => Database::all(
                'SELECT id, brand, username, created_at FROM linked_accounts WHERE user_id = ? ORDER BY created_at DESC',
                [$userId]
            )]);
        }
        if ($path === '/api/linked-accounts' && $method === 'POST') {
            $brand = strtoupper($this->text($this->body['brand'] ?? '', 16));
            $username = $this->text($this->body['username'] ?? '', 64);
            if (!in_array($brand, ['QQ88', '78WIN', 'SC88', 'C168', 'CM88', 'F8BET'], true)) {
                throw new HttpException(400, 'Thương hiệu không nằm trong danh sách hỗ trợ.');
            }
            if (mb_strlen($username) < 3 || preg_match('/[\x00-\x1F]/', $username)) {
                throw new HttpException(400, 'Tên đăng nhập phải từ 3 đến 64 ký tự.');
            }
            $row = Database::one(
                'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ' .
                'ON CONFLICT (user_id, brand, username) DO NOTHING RETURNING id, brand, username, created_at',
                [$userId, $brand, $username]
            );
            if ($row === null) {
                throw new HttpException(409, 'Tài khoản này đã được liên kết.');
            }
            return self::response(['account' => $row], 201);
        }
        if (preg_match('#^/api/linked-accounts/(\d{1,18})$#', $path, $match) && $method === 'DELETE') {
            $deleted = Database::run('DELETE FROM linked_accounts WHERE id = ? AND user_id = ? RETURNING id', [$match[1], $userId]);
            if ($deleted->rowCount() === 0) {
                throw new HttpException(404, 'Không tìm thấy tài khoản liên kết.');
            }
            return self::response(['success' => true]);
        }

        if ($path === '/api/orders' && $method === 'GET') {
            return self::response(['orders' => Database::all(
                "SELECT id, order_code, product_name, category, amount, status, admin_note, " .
                "CASE WHEN status = 'completed' THEN delivery_text ELSE '' END AS delivery_text, created_at, updated_at " .
                'FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 100',
                [$userId]
            )]);
        }
        if ($path === '/api/orders' && $method === 'POST') {
            $productId = (string) ($this->body['productId'] ?? '');
            if (!preg_match('/^\d{1,18}$/', $productId)) {
                throw new HttpException(400, 'Sản phẩm không hợp lệ.');
            }
            $order = Database::transaction(function () use ($userId, $productId): array {
                $product = Database::one('SELECT * FROM products WHERE id = ? AND is_active = TRUE FOR UPDATE', [$productId]);
                if ($product === null) {
                    throw new HttpException(404, 'Sản phẩm hiện không còn bán.');
                }
                if ($product['stock'] !== null && (int) $product['stock'] <= 0) {
                    throw new HttpException(409, 'Sản phẩm đã hết hàng.');
                }
                Database::run(
                    "INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO NOTHING",
                    [$userId, $this->text($this->user['first_name'] ?? '', 120), $this->text($this->user['username'] ?? '', 64)]
                );
                $member = Database::one('SELECT balance FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
                $price = (int) $product['price'];
                if ($member === null || (int) $member['balance'] < $price) {
                    throw new HttpException(402, 'Số dư không đủ. Vui lòng nạp thêm tiền.');
                }

                $orderCode = self::orderCode();
                Database::run('UPDATE users SET balance = balance - ? WHERE user_id = ?', [$price, $userId]);
                $created = Database::one(
                    "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) " .
                    "VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING id, order_code, product_name, category, amount, status, created_at",
                    [$orderCode, $userId, $product['id'], $product['name'], $product['category'], $price]
                );
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'PURCHASE', ?)",
                    ['order:' . $orderCode, $userId, -$price, 'Thanh toán đơn ' . $orderCode]
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'PURCHASE', ?, 'paid')",
                    [$userId, $price, $orderCode]
                );
                if ($product['stock'] !== null) {
                    Database::run('UPDATE products SET stock = stock - 1, updated_at = NOW() WHERE id = ?', [$product['id']]);
                }
                $created['balance'] = (int) Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
                return $created;
            });
            return self::response(['order' => $order, 'message' => 'Đơn hàng đã được tạo và trừ số dư thành công.'], 201);
        }

        if ($path === '/api/wallet' && $method === 'GET') {
            $user = $this->userRow();
            $history = Database::all(
                'SELECT id, amount, transaction_type, description, reference_code, created_at FROM wallet_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 60',
                [$userId]
            );
            return self::response(['balance' => $user['balance'], 'history' => $history]);
        }

        if ($path === '/api/wallet/payment-requests' && $method === 'GET') {
            return self::response(['requests' => PaymentService::forUser($userId, 20)]);
        }
        if ($path === '/api/wallet/payment-requests' && $method === 'POST') {
            $amount = $this->parseMoney($this->body['amount'] ?? null, false);
            if ($amount < 10000 || $amount > 500000000) {
                throw new HttpException(400, 'Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
            }
            $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME'], true);
            $bank = $settings['VIETQR_BANK_ID'] ?: (string) Env::get('VIETQR_BANK_ID', '');
            $account = $settings['VIETQR_ACCOUNT_NO'] ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
            $holder = $settings['VIETQR_ACCOUNT_NAME'] ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
            if ($bank === '' || $account === '' || $holder === '') {
                throw new HttpException(409, 'Quản trị viên chưa cấu hình tài khoản VietQR.');
            }
            try {
                $request = PaymentService::create($userId, $amount, $this->botKey, $bank, $account, $holder);
            } catch (RuntimeException $error) {
                throw new HttpException(400, $error->getMessage());
            }
            $params = http_build_query([
                'amount' => $amount,
                'addInfo' => $request['transfer_content'],
                'accountName' => $holder,
            ]);
            $qrUrl = 'https://img.vietqr.io/image/' . rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png?' . $params;
            $this->notifyAdmins(
                '🔔 YÊU CẦU NẠP MỚI ' . $request['payment_id'] . ' · ' . number_format($amount, 0, ',', '.') .
                ' VNĐ · User ' . $userId . ' · Nội dung CK: ' . $request['transfer_content'] . '. Cần đối soát sao kê trước khi duyệt.'
            );
            return self::response(['request' => $request, 'qrUrl' => $qrUrl, 'message' => 'Yêu cầu đã tạo và đang chờ Admin đối soát.'], 201);
        }
        if ($path === '/api/wallet/payment-info' && $method === 'GET') {
            $amount = $this->parseMoney($this->query['amount'] ?? 50000, false);
            if ($amount < 10000 || $amount > 500000000) {
                throw new HttpException(400, 'Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
            }
            $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME'], true);
            $bank = $settings['VIETQR_BANK_ID'] ?: (string) Env::get('VIETQR_BANK_ID', '');
            $account = $settings['VIETQR_ACCOUNT_NO'] ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
            $accountName = $settings['VIETQR_ACCOUNT_NAME'] ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
            if ($bank === '' || $account === '' || $accountName === '') {
                throw new HttpException(409, 'Quản trị viên chưa cấu hình tài khoản VietQR.');
            }
            $params = http_build_query([
                'amount' => $amount,
                'addInfo' => 'NAP ' . $userId,
                'accountName' => $accountName,
            ]);
            return self::response([
                'amount' => $amount,
                'accountName' => $accountName,
                'accountNo' => $account,
                'bankId' => $bank,
                'transferContent' => 'NAP ' . $userId,
                'qrUrl' => 'https://img.vietqr.io/image/' . rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png?' . $params,
            ]);
        }

        if ($path === '/api/referrals' && $method === 'GET') {
            $count = (int) Database::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$userId]);
            $reward = (int) Database::value(
                "SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'REFERRAL_REWARD'",
                [$userId]
            );
            $commission = $this->settings(['referral_commission_percent'], true);
            $username = ltrim((string) Env::get('BOT_USERNAME', ''), '@');
            $link = $username !== '' ? 'https://t.me/' . $username . '?start=ref_' . $userId : '';
            return self::response([
                'totalInvited' => $count,
                'totalReward' => $reward,
                'commissionPercent' => (float) ($commission['referral_commission_percent'] ?? 0),
                'referralLink' => $link,
            ]);
        }

        if ($path === '/api/giftcodes/redeem' && $method === 'POST') {
            $code = strtoupper($this->text($this->body['code'] ?? '', 64));
            if (!preg_match('/^[A-Z0-9_-]{4,64}$/', $code)) {
                throw new HttpException(400, 'Mã giftcode không hợp lệ.');
            }
            $reward = Database::transaction(function () use ($code, $userId): int {
                $gift = Database::one('SELECT * FROM giftcodes WHERE code = ? FOR UPDATE', [$code]);
                if ($gift === null) {
                    throw new HttpException(404, 'Giftcode không tồn tại.');
                }
                if (!$this->toBool($gift['is_active']) || ($gift['expires_at'] && strtotime($gift['expires_at']) < time())) {
                    throw new HttpException(410, 'Giftcode đã hết hạn hoặc bị khóa.');
                }
                if ((int) $gift['redeemed_count'] >= (int) $gift['max_redemptions']) {
                    throw new HttpException(409, 'Giftcode đã được sử dụng hết lượt.');
                }
                $inserted = Database::run(
                    'INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING code',
                    [$code, $userId, $gift['reward_amount']]
                );
                if ($inserted->rowCount() === 0) {
                    throw new HttpException(409, 'Bạn đã sử dụng giftcode này rồi.');
                }
                $reward = (int) $gift['reward_amount'];
                if ($reward > 0) {
                    Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$reward, $userId]);
                    Database::run(
                        "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'GIFTCODE', ?)",
                        ['giftcode:' . $code . ':' . $userId, $userId, $reward, 'Nhận thưởng giftcode ' . $code]
                    );
                    Database::run(
                        "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'GIFTCODE', ?, 'paid')",
                        [$userId, $reward, $code]
                    );
                }
                Database::run('UPDATE giftcodes SET redeemed_count = redeemed_count + 1 WHERE code = ?', [$code]);
                return $reward;
            });
            return self::response([
                'success' => true,
                'reward' => $reward,
                'balance' => $this->userRow()['balance'],
                'message' => $reward > 0 ? 'Đã cộng thưởng vào ví của bạn.' : 'Giftcode đã được ghi nhận.',
            ]);
        }

        if ($path === '/api/support/tickets' && $method === 'GET') {
            return self::response(['tickets' => Database::all(
                'SELECT id, ticket_type, subject, message, status, priority, admin_reply, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
                [$userId]
            )]);
        }
        if ($path === '/api/support/tickets' && $method === 'POST') {
            $ticketType = $this->text($this->body['ticketType'] ?? 'support', 24);
            if (!in_array($ticketType, ['dinh_lam', 'support'], true)) {
                throw new HttpException(400, 'Loại yêu cầu hỗ trợ không hợp lệ.');
            }
            $subject = $this->text($this->body['subject'] ?? '', 120);
            $message = $this->text($this->body['message'] ?? '', 3000);
            if (mb_strlen($subject) < 3 || mb_strlen($message) < 5) {
                throw new HttpException(400, 'Vui lòng nhập tiêu đề và nội dung yêu cầu.');
            }
            $ticket = Database::one(
                'INSERT INTO support_tickets (user_id, ticket_type, subject, message) VALUES (?, ?, ?, ?) RETURNING id, ticket_type, subject, message, status, created_at',
                [$userId, $ticketType, $subject, $message]
            );
            return self::response(['ticket' => $ticket, 'message' => 'Đã gửi yêu cầu hỗ trợ.'], 201);
        }

        if ($path === '/api/vouchers' && $method === 'GET') {
            return self::response([
                'eligibleOrders' => VoucherService::eligibleOrders($userId),
                'requests' => VoucherService::forUser($userId),
            ]);
        }
        if ($path === '/api/vouchers' && $method === 'POST') {
            $orderId = filter_var($this->body['orderId'] ?? null, FILTER_VALIDATE_INT);
            $reason = $this->text($this->body['reason'] ?? '', 1000);
            if ($orderId === false || $orderId < 1 || mb_strlen($reason) < 5) {
                throw new HttpException(400, 'Chọn đơn hàng và mô tả lý do yêu cầu voucher (ít nhất 5 ký tự).');
            }
            try {
                $request = VoucherService::create($userId, (int) $orderId, $reason);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->notifyAdmins('🎟️ YÊU CẦU VOUCHER 72H #' . $request['id'] . ' · Đơn ' . $request['order_code'] . ' · User ' . $userId . '. Vui lòng kiểm tra lịch sử đơn trước khi duyệt.');
            return self::response(['request' => $request, 'message' => 'Đã gửi yêu cầu voucher để Admin kiểm tra.'], 201);
        }

        if ($path === '/api/matchmaking/leaderboard' && $method === 'GET') {
            $limit = (int) ($this->query['limit'] ?? 10);
            return self::response([
                'players' => MatchmakingService::leaderboard($limit),
                'currentPlayer' => MatchmakingService::ensureStats($userId),
            ]);
        }

        if ($path === '/api/matchmaking' && $method === 'GET') {
            $stats = MatchmakingService::ensureStats($userId);
            $match = Database::one(
                "SELECT id, mode, status, created_at, player_one, player_two, CASE WHEN player_one = ? THEN player_two ELSE player_one END AS opponent_id " .
                "FROM matchmaking_matches WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?) ORDER BY created_at DESC LIMIT 1",
                [$userId, $userId, $userId]
            );
            if ($match !== null) {
                $opponent = Database::one('SELECT name, username FROM users WHERE user_id = ?', [$match['opponent_id']]);
                $opponentStats = MatchmakingService::ensureStats((string) $match['opponent_id']);
                $result = Database::one(
                    'SELECT score_one, score_two, status, reported_by, created_at FROM matchmaking_results WHERE match_id = ?',
                    [$match['id']]
                );
                return self::response([
                    'status' => $match['status'] === 'disputed' ? 'disputed' : 'matched',
                    'matchId' => (string) $match['id'],
                    'mode' => $match['mode'],
                    'opponentId' => $match['opponent_id'],
                    'opponentName' => ($opponent['name'] ?? '') ?: (($opponent['username'] ?? '') ?: 'Thành viên Hendy'),
                    'createdAt' => $match['created_at'],
                    'rating' => (int) $stats['rating'],
                    'points' => (int) $stats['points'],
                    'opponentRating' => (int) $opponentStats['rating'],
                    'isPlayerOne' => $match['player_one'] === $userId,
                    'pendingResult' => $result !== null ? [
                        'scoreOne' => (int) $result['score_one'],
                        'scoreTwo' => (int) $result['score_two'],
                        'status' => $result['status'],
                        'reportedByMe' => $result['reported_by'] === $userId,
                    ] : null,
                ]);
            }

            $waiting = Database::one(
                "SELECT id, mode, created_at FROM matchmaking_queue WHERE user_id = ? AND status = 'waiting' ORDER BY created_at DESC LIMIT 1",
                [$userId]
            );
            return self::response($waiting !== null
                ? ['status' => 'waiting', 'mode' => $waiting['mode'], 'createdAt' => $waiting['created_at'], 'rating' => (int) $stats['rating'], 'points' => (int) $stats['points']]
                : ['status' => 'idle', 'rating' => (int) $stats['rating'], 'points' => (int) $stats['points']]);
        }

        if ($path === '/api/matchmaking/join' && $method === 'POST') {
            $mode = $this->text($this->body['mode'] ?? 'quick', 24);
            if (!in_array($mode, ['quick', 'practice'], true)) {
                throw new HttpException(400, 'Chế độ ghép đôi không hợp lệ.');
            }
            $match = Database::transaction(function () use ($userId, $mode): ?array {
                Database::run('SELECT pg_advisory_xact_lock(hashtext(?))', ['hendy-matchmaking:' . $mode]);
                $myStats = MatchmakingService::ensureStats($userId);
                $myRating = (int) $myStats['rating'];
                $active = Database::one(
                    "SELECT * FROM matchmaking_matches WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?) ORDER BY created_at DESC LIMIT 1 FOR UPDATE",
                    [$userId, $userId]
                );
                if ($active !== null) {
                    return $active;
                }
                $current = Database::one("SELECT id FROM matchmaking_queue WHERE user_id = ? AND status = 'waiting' ORDER BY created_at DESC LIMIT 1 FOR UPDATE", [$userId]);
                if ($current !== null) {
                    return null;
                }
                $partner = Database::one(
                    "SELECT q.id, q.user_id FROM matchmaking_queue q " .
                    "LEFT JOIN matchmaking_player_stats s ON s.user_id = q.user_id " .
                    "WHERE q.status = 'waiting' AND q.mode = ? AND q.user_id <> ? " .
                    "AND (? <> 'quick' OR ABS(COALESCE(s.rating, 1000) - ?) <= LEAST(600, 150 + (EXTRACT(EPOCH FROM (NOW() - q.created_at)) / 30 * 25))) " .
                    "ORDER BY CASE WHEN ? = 'quick' THEN ABS(COALESCE(s.rating, 1000) - ?) ELSE 0 END ASC, q.created_at ASC " .
                    "LIMIT 1 FOR UPDATE OF q",
                    [$mode, $userId, $mode, $myRating, $mode, $myRating]
                );
                if ($partner === null) {
                    Database::run("INSERT INTO matchmaking_queue (user_id, mode, status) VALUES (?, ?, 'waiting')", [$userId, $mode]);
                    return null;
                }
                MatchmakingService::ensureStats((string) $partner['user_id']);
                $created = Database::one(
                    "INSERT INTO matchmaking_matches (player_one, player_two, mode, status) VALUES (?, ?, ?, 'matched') RETURNING *",
                    [$partner['user_id'], $userId, $mode]
                );
                Database::run(
                    "UPDATE matchmaking_queue SET status = 'paired', matched_user_id = ?, match_id = ?, updated_at = NOW() WHERE id = ?",
                    [$userId, $created['id'], $partner['id']]
                );
                Database::run(
                    "INSERT INTO matchmaking_queue (user_id, mode, status, matched_user_id, match_id) VALUES (?, ?, 'paired', ?, ?)",
                    [$userId, $mode, $partner['user_id'], $created['id']]
                );
                return $created;
            });

            if ($match !== null && $match['status'] === 'disputed') {
                return self::response(['status' => 'disputed', 'matchId' => (string) $match['id'], 'message' => 'Lượt ghép đang được quản trị viên xử lý tranh chấp.']);
            }
            if ($match !== null) {
                $opponentId = $match['player_one'] === $userId ? $match['player_two'] : $match['player_one'];
                $opponent = Database::one('SELECT name, username FROM users WHERE user_id = ?', [$opponentId]);
                $name = ($opponent['name'] ?? '') ?: (($opponent['username'] ?? '') ?: 'Thành viên Hendy');
                $this->notify($userId, '🎮 Bạn đã được ghép đôi với ' . $name . '. Mã ghép #' . $match['id']);
                $this->notify($opponentId, '🎮 Bạn đã được ghép đôi với một thành viên. Mã ghép #' . $match['id']);
                return self::response(['status' => 'matched', 'matchId' => (string) $match['id'], 'opponentId' => $opponentId, 'opponentName' => $name]);
            }
            return self::response(['status' => 'waiting', 'message' => 'Đang chờ người chơi có điểm xếp hạng phù hợp.']);
        }

        if ($path === '/api/matchmaking/leave' && $method === 'POST') {
            $updated = Database::run("UPDATE matchmaking_queue SET status = 'cancelled', updated_at = NOW() WHERE user_id = ? AND status = 'waiting' RETURNING id", [$userId]);
            if ($updated->rowCount() === 0) {
                $active = Database::one("SELECT id FROM matchmaking_matches WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?) LIMIT 1", [$userId, $userId]);
                if ($active !== null) {
                    throw new HttpException(409, 'Bạn đã được ghép đôi hoặc đang chờ xử lý kết quả. Không thể rời lượt ghép.');
                }
            }
            return self::response(['success' => true, 'status' => 'idle']);
        }

        if ($path === '/api/matchmaking/report' && $method === 'POST') {
            $matchId = (string) ($this->body['matchId'] ?? '');
            $scoreOne = filter_var($this->body['scoreOne'] ?? null, FILTER_VALIDATE_INT);
            $scoreTwo = filter_var($this->body['scoreTwo'] ?? null, FILTER_VALIDATE_INT);
            if (!preg_match('/\A[0-9]{1,18}\z/', $matchId) || $scoreOne === false || $scoreTwo === false || $scoreOne < 0 || $scoreOne > 999 || $scoreTwo < 0 || $scoreTwo > 999) {
                throw new HttpException(400, 'Mã lượt ghép và tỷ số phải là số nguyên từ 0 đến 999.');
            }
            try {
                $result = MatchmakingService::report((int) $matchId, $userId, (int) $scoreOne, (int) $scoreTwo);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->notify(
                (string) $result['opponentId'],
                '🧾 Đối thủ đã gửi tỷ số trận #' . $matchId . ': ' . (int) $scoreOne . '-' . (int) $scoreTwo . ' (thứ tự A-B). Mở mục Ghép đôi trong bot để xác nhận hoặc báo sai.'
            );
            return self::response(['result' => $result['result'], 'status' => 'pending', 'message' => 'Đã gửi tỷ số. Chờ đối thủ xác nhận.'], 201);
        }

        if ($path === '/api/matchmaking/confirm' && $method === 'POST') {
            $matchId = (string) ($this->body['matchId'] ?? '');
            $approved = filter_var($this->body['approved'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (!preg_match('/\A[0-9]{1,18}\z/', $matchId) || !array_key_exists('approved', $this->body) || $approved === null) {
                throw new HttpException(400, 'Mã lượt ghép hoặc lựa chọn xác nhận không hợp lệ.');
            }
            $match = Database::one('SELECT player_one, player_two FROM matchmaking_matches WHERE id = ?', [$matchId]);
            try {
                $result = MatchmakingService::confirm((int) $matchId, $userId, $approved);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            if ($match !== null) {
                $otherId = $match['player_one'] === $userId ? $match['player_two'] : $match['player_one'];
                $message = $result['status'] === 'disputed'
                    ? '⚠️ Kết quả trận #' . $matchId . ' đang tranh chấp. Elo và điểm chưa thay đổi; quản trị viên sẽ kiểm tra.'
                    : '✅ Kết quả trận #' . $matchId . ' đã được hai bên xác nhận. Điểm và Elo đã được cập nhật.';
                $this->notify((string) $otherId, $message);
            }
            return self::response($result);
        }

        if ($path === '/api/matchmaking/finish' && $method === 'POST') {
            throw new HttpException(410, 'Không thể kết thúc lượt ghép trực tiếp. Hãy gửi tỷ số và chờ đối thủ xác nhận.');
        }

        if ($path === '/api/vip' && $method === 'GET') {
            $user = $this->userRow();
            $thresholds = [0, 500000, 2000000, 5000000];
            return self::response([
                'currentLevel' => $user['vipLevel'],
                'totalDeposited' => $user['totalDeposited'],
                'nextThreshold' => $user['vipLevel'] >= 3 ? null : $thresholds[$user['vipLevel'] + 1],
                'products' => $this->listProducts('vip', false),
            ]);
        }

        if (str_starts_with($path, '/api/admin/')) {
            $this->requireAdmin();
            return $this->handleAdmin($method, $path);
        }

        throw new HttpException(404, 'Không tìm thấy API endpoint.');
    }

    private function handleAdmin(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/api/admin/matchmaking/disputes') {
            return self::response([
                'disputes' => Database::all(
                    "SELECT r.id AS result_id, r.match_id, r.score_one, r.score_two, r.reported_by, r.confirmed_by, r.created_at, m.mode, m.player_one, m.player_two " .
                    "FROM matchmaking_results r JOIN matchmaking_matches m ON m.id = r.match_id " .
                    "WHERE r.status = 'disputed' ORDER BY r.created_at ASC LIMIT 100"
                ),
            ]);
        }
        if ($method === 'POST' && preg_match('#^/api/admin/matchmaking/([0-9]{1,18})/resolve$#', $path, $match)) {
            $scoreOne = filter_var($this->body['scoreOne'] ?? null, FILTER_VALIDATE_INT);
            $scoreTwo = filter_var($this->body['scoreTwo'] ?? null, FILTER_VALIDATE_INT);
            if ($scoreOne === false || $scoreTwo === false || $scoreOne < 0 || $scoreOne > 999 || $scoreTwo < 0 || $scoreTwo > 999) {
                throw new HttpException(400, 'Tỷ số phải là số nguyên từ 0 đến 999.');
            }
            $matchRow = Database::one('SELECT player_one, player_two FROM matchmaking_matches WHERE id = ?', [$match[1]]);
            try {
                $resolved = MatchmakingService::resolveDispute((int) $match[1], (string) $this->user['id'], (int) $scoreOne, (int) $scoreTwo);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('matchmaking.resolve', $match[1], ['scoreOne' => (int) $scoreOne, 'scoreTwo' => (int) $scoreTwo]);
            if ($matchRow !== null) {
                $notice = '✅ Quản trị viên đã xử lý tranh chấp trận #' . $match[1] . '. Tỷ số cuối: ' . (int) $scoreOne . '-' . (int) $scoreTwo . '. Elo và điểm nội bộ đã được cập nhật.';
                $this->notify((string) $matchRow['player_one'], $notice);
                $this->notify((string) $matchRow['player_two'], $notice);
            }
            return self::response(['result' => $resolved, 'message' => 'Đã xử lý tranh chấp và cập nhật bảng xếp hạng.']);
        }
        if ($method === 'GET' && $path === '/api/admin/payments') {
            return self::response(['payments' => PaymentService::pending(100), 'stats' => PaymentService::stats()]);
        }
        if (preg_match('#^/api/admin/payments/([A-Z0-9]{6,24})/(approve|reject)$#', $path, $paymentMatch) && $method === 'POST') {
            $paymentId = $paymentMatch[1];
            try {
                if ($paymentMatch[2] === 'approve') {
                    $result = PaymentService::approve($paymentId, (string) $this->user['id']);
                    $this->notify((string) $result['telegramId'],
                        '🎉 Yêu cầu nạp ' . $paymentId . ' đã được Admin duyệt. Cộng +' .
                        number_format($result['amountVnd'], 0, ',', '.') . ' VNĐ. Số dư mới: ' .
                        number_format($result['newBalance'], 0, ',', '.') . ' VNĐ.',
                        (string) ($result['botKey'] ?? $this->botKey));
                } else {
                    $result = PaymentService::reject($paymentId, (string) $this->user['id'], $this->text($this->body['note'] ?? 'Admin từ chối sau khi đối soát.', 500));
                    $this->notify((string) $result['telegramId'], 'Yêu cầu nạp ' . $paymentId . ' chưa được duyệt. Vui lòng liên hệ CSKH nếu cần hỗ trợ.', (string) ($result['botKey'] ?? $this->botKey));
                }
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('payment.' . $paymentMatch[2], $paymentId, ['amount' => $result['amountVnd']]);
            return self::response(['payment' => $result, 'message' => $paymentMatch[2] === 'approve' ? 'Đã duyệt và cập nhật ví.' : 'Đã từ chối; ví không thay đổi.']);
        }
        if ($method === 'GET' && $path === '/api/admin/video-jobs') {
            return self::response(['jobs' => VideoJobService::recent(100), 'stats' => VideoJobService::stats()]);
        }
        if (preg_match('#^/api/admin/video-jobs/([0-9a-f-]{36})/retry$#i', $path, $jobMatch) && $method === 'POST') {
            try {
                $job = VideoJobService::retry($jobMatch[1]);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('video_job.retry', $jobMatch[1], []);
            $this->notify((string) $job['user_id'], 'Video job ' . substr((string) $job['id'], 0, 8) . ' đã được đưa lại vào hàng chờ.', (string) ($job['bot_key'] ?? $this->botKey));
            return self::response(['job' => $job, 'message' => 'Đã đưa video job vào hàng chờ.']);
        }

        if ($method === 'GET' && $path === '/api/admin/modules') {
            return self::response(['artifacts' => Database::all('SELECT id, module_key, original_name, mime_type, sha256, status, created_at FROM module_artifacts ORDER BY created_at DESC LIMIT 100')]);
        }
        if ($method === 'POST' && $path === '/api/admin/realtime-ticket') {
            $ticket = Realtime::issueAdminTicket((string) $this->user['id']);
            $url = trim((string) Env::get('WEBSOCKET_URL', ''));
            if ($url === '' || !preg_match('#^wss://#i', $url)) throw new HttpException(503, 'WebSocket WSS chưa được cấu hình.');
            return self::response(['ticket' => $ticket, 'url' => $url, 'expiresInSeconds' => 60]);
        }
        if ($method === 'GET' && $path === '/api/admin/overview') {
            $paymentStats = PaymentService::stats();
            $videoStats = VideoJobService::stats();
            return self::response([
                'users' => (int) Database::value('SELECT COUNT(*) FROM users'),
                'activeProducts' => (int) Database::value('SELECT COUNT(*) FROM products WHERE is_active = TRUE'),
                'ordersLast30Days' => (int) Database::value("SELECT COUNT(*) FROM orders WHERE created_at >= NOW() - INTERVAL '30 days'"),
                'completedOrderValue' => (int) Database::value("SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = 'completed'"),
                'openTickets' => (int) Database::value("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open', 'processing')"),
                'pendingPayments' => $paymentStats['pending'],
                'queuedVideoJobs' => $videoStats['queued'],
                'activeVideoJobs' => $videoStats['processing'],
            ]);
        }
        if ($method === 'GET' && $path === '/api/admin/runtime') {
            $primary = TelegramAuth::token('primary') !== '';
            $secondary = TelegramAuth::token('secondary') !== '';
            return self::response([
                'bots' => [
                    ['key' => 'primary', 'status' => $primary ? 'webhook-configured' : 'missing-token', 'configured' => $primary],
                    ['key' => 'secondary', 'status' => $secondary ? 'webhook-configured' : 'disabled', 'configured' => $secondary],
                ],
                'timestamp' => gmdate(DATE_ATOM),
            ]);
        }
        if ($method === 'GET' && $path === '/api/admin/products') {
            $category = $this->text($this->query['category'] ?? '', 32);
            return self::response(['products' => $this->listProducts($category !== '' ? $category : null, true)]);
        }
        if ($method === 'POST' && $path === '/api/admin/products') {
            $name = $this->text($this->body['name'] ?? '', 120);
            $category = $this->text($this->body['category'] ?? '', 32);
            $description = $this->text($this->body['description'] ?? '', 1500);
            $price = $this->parseMoney($this->body['price'] ?? null, true);
            $days = (int) ($this->body['durationDays'] ?? 0);
            $stock = ($this->body['stock'] ?? null) === '' || ($this->body['stock'] ?? null) === null ? null : (int) $this->body['stock'];
            $vip = (int) ($this->body['vipLevel'] ?? 0);
            if (mb_strlen($name) < 2 || !in_array($category, self::CATEGORIES, true)) {
                throw new HttpException(400, 'Tên sản phẩm hoặc danh mục không hợp lệ.');
            }
            if ($days < 0 || $days > 3650 || $stock !== null && ($stock < 0 || $stock > 10000000) || $vip < 0 || $vip > 3) {
                throw new HttpException(400, 'Thời hạn, tồn kho hoặc cấp VIP không hợp lệ.');
            }
            $slug = self::slug($this->body['slug'] ?? $name);
            $image = $this->text($this->body['imageUrl'] ?? '', 500);
            self::validateHttps($image, 'Ảnh sản phẩm phải dùng HTTPS.');
            $product = Database::one(
                'INSERT INTO products (slug, name, category, description, price, duration_days, stock, vip_level, image_url, is_active) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE) RETURNING *',
                [$slug, $name, $category, $description, $price, $days, $stock, $vip, $image ?: null]
            );
            $this->audit('product.create', $slug, ['category' => $category, 'price' => $price]);
            return self::response(['product' => self::publicProduct($product)], 201);
        }

        if (preg_match('#^/api/admin/products/(\d{1,18})$#', $path, $match)) {
            $id = $match[1];
            if ($method === 'PATCH') {
                $current = Database::one('SELECT * FROM products WHERE id = ?', [$id]);
                if ($current === null) {
                    throw new HttpException(404, 'Không tìm thấy sản phẩm.');
                }
                $name = array_key_exists('name', $this->body) ? $this->text($this->body['name'], 120) : $current['name'];
                $category = array_key_exists('category', $this->body) ? $this->text($this->body['category'], 32) : $current['category'];
                $description = array_key_exists('description', $this->body) ? $this->text($this->body['description'], 1500) : $current['description'];
                $price = array_key_exists('price', $this->body) ? $this->parseMoney($this->body['price'], true) : (int) $current['price'];
                $days = array_key_exists('durationDays', $this->body) ? (int) $this->body['durationDays'] : (int) $current['duration_days'];
                $stock = array_key_exists('stock', $this->body)
                    ? (($this->body['stock'] === '' || $this->body['stock'] === null) ? null : (int) $this->body['stock'])
                    : ($current['stock'] === null ? null : (int) $current['stock']);
                $vip = array_key_exists('vipLevel', $this->body) ? (int) $this->body['vipLevel'] : (int) $current['vip_level'];
                $active = array_key_exists('isActive', $this->body) ? $this->toBool($this->body['isActive']) : $this->toBool($current['is_active']);
                $image = array_key_exists('imageUrl', $this->body) ? $this->text($this->body['imageUrl'], 500) : (string) ($current['image_url'] ?? '');
                if (mb_strlen($name) < 2 || !in_array($category, self::CATEGORIES, true) || $days < 0 || $days > 3650 || $stock !== null && $stock < 0 || $vip < 0 || $vip > 3) {
                    throw new HttpException(400, 'Thông tin sản phẩm không hợp lệ.');
                }
                self::validateHttps($image, 'Ảnh sản phẩm phải dùng HTTPS.');
                $product = Database::one(
                    'UPDATE products SET name = ?, category = ?, description = ?, price = ?, duration_days = ?, stock = ?, vip_level = ?, image_url = ?, is_active = ?, updated_at = NOW() WHERE id = ? RETURNING *',
                    [$name, $category, $description, $price, $days, $stock, $vip, $image ?: null, $active, $id]
                );
                $this->audit('product.update', $id, ['isActive' => $active, 'category' => $category, 'price' => $price]);
                return self::response(['product' => self::publicProduct($product)]);
            }
            if ($method === 'DELETE') {
                $deleted = Database::run('UPDATE products SET is_active = FALSE, updated_at = NOW() WHERE id = ? RETURNING id', [$id]);
                if ($deleted->rowCount() === 0) {
                    throw new HttpException(404, 'Không tìm thấy sản phẩm.');
                }
                $this->audit('product.disable', $id, []);
                return self::response(['success' => true]);
            }
        }

        if ($method === 'GET' && $path === '/api/admin/orders') {
            return self::response(['orders' => Database::all(
                'SELECT id, order_code, user_id, product_name, category, amount, status, admin_note, delivery_text, created_at, updated_at FROM orders ORDER BY created_at DESC LIMIT 200'
            )]);
        }
        if (preg_match('#^/api/admin/orders/(\d{1,18})$#', $path, $match) && $method === 'PATCH') {
            return $this->updateOrder($match[1]);
        }

        if ($method === 'GET' && $path === '/api/admin/users') {
            $search = $this->text($this->query['search'] ?? '', 80);
            $users = Database::all(
                "SELECT user_id, name, username, balance, total_deposited, vip_level, ref_by, created_at FROM users " .
                "WHERE (? = '' OR user_id ILIKE ? OR name ILIKE ? OR username ILIKE ?) ORDER BY created_at DESC LIMIT 100",
                [$search, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%']
            );
            foreach ($users as &$user) {
                $user['balance'] = (int) $user['balance'];
                $user['total_deposited'] = (int) $user['total_deposited'];
            }
            unset($user);
            return self::response(['users' => $users]);
        }
        if (preg_match('#^/api/admin/users/(\d{5,20})/adjust-balance$#', $path, $match) && $method === 'POST') {
            $amount = $this->parseMoney($this->body['amount'] ?? null, false);
            $direction = $this->text($this->body['direction'] ?? '', 12);
            $description = $this->text($this->body['description'] ?? '', 300);
            if (!in_array($direction, ['credit', 'debit'], true)) {
                throw new HttpException(400, 'Loại điều chỉnh không hợp lệ.');
            }
            $userId = $match[1];
            $signed = $direction === 'credit' ? $amount : -$amount;
            $balance = Database::transaction(function () use ($amount, $signed, $userId, $description): int {
                $updated = Database::one(
                    'UPDATE users SET balance = balance + ? WHERE user_id = ? AND balance + ? >= 0 RETURNING balance',
                    [$signed, $userId, $signed]
                );
                if ($updated === null) {
                    throw new HttpException(409, 'Không tìm thấy tài khoản hoặc số dư không đủ.');
                }
                $reference = 'admin:' . bin2hex(random_bytes(12));
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'ADMIN_ADJUSTMENT', ?)",
                    [$reference, $userId, $signed, $description !== '' ? $description : 'Điều chỉnh số dư bởi quản trị viên']
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'ADMIN_ADJUSTMENT', ?, 'paid')",
                    [$userId, $amount, $reference]
                );
                return (int) $updated['balance'];
            });
            $this->audit('user.balance', $userId, ['amount' => $signed]);
            $this->notify($userId, 'Số dư của bạn vừa được điều chỉnh: ' . ($signed > 0 ? '+' : '') . self::moneyFormat($signed) . '.');
            return self::response(['success' => true, 'balance' => $balance]);
        }

        if ($method === 'GET' && $path === '/api/admin/vouchers') {
            return self::response(['requests' => VoucherService::pending(200)]);
        }
        if (preg_match('#^/api/admin/vouchers/(\\d+)/(approve|reject)$#', $path, $match) && $method === 'POST') {
            $action = $match[2];
            $reward = $action === 'approve' ? $this->parseMoney($this->body['rewardAmount'] ?? null, false) : 0;
            $note = $this->text($this->body['adminNote'] ?? '', 1000);
            try {
                $result = VoucherService::review((int) $match[1], $action, (string) $this->user['id'], $reward, $note);
            } catch (RuntimeException $error) {
                throw new HttpException(409, $error->getMessage());
            }
            $this->audit('voucher.' . $action, (string) $match[1], ['rewardAmount' => $reward]);
            $message = $action === 'approve'
                ? '✅ Yêu cầu voucher cho đơn ' . $result['request']['order_code'] . ' đã được duyệt. Ví Voucher được cộng ' . self::moneyFormat($reward) . '.'
                : 'Yêu cầu voucher cho đơn ' . $result['request']['order_code'] . ' đã bị từ chối. Ghi chú: ' . ($note !== '' ? $note : 'Không có.');
            $this->notify($result['userId'], $message);
            return self::response(['request' => $result['request'], 'message' => $action === 'approve' ? 'Đã duyệt và cộng ví Voucher.' : 'Đã từ chối yêu cầu.']);
        }

        if ($method === 'GET' && $path === '/api/admin/tickets') {
            return self::response(['tickets' => Database::all(
                "SELECT * FROM support_tickets ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END, created_at DESC LIMIT 200"
            )]);
        }
        if (preg_match('#^/api/admin/tickets/(\d{1,18})$#', $path, $match) && $method === 'PATCH') {
            $status = $this->text($this->body['status'] ?? '', 24);
            $reply = $this->text($this->body['reply'] ?? '', 3000);
            if (!in_array($status, ['open', 'processing', 'resolved', 'closed'], true)) {
                throw new HttpException(400, 'Trạng thái hỗ trợ không hợp lệ.');
            }
            $ticket = Database::one(
                'UPDATE support_tickets SET status = ?, admin_reply = ?, updated_at = NOW() WHERE id = ? RETURNING *',
                [$status, $reply, $match[1]]
            );
            if ($ticket === null) {
                throw new HttpException(404, 'Không tìm thấy yêu cầu hỗ trợ.');
            }
            $this->audit('support.reply', $match[1], ['status' => $status]);
            if ($reply !== '') {
                $this->notify((string) $ticket['user_id'], "🎧 Phản hồi từ Trung tâm khách hàng:\n" . htmlspecialchars($reply, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            return self::response(['ticket' => $ticket]);
        }

        if ($method === 'GET' && $path === '/api/admin/settings') {
            return self::response(['settings' => $this->settings(self::SETTINGS, false)]);
        }
        if ($method === 'PATCH' && $path === '/api/admin/settings') {
            $keys = array_keys($this->body);
            if ($keys === [] || array_diff($keys, self::SETTINGS) !== []) {
                throw new HttpException(400, 'Có khóa cấu hình không được phép cập nhật. Secret hệ thống chỉ quản lý trong Environment.');
            }
            $values = [];
            foreach ($this->body as $key => $value) {
                $text = $this->text($value, 500);
                if ($key === 'referral_commission_percent') {
                    $percent = (float) $text;
                    if (!is_finite($percent) || $percent < 0 || $percent > 30) {
                        throw new HttpException(400, 'Tỷ lệ hoa hồng phải từ 0 đến 30%.');
                    }
                }
                if (in_array($key, ['website_url', 'GROUP_URL', 'CHANNEL_URL'], true)) {
                    self::validateHttps($text, 'Liên kết website/nhóm/kênh phải sử dụng HTTPS.');
                    if ($text !== '' && filter_var($text, FILTER_VALIDATE_URL) === false) {
                        throw new HttpException(400, 'Liên kết website phải là URL hợp lệ.');
                    }
                }
                $values[$key] = $text;
            }
            Database::transaction(function () use ($values): void {
                foreach ($values as $key => $value) {
                    Database::run(
                        'INSERT INTO app_settings (key, value, is_public, updated_at) VALUES (?, ?, TRUE, NOW()) ' .
                        'ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, is_public = TRUE, updated_at = NOW()',
                        [$key, $value]
                    );
                }
            });
            $this->audit('settings.update', 'public-settings', ['keys' => $keys]);
            return self::response(['success' => true, 'settings' => $this->settings(self::SETTINGS, false)]);
        }

        if (preg_match('#^/api/admin/giftcodes/([A-Z0-9_-]{4,64})$#i', $path, $match) && $method === 'PATCH') {
            $code = strtoupper($match[1]);
            $active = $this->toBool($this->body['isActive'] ?? false);
            $gift = Database::one('UPDATE giftcodes SET is_active = ? WHERE code = ? RETURNING code, is_active', [$active, $code]);
            if ($gift === null) {
                throw new HttpException(404, 'Không tìm thấy giftcode.');
            }
            $this->audit('giftcode.toggle', $code, ['isActive' => $active]);
            return self::response(['giftcode' => $gift]);
        }

        if ($method === 'GET' && $path === '/api/admin/bot-menus') {
            $menus = Database::all('SELECT * FROM bot_menu_configs ORDER BY bot_key');
            return self::response(['menus' => array_map(fn (array $row): array => $this->publicMenu($row), $menus)]);
        }
        if (preg_match('#^/api/admin/bot-menus/(primary|secondary)$#', $path, $match) && $method === 'PATCH') {
            return $this->saveBotMenu($match[1]);
        }

        if ($method === 'GET' && $path === '/api/admin/giftcodes') {
            return self::response(['giftcodes' => Database::all('SELECT * FROM giftcodes ORDER BY created_at DESC LIMIT 200')]);
        }
        if ($method === 'POST' && $path === '/api/admin/giftcodes') {
            $code = strtoupper($this->text($this->body['code'] ?? bin2hex(random_bytes(5)), 64));
            $reward = $this->parseMoney($this->body['rewardAmount'] ?? 0, true);
            $max = (int) ($this->body['maxRedemptions'] ?? 1);
            $expires = $this->body['expiresAt'] ?? null;
            if (!preg_match('/^[A-Z0-9_-]{4,64}$/', $code)) {
                throw new HttpException(400, 'Mã giftcode chỉ gồm A-Z, số, _ hoặc -.');
            }
            if ($max < 1 || $max > 100000) {
                throw new HttpException(400, 'Số lượt sử dụng không hợp lệ.');
            }
            if ($expires !== null && $expires !== '') {
                try {
                    $expires = (new DateTimeImmutable((string) $expires))->format(DATE_ATOM);
                } catch (Throwable) {
                    throw new HttpException(400, 'Ngày hết hạn không hợp lệ.');
                }
            } else {
                $expires = null;
            }
            $gift = Database::one(
                'INSERT INTO giftcodes (code, reward_amount, max_redemptions, expires_at) VALUES (?, ?, ?, ?) RETURNING *',
                [$code, $reward, $max, $expires]
            );
            $this->audit('giftcode.create', $code, ['reward' => $reward, 'maxRedemptions' => $max]);
            return self::response(['giftcode' => $gift], 201);
        }

        throw new HttpException(404, 'Không tìm thấy API quản trị.');
    }

    private function updateOrder(string $id): array
    {
        $status = $this->text($this->body['status'] ?? '', 24);
        $note = $this->text($this->body['adminNote'] ?? '', 1000);
        $delivery = $this->text($this->body['deliveryText'] ?? '', 5000);
        if (!in_array($status, self::ORDER_STATUSES, true)) {
            throw new HttpException(400, 'Trạng thái đơn hàng không hợp lệ.');
        }

        [$order, $refunded] = Database::transaction(function () use ($id, $status, $note, $delivery): array {
            $current = Database::one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
            if ($current === null) {
                throw new HttpException(404, 'Không tìm thấy đơn hàng.');
            }
            $terminal = ['completed', 'rejected', 'cancelled', 'refunded'];
            if (in_array($current['status'], $terminal, true) && $current['status'] !== $status) {
                throw new HttpException(409, 'Đơn hàng đã kết thúc và không thể thay đổi trạng thái.');
            }

            $order = Database::one(
                'UPDATE orders SET status = ?, admin_note = ?, delivery_text = ?, updated_at = NOW() WHERE id = ? RETURNING *',
                [$status, $note, $delivery, $id]
            );
            $refunded = false;

            if (in_array($status, ['rejected', 'cancelled', 'refunded'], true) && !in_array($current['status'], $terminal, true)) {
                $amount = (int) $current['amount'];
                if ($amount > 0) {
                    $credit = Database::run(
                        "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'ORDER_REFUND', ?) ON CONFLICT (reference_code) DO NOTHING RETURNING id",
                        ['refund:' . $current['order_code'], $current['user_id'], $amount, 'Hoàn tiền đơn ' . $current['order_code']]
                    );
                    if ($credit->rowCount() > 0) {
                        Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$amount, $current['user_id']]);
                        Database::run(
                            "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'ORDER_REFUND', ?, 'paid')",
                            [$current['user_id'], $amount, $current['order_code']]
                        );
                        $refunded = true;
                    }
                }
                if ($current['product_id'] !== null) {
                    Database::run('UPDATE products SET stock = stock + 1, updated_at = NOW() WHERE id = ? AND stock IS NOT NULL', [$current['product_id']]);
                }
            }

            if ($status === 'completed' && $current['status'] !== 'completed' && $current['product_id'] !== null) {
                $product = Database::one('SELECT vip_level FROM products WHERE id = ?', [$current['product_id']]);
                if ($product !== null && (int) $product['vip_level'] > 0) {
                    Database::run('UPDATE users SET vip_level = GREATEST(vip_level, ?) WHERE user_id = ?', [(int) $product['vip_level'], $current['user_id']]);
                }
            }

            return [$order, $refunded];
        });

        $this->audit('order.update', (string) $order['order_code'], ['status' => $status, 'refunded' => $refunded]);
        if ($status === 'completed') {
            $message = 'Đơn hàng ' . $order['order_code'] . ' đã hoàn tất.' . ($delivery !== '' ? "\nThông tin bàn giao:\n" . htmlspecialchars($delivery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '');
        } elseif ($refunded) {
            $message = 'Đơn hàng ' . $order['order_code'] . ' đã bị hủy/từ chối. Số tiền ' . self::moneyFormat($order['amount']) . ' đã được hoàn vào ví.';
        } else {
            $message = 'Đơn hàng ' . $order['order_code'] . ' đã cập nhật trạng thái: ' . $status;
        }
        $this->notify((string) $order['user_id'], $message);
        return self::response(['order' => $order, 'refunded' => $refunded]);
    }

    private function saveBotMenu(string $botKey): array
    {
        if ($botKey === 'secondary' && TelegramAuth::token('secondary') === '') {
            throw new HttpException(409, 'BOT2_TOKEN chưa được cấu hình trong Environment.');
        }
        $mode = (string) ($this->body['menuMode'] ?? '');
        $columns = (int) ($this->body['menuColumns'] ?? 0);
        if (!in_array($mode, ['reply_keyboard', 'inline_keyboard'], true)) {
            throw new HttpException(400, 'Loại menu phải là reply_keyboard hoặc inline_keyboard.');
        }
        if ($columns < 1 || $columns > 3) {
            throw new HttpException(400, 'Số cột menu phải từ 1 đến 3.');
        }

        $labels = $this->validateMap($this->body['buttonLabels'] ?? [], 'Tên nút', 60);
        $descriptions = $this->validateMap($this->body['buttonDescriptions'] ?? [], 'Mô tả nút', 160);
        $styles = $this->validateMap($this->body['buttonStyles'] ?? [], 'Màu nút', 12, ['primary', 'success', 'danger']);
        $intro = $this->text($this->body['introText'] ?? '', 600);
        $persistent = array_key_exists('persistentKeyboard', $this->body) ? $this->toBool($this->body['persistentKeyboard']) : true;
        $resize = array_key_exists('resizeKeyboard', $this->body) ? $this->toBool($this->body['resizeKeyboard']) : true;

        $saved = Database::one(
            'INSERT INTO bot_menu_configs (bot_key, menu_mode, intro_text, menu_columns, persistent_keyboard, resize_keyboard, button_labels, button_descriptions, button_styles, updated_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, NOW()) ' .
            'ON CONFLICT (bot_key) DO UPDATE SET menu_mode = EXCLUDED.menu_mode, intro_text = EXCLUDED.intro_text, menu_columns = EXCLUDED.menu_columns, ' .
            'persistent_keyboard = EXCLUDED.persistent_keyboard, resize_keyboard = EXCLUDED.resize_keyboard, button_labels = EXCLUDED.button_labels, ' .
            'button_descriptions = EXCLUDED.button_descriptions, button_styles = EXCLUDED.button_styles, updated_at = NOW() RETURNING *',
            [$botKey, $mode, $intro, $columns, $persistent, $resize, json_encode($labels, JSON_UNESCAPED_UNICODE), json_encode($descriptions, JSON_UNESCAPED_UNICODE), json_encode($styles, JSON_UNESCAPED_UNICODE)]
        );
        $this->audit('bot-menu.update', $botKey, ['menuMode' => $mode, 'menuColumns' => $columns]);
        return self::response([
            'success' => true,
            'menu' => $this->publicMenu($saved),
            'message' => 'Đã lưu cấu hình menu. Thay đổi có hiệu lực ở lần mở menu tiếp theo.',
        ]);
    }

    private function exchangeLegacySession(): array
    {
        $ticket = $this->text($this->body['ticket'] ?? '', 128);
        $secret = (string) Env::get('LEGACY_SESSION_SECRET', '');
        if (strlen($secret) < 32) {
            throw new HttpException(503, 'Cổng đăng nhập website cũ chưa được cấu hình.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $ticket)) {
            throw new HttpException(400, 'Login ticket không hợp lệ.');
        }

        $identity = Database::transaction(function () use ($ticket): array {
            $hash = hash('sha256', $ticket);
            $found = Database::one(
                'SELECT user_id, bot_key FROM legacy_login_tickets WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW() FOR UPDATE',
                [$hash]
            );
            if ($found === null) {
                throw new HttpException(401, 'Link đăng nhập đã hết hạn hoặc đã được sử dụng.');
            }
            Database::run('UPDATE legacy_login_tickets SET consumed_at = NOW() WHERE token_hash = ?', [$hash]);
            $user = Database::one('SELECT user_id, name, username, vip_level, balance FROM users WHERE user_id = ?', [$found['user_id']]);
            if ($user === null) {
                throw new HttpException(404, 'Không tìm thấy hồ sơ thành viên.');
            }
            return ['identity' => $found, 'user' => $user];
        });

        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64Url(json_encode([
            'sub' => (string) $identity['user']['user_id'],
            'name' => $identity['user']['name'],
            'username' => $identity['user']['username'],
            'vipLevel' => (int) $identity['user']['vip_level'],
            'balance' => (int) $identity['user']['balance'],
            'bot' => $identity['identity']['bot_key'],
            'aud' => 'hendy-legacy-account',
            'iat' => $now,
            'exp' => $now + 300,
        ], JSON_UNESCAPED_UNICODE));
        $unsigned = $header . '.' . $payload;
        $signature = self::base64Url(hash_hmac('sha256', $unsigned, $secret, true));
        return self::response(['token' => $unsigned . '.' . $signature, 'tokenType' => 'Bearer', 'expiresInSeconds' => 300]);
    }

    private function userRow(): array
    {
        $userId = (string) $this->user['id'];
        $name = $this->text(trim((string) ($this->user['first_name'] ?? '') . ' ' . (string) ($this->user['last_name'] ?? '')), 120);
        $username = $this->text($this->user['username'] ?? '', 64);
        $row = Database::one(
            "INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET " .
            "name = CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE users.name END, " .
            "username = CASE WHEN EXCLUDED.username <> '' THEN EXCLUDED.username ELSE users.username END " .
            'RETURNING user_id, name, username, balance, vip_level, total_deposited, nyc_wallet, voucher_wallet, point_old, created_at',
            [$userId, $name, $username]
        ) ?? [];
        return [
            'id' => $userId,
            'name' => $row['name'] ?: ($username ?: 'Thành viên'),
            'username' => $row['username'],
            'balance' => (int) $row['balance'],
            'mainWallet' => (int) $row['balance'],
            'nycWallet' => (int) $row['nyc_wallet'],
            'voucherWallet' => (int) $row['voucher_wallet'],
            'vipLevel' => (int) $row['vip_level'],
            'totalDeposited' => (int) $row['total_deposited'],
            'pointOld' => (int) $row['point_old'],
            'createdAt' => $row['created_at'],
        ];
    }

    private function listProducts(?string $category, bool $includeInactive): array
    {
        $where = [];
        $parameters = [];
        if ($category !== null && $category !== '') {
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new HttpException(400, 'Danh mục không hợp lệ.');
            }
            $where[] = 'category = ?';
            $parameters[] = $category;
        }
        if (!$includeInactive) {
            $where[] = 'is_active = TRUE';
        }
        $sql = 'SELECT * FROM products' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY category, created_at DESC LIMIT 250';
        return array_map(self::publicProduct(...), Database::all($sql, $parameters));
    }

    private function settings(array $keys, bool $publicOnly): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT key, value FROM app_settings WHERE key IN ($placeholders)" . ($publicOnly ? ' AND is_public = TRUE' : '');
        $result = [];
        foreach (Database::all($sql, $keys) as $row) {
            $result[$row['key']] = $row['value'];
        }
        return $result;
    }

    private function audit(string $action, string $target, array $details): void
    {
        Realtime::publish('admin.' . $action, ['target' => $target, 'details' => $details]);
        Database::run(
            'INSERT INTO admin_audit (admin_user_id, action, target, details) VALUES (?, ?, ?, ?::jsonb)',
            [(string) $this->user['id'], $action, $target, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private function notify(string $userId, string $message, ?string $botKey = null): void
    {
        $token = TelegramAuth::token($botKey ?? $this->botKey);
        if ($token === '') {
            return;
        }
        try {
            (new TelegramApi($token))->sendMessage($userId, $message);
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo Telegram: ' . $error->getMessage());
        }
    }

    private function notifyAdmins(string $message): void
    {
        foreach (TelegramAuth::adminIds() as $adminId) {
            $this->notify($adminId, $message);
        }
    }

    private function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new HttpException(403, 'Bạn không có quyền quản trị.');
        }
    }

    private function isAdmin(): bool
    {
        return (bool) ($this->user['_is_admin'] ?? false);
    }

    private function publicMenu(array $row): array
    {
        return [
            'botKey' => $row['bot_key'],
            'configured' => TelegramAuth::token($row['bot_key']) !== '',
            'menuMode' => $row['menu_mode'],
            'introText' => $row['intro_text'],
            'menuColumns' => (int) $row['menu_columns'],
            'persistentKeyboard' => $this->toBool($row['persistent_keyboard']),
            'resizeKeyboard' => $this->toBool($row['resize_keyboard']),
            'buttonLabels' => self::jsonArray($row['button_labels']),
            'buttonDescriptions' => self::jsonArray($row['button_descriptions']),
            'buttonStyles' => self::jsonArray($row['button_styles']),
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function validateMap(mixed $value, string $label, int $maximum, ?array $allowed = null): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new HttpException(400, $label . ' phải là JSON hợp lệ.');
            }
            $value = $decoded;
        }
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new HttpException(400, $label . ' phải là đối tượng JSON.');
        }
        $result = [];
        foreach ($value as $key => $raw) {
            if (!in_array((string) $key, self::MENU_ACTIONS, true)) {
                throw new HttpException(400, 'Khóa nút không hợp lệ: ' . $key);
            }
            $text = trim((string) $raw);
            if (mb_strlen($text) > $maximum) {
                throw new HttpException(400, $label . ' có giá trị quá dài.');
            }
            if ($allowed !== null && $text !== '' && !in_array($text, $allowed, true)) {
                throw new HttpException(400, 'Màu nút không hợp lệ.');
            }
            if ($text !== '') {
                $result[(string) $key] = $text;
            }
        }
        return $result;
    }

    private function text(mixed $value, int $maximum): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new HttpException(400, 'Dữ liệu văn bản không hợp lệ.');
        }
        return mb_substr(trim((string) ($value ?? '')), 0, $maximum);
    }

    private function parseMoney(mixed $value, bool $allowZero): int
    {
        if (!is_numeric($value) || (string) $value === '') {
            throw new HttpException(400, 'Số tiền không hợp lệ.');
        }
        $amount = filter_var($value, FILTER_VALIDATE_INT);
        if ($amount === false || $amount < ($allowZero ? 0 : 1) || $amount > 9000000000000000) {
            throw new HttpException(400, 'Số tiền không hợp lệ.');
        }
        return (int) $amount;
    }

    private function toBool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function publicProduct(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'category' => $row['category'],
            'description' => $row['description'],
            'price' => (int) $row['price'],
            'durationDays' => (int) $row['duration_days'],
            'stock' => $row['stock'] === null ? null : (int) $row['stock'],
            'vipLevel' => (int) $row['vip_level'],
            'imageUrl' => $row['image_url'] ?? '',
            'isActive' => filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
            'metadata' => self::jsonArray($row['metadata'] ?? []),
        ];
    }

    private static function slug(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', (string) ($normalized ?: $value)), '-');
        return mb_substr($slug !== '' ? $slug : bin2hex(random_bytes(4)), 0, 70);
    }

    private static function validateHttps(string $url, string $message): void
    {
        if ($url !== '' && !preg_match('/^https:\/\/\S+$/i', $url)) {
            throw new HttpException(400, $message);
        }
    }

    private static function orderCode(): string
    {
        return 'HD' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(3)));
    }

    private static function moneyFormat(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' VNĐ';
    }


    private static function base64Url(string|false $value): string
    {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private static function response(array $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
