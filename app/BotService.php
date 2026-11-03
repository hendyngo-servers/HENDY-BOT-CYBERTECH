<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

final class BotService
{
    private const TTL_MINUTES = 10;
    private const BRANDS = ['QQ88', '78WIN', 'SC88', 'C168', 'CM88', 'F8BET'];

    private const MENU = [
        ['action' => 'live', 'label' => '🚀 MINIGAME LIVE', 'description' => 'Liên kết và quản lý tài khoản trò chơi.', 'style' => 'primary'],
        ['action' => 'tools', 'label' => '🧰 THUÊ TOOL', 'description' => 'Xem công cụ và thời hạn thuê.', 'style' => 'primary'],
        ['action' => 'codes', 'label' => '🎁 MUA CODE', 'description' => 'Mua code và theo dõi đơn hàng.', 'style' => 'success'],
        ['action' => 'wallet', 'label' => '💳 NẠP TIỀN', 'description' => 'Tạo thông tin VietQR và xem lịch sử nạp.', 'style' => 'success'],
        ['action' => 'otp', 'label' => '⚙️ OTP & PROXY', 'description' => 'Xem các gói OTP và Proxy đang mở bán.', 'style' => 'primary'],
        ['action' => 'viptools', 'label' => '🏆 KHO VIP', 'description' => 'Khám phá kho công cụ dành cho thành viên VIP.', 'style' => 'primary'],
        ['action' => 'customer', 'label' => '💎 TRUNG TÂM KHÁCH HÀNG', 'description' => 'Xem hồ sơ, số dư, đơn hàng và liên kết.', 'style' => 'primary'],
        ['action' => 'duel', 'label' => '⚔️ GHÉP ĐÔI', 'description' => 'Vào hàng chờ và ghép với thành viên khác.', 'style' => 'primary'],
        ['action' => 'ref', 'label' => '🎊 GIỚI THIỆU REF', 'description' => 'Chia sẻ link và theo dõi hoa hồng giới thiệu.', 'style' => 'success'],
        ['action' => 'vip', 'label' => '👑 TRUNG TÂM VIP', 'description' => 'Xem cấp VIP và gói nâng cấp.', 'style' => 'primary'],
        ['action' => 'group', 'label' => '🗣️ NHÓM CHAT', 'description' => 'Mở liên kết cộng đồng chính thức.', 'style' => 'primary'],
        ['action' => 'news', 'label' => '📢 KÊNH THÔNG BÁO', 'description' => 'Theo dõi thông báo và cập nhật mới.', 'style' => 'primary'],
        ['action' => 'gift', 'label' => '🎟️ GIFTCODE', 'description' => 'Nhập mã quà tặng vào tài khoản.', 'style' => 'success'],
        ['action' => 'support', 'label' => '🧑‍💻 LIÊN HỆ CSKH', 'description' => 'Gửi yêu cầu hỗ trợ tới đội ngũ CSKH.', 'style' => 'primary'],
    ];

    private TelegramApi $telegram;
    private string $botKey;
    private string $adminId;

    public function __construct(string $botKey)
    {
        $this->botKey = $botKey === 'secondary' ? 'secondary' : 'primary';
        $this->adminId = TelegramAuth::adminId();
        $token = TelegramAuth::token($this->botKey);
        if ($token === '') {
            throw new RuntimeException('Token cho ' . $this->botKey . ' chưa được cấu hình.');
        }
        $this->telegram = new TelegramApi($token);
    }

    public function api(): TelegramApi
    {
        return $this->telegram;
    }

    public function handle(array $update): void
    {
        $updateId = isset($update['update_id']) ? (int) $update['update_id'] : null;
        if ($updateId !== null) {
            $processed = Database::one(
                'SELECT update_id FROM telegram_updates WHERE bot_key = ? AND update_id = ?',
                [$this->botKey, $updateId]
            );
            if ($processed !== null) {
                return;
            }
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }

        Realtime::publish('bot.update', ['botKey' => $this->botKey, 'updateId' => $updateId, 'kind' => isset($update['message']) ? 'message' : (isset($update['callback_query']) ? 'callback' : 'other')]);

        if ($updateId !== null) {
            Database::run(
                'INSERT INTO telegram_updates (bot_key, update_id) VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$this->botKey, $updateId]
            );
        }
    }

    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? [];
        $chat = $message['chat'] ?? [];
        $userId = (string) ($from['id'] ?? '');
        $chatId = (string) ($chat['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if (!preg_match('/^\d{5,20}$/', $userId) || $chatId === '' || $text === '') {
            return;
        }

        $firstName = trim((string) ($from['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? ''));

        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/u', $text, $matches)) {
            $payload = trim((string) ($matches[1] ?? ''));
            $referrer = preg_match('/^ref_(\d{5,20})$/', $payload, $refMatch) ? $refMatch[1] : null;
            if ($referrer === $userId) {
                $referrer = null;
            }
            Database::run(
                'INSERT INTO users (user_id, name, username, ref_by) VALUES (?, ?, ?, ?) ' .
                'ON CONFLICT (user_id) DO UPDATE SET name = CASE WHEN EXCLUDED.name <> \'\' THEN EXCLUDED.name ELSE users.name END, ' .
                'username = CASE WHEN EXCLUDED.username <> \'\' THEN EXCLUDED.username ELSE users.username END',
                [$userId, trim($firstName . ' ' . $lastName), $username, $referrer]
            );
            $this->clearPending($userId);
            $this->sendHome($chatId, $from);
            return;
        }

        if (preg_match('/^\/menu(?:@\w+)?$/u', $text)) {
            $this->sendHome($chatId, $from);
            return;
        }

        if (preg_match('/^\/help(?:@\w+)?$/u', $text)) {
            $this->telegram->sendMessage($chatId, implode("\n", [
                '🤖 <b>HENDY BOT</b>',
                '/start — Mở menu chính',
                '/menu — Hiển thị lại menu',
                '/orders — Xem đơn hàng',
                '/admin — Mở bảng quản trị (chỉ Admin)',
                '/help — Hướng dẫn sử dụng',
                '',
                'Bấm nút ở bàn phím phía dưới để mở chức năng.',
            ]));
            return;
        }

        if (preg_match('/^\/orders(?:@\w+)?$/u', $text)) {
            $this->showOrders($chatId, $userId);
            return;
        }

        if (preg_match('/^\/admin(?:@\w+)?$/u', $text)) {
            $this->openAdmin($chatId, $userId);
            return;
        }

        if (preg_match('/^\/addmoney(?:@\w+)?\s+(\d{5,20})\s+(\d+)$/u', $text, $matches)) {
            $this->addMoney($chatId, $userId, $matches[1], (int) $matches[2]);
            return;
        }

        if (str_starts_with($text, '/')) {
            return;
        }

        $pending = $this->getPending($userId);
        if ($pending !== null && $this->processPending($chatId, $userId, $text, $pending)) {
            return;
        }

        $action = $this->actionForText($text, $userId);
        if ($action !== null) {
            $this->dispatchAction($chatId, $userId, $action);
        }
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $from = $callback['from'] ?? [];
        $userId = (string) ($from['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = (string) ($message['chat']['id'] ?? $userId);
        $data = (string) ($callback['data'] ?? '');

        try {
            if ($callbackId !== '') {
                $this->telegram->answerCallback($callbackId);
            }
            if (!preg_match('/^\d{5,20}$/', $userId) || !str_starts_with($data, 'member:')) {
                return;
            }
            $action = substr($data, 7);
            $this->dispatchAction($chatId, $userId, $action);
        } catch (Throwable $error) {
            error_log('[telegram:' . $this->botKey . '] ' . $error->getMessage());
            $this->telegram->sendMessage($chatId, '❌ ' . self::escape($error->getMessage()));
        }
    }

    private function sendHome(string $chatId, array $from): void
    {
        $userId = (string) ($from['id'] ?? '');
        $name = trim((string) ($from['first_name'] ?? '') . ' ' . (string) ($from['last_name'] ?? ''));
        $username = trim((string) ($from['username'] ?? ''));
        $user = $this->getUser($userId, $name, $username);
        $config = $this->menuConfig();
        $items = $this->menuItems($config, $userId);
        $descriptions = array_map(
            static fn (array $item): string => '• ' . $item['label'] . ' — ' . $item['description'],
            $items
        );

        $text = implode("\n", [
            '🤖 <b>HENDY BOT | HENDY CYBERTECH</b>',
            'Xin chào <b>' . self::escape($name !== '' ? $name : ($username ?: 'thành viên')) . '</b>!',
            '🆔 ID Telegram: <code>' . self::escape($userId) . '</code>',
            '━━━━━━━━━━━━━━━━━━━━',
            '💰 Ví chính: <b>' . self::money($user['balance'] ?? 0) . '</b>',
            '👑 Cấp thành viên: <b>VIP ' . (int) ($user['vip_level'] ?? 0) . '</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            self::escape((string) ($config['intro_text'] ?? 'Chào mừng bạn đến với HENDY BOT.')),
            '',
            '<b>HƯỚNG DẪN CHỨC NĂNG</b>',
            ...array_map(self::escape(...), $descriptions),
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $this->menuMarkup($items, $config),
        ]);
    }

    private function menuConfig(): array
    {
        $row = Database::one('SELECT * FROM bot_menu_configs WHERE bot_key = ?', [$this->botKey]);
        return $row ?? [
            'bot_key' => $this->botKey,
            'menu_mode' => 'reply_keyboard',
            'intro_text' => 'Chào mừng bạn đến với HENDY BOT. Chọn dịch vụ ở bàn phím phía dưới để bắt đầu.',
            'menu_columns' => 2,
            'persistent_keyboard' => true,
            'resize_keyboard' => true,
            'button_labels' => [],
            'button_descriptions' => [],
            'button_styles' => [],
        ];
    }

    private function menuItems(array $config, string $userId): array
    {
        $labels = self::jsonArray($config['button_labels'] ?? []);
        $descriptions = self::jsonArray($config['button_descriptions'] ?? []);
        $styles = self::jsonArray($config['button_styles'] ?? []);
        $items = [];

        foreach (self::MENU as $item) {
            $action = $item['action'];
            $items[] = [
                ...$item,
                'label' => self::truncate((string) ($labels[$action] ?? $item['label']), 60),
                'description' => self::truncate((string) ($descriptions[$action] ?? $item['description']), 160),
                'style' => in_array($styles[$action] ?? '', ['primary', 'success', 'danger'], true) ? $styles[$action] : $item['style'],
            ];
        }

        if ($this->adminId !== '' && $userId === $this->adminId) {
            $items[] = [
                'action' => 'admin',
                'label' => self::truncate((string) ($labels['admin'] ?? '🛡️ BẢNG ĐIỀU KHIỂN ADMIN'), 60),
                'description' => self::truncate((string) ($descriptions['admin'] ?? 'Mở bảng quản trị dành riêng cho Admin.'), 160),
                'style' => in_array($styles['admin'] ?? '', ['primary', 'success', 'danger'], true) ? $styles['admin'] : 'primary',
            ];
        }
        return $items;
    }

    private function menuMarkup(array $items, array $config): array
    {
        $columns = max(1, min(3, (int) ($config['menu_columns'] ?? 2)));
        if (($config['menu_mode'] ?? 'reply_keyboard') === 'inline_keyboard') {
            $rows = [];
            foreach (array_chunk($items, $columns) as $chunk) {
                $row = [];
                foreach ($chunk as $item) {
                    $button = [
                        'text' => $item['label'],
                        'style' => in_array($item['style'] ?? '', ['primary', 'success', 'danger'], true)
                            ? $item['style']
                            : 'primary',
                    ];
                    if ($item['action'] === 'admin') {
                        $url = $this->webAppUrl('admin');
                        if ($url !== '') {
                            $button['web_app'] = ['url' => $url];
                        } else {
                            $button['callback_data'] = 'member:admin';
                        }
                    } else {
                        $button['callback_data'] = 'member:' . $item['action'];
                    }
                    $row[] = $button;
                }
                $rows[] = $row;
            }
            return ['inline_keyboard' => $rows];
        }
        return $this->replyMenu($items, $config);
    }

    private function replyMenu(array $items, array $config): array
    {
        $columns = max(1, min(3, (int) ($config['menu_columns'] ?? 2)));
        $rows = [];
        foreach (array_chunk($items, $columns) as $chunk) {
            $row = [];
            foreach ($chunk as $item) {
                $button = [
                    'text' => $item['label'],
                    'style' => in_array($item['style'] ?? '', ['primary', 'success', 'danger'], true)
                        ? $item['style']
                        : 'primary',
                ];
                if ($item['action'] === 'admin') {
                    $url = $this->webAppUrl('admin');
                    if ($url !== '') {
                        $button['web_app'] = ['url' => $url];
                    }
                }
                $row[] = $button;
            }
            $rows[] = $row;
        }

        return [
            'keyboard' => $rows,
            'resize_keyboard' => $this->toBool($config['resize_keyboard'] ?? true),
            'is_persistent' => $this->toBool($config['persistent_keyboard'] ?? true),
            'input_field_placeholder' => 'Chọn chức năng Hendy Bot',
        ];
    }

    private function inlineMenu(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    private function actionForText(string $text, string $userId): ?string
    {
        foreach ($this->menuItems($this->menuConfig(), $userId) as $item) {
            if ($item['label'] === $text) {
                return $item['action'];
            }
        }
        return null;
    }

    private function dispatchAction(string $chatId, string $userId, string $action): void
    {
        if (preg_match('/^order:(\d{1,18})$/', $action, $matches)) {
            $this->createOrder($chatId, $userId, $matches[1]);
            return;
        }

        if (preg_match('/^select-brand:([A-Z0-9]+)$/', $action, $matches)) {
            $brand = strtoupper($matches[1]);
            if (!in_array($brand, self::BRANDS, true)) {
                throw new RuntimeException('Thương hiệu không hợp lệ.');
            }
            $this->setPending($userId, 'link_username', ['brand' => $brand]);
            $this->telegram->sendMessage($chatId, 'Bạn đã chọn <b>' . self::escape($brand) . '</b>. Hãy nhập tên đăng nhập. Không gửi mật khẩu hoặc OTP.');
            return;
        }

        if (preg_match('/^duel-finish:(\d{1,18})$/', $action, $matches)) {
            $this->requestMatchResult($chatId, $userId, $matches[1]);
            return;
        }

        if (preg_match('/^duel-confirm:(\d{1,18}):(yes|no)$/', $action, $matches)) {
            $this->confirmMatchResult($chatId, $userId, $matches[1], $matches[2] === 'yes');
            return;
        }

        if ($action === 'duel-join' || $action === 'duel-join:quick' || $action === 'duel-join:practice') {
            $mode = str_contains($action, 'practice') ? 'practice' : 'quick';
            $this->joinMatch($chatId, $userId, $mode);
            return;
        }

        if ($action === 'duel-leave') {
            $this->leaveMatch($chatId, $userId);
            return;
        }

        if (preg_match('/^link-delete:(\d{1,18})$/', $action, $matches)) {
            Database::run('DELETE FROM linked_accounts WHERE id = ? AND user_id = ?', [$matches[1], $userId]);
            $this->showLiveAccounts($chatId, $userId);
            return;
        }

        switch ($action) {
            case 'home':
                $this->sendHome($chatId, ['id' => $userId]);
                return;
            case 'live':
                $this->showLiveAccounts($chatId, $userId);
                return;
            case 'link-new':
                $rows = [];
                foreach (array_chunk(self::BRANDS, 2) as $chunk) {
                    $row = [];
                    foreach ($chunk as $brand) {
                        $row[] = ['text' => $brand, 'callback_data' => 'member:select-brand:' . $brand];
                    }
                    $rows[] = $row;
                }
                $this->telegram->sendMessage($chatId, '👉 <b>CHỌN HỆ THỐNG TÀI KHOẢN</b>' . "\nChỉ lưu username; không gửi mật khẩu, OTP hoặc mã xác minh.", [
                    'reply_markup' => $this->inlineMenu($rows),
                ]);
                return;
            case 'tools':
                $this->showProducts($chatId, ['tools'], '🧰 THUÊ TOOL');
                return;
            case 'codes':
                $this->showProducts($chatId, ['codes'], '🎁 KHO MÃ CODE');
                return;
            case 'otp':
                $this->showProducts($chatId, ['otp', 'proxy'], '⚙️ OTP & PROXY');
                return;
            case 'viptools':
                $this->showProducts($chatId, ['vip'], '🏆 KHO VIP');
                return;
            case 'wallet':
                $this->setPending($userId, 'wallet');
                $this->telegram->sendMessage($chatId, '💳 Nhập số tiền cần nạp (từ 10.000 đến 500.000.000 VNĐ). Ví dụ: <code>50000</code>.');
                return;
            case 'deposit-history':
                $this->showDepositHistory($chatId, $userId);
                return;
            case 'orders':
                $this->showOrders($chatId, $userId);
                return;
            case 'customer':
                $this->showCustomer($chatId, $userId);
                return;
            case 'duel':
                $this->showMatchmaking($chatId, $userId);
                return;
            case 'ref':
                $this->showReferral($chatId, $userId);
                return;
            case 'vip':
                $this->showVip($chatId, $userId);
                return;
            case 'group':
            case 'news':
                $this->showCommunityLink($chatId, $action);
                return;
            case 'gift':
                $this->setPending($userId, 'giftcode');
                $this->telegram->sendMessage($chatId, '🎟️ Vui lòng nhập mã Giftcode trong vòng 10 phút.');
                return;
            case 'support':
                $this->setPending($userId, 'support');
                $this->telegram->sendMessage($chatId, '🧑‍💻 Hãy mô tả vấn đề cần hỗ trợ. Bot sẽ tạo ticket và gửi thông tin cho Admin.');
                return;
            case 'admin':
                $this->openAdmin($chatId, $userId);
                return;
            default:
                $this->telegram->sendMessage($chatId, 'Chức năng chưa được cấu hình. Hãy chọn lại menu chính bằng /menu.');
        }
    }

    private function showLiveAccounts(string $chatId, string $userId): void
    {
        $accounts = Database::all(
            'SELECT id, brand, username, created_at FROM linked_accounts WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
            [$userId]
        );
        $lines = ['🚀 <b>MINIGAME LIVE</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($accounts as $account) {
            $lines[] = '• <b>' . self::escape($account['brand']) . '</b> — <code>' . self::escape($account['username']) . '</code>';
        }
        if ($accounts === []) {
            $lines[] = 'Bạn chưa liên kết tài khoản nào.';
        }
        $rows = [[['text' => '＋ LIÊN KẾT TÀI KHOẢN', 'callback_data' => 'member:link-new']]];
        foreach (array_chunk($accounts, 2) as $chunk) {
            $row = [];
            foreach ($chunk as $account) {
                $row[] = [
                    'text' => 'Xóa #' . $account['id'],
                    'callback_data' => 'member:link-delete:' . $account['id'],
                    'style' => 'danger',
                ];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function showProducts(string $chatId, array $categories, string $title): void
    {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $products = Database::all(
            "SELECT id, name, price, description, stock, duration_days FROM products " .
            "WHERE category IN ($placeholders) AND is_active = TRUE AND (stock IS NULL OR stock > 0) " .
            'ORDER BY created_at DESC LIMIT 30',
            $categories
        );

        $lines = [$title, '━━━━━━━━━━━━━━━━━━━━', 'Chọn sản phẩm để tạo đơn hàng:'];
        $rows = [];
        foreach ($products as $product) {
            $label = self::truncate($product['name'] . ' · ' . self::money($product['price']), 55);
            $rows[] = [[
                'text' => $label,
                'callback_data' => 'member:order:' . $product['id'],
                'style' => 'success',
            ]];
            if (trim((string) $product['description']) !== '') {
                $lines[] = '• <b>' . self::escape($product['name']) . '</b> — ' . self::money($product['price']);
                $lines[] = '  ' . self::escape(self::truncate((string) $product['description'], 180));
            }
        }

        if ($products === []) {
            $lines[] = 'Hiện chưa có sản phẩm được Admin đăng bán.';
        }
        $rows[] = [['text' => '🧾 ĐƠN HÀNG CỦA TÔI', 'callback_data' => 'member:orders']];
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function createOrder(string $chatId, string $userId, string $productId): void
    {
        $order = Database::transaction(function () use ($userId, $productId): array {
            $product = Database::one('SELECT * FROM products WHERE id = ? AND is_active = TRUE FOR UPDATE', [$productId]);
            if ($product === null) {
                throw new RuntimeException('Sản phẩm không còn được bán.');
            }
            if ($product['stock'] !== null && (int) $product['stock'] <= 0) {
                throw new RuntimeException('Sản phẩm đã hết hàng.');
            }
            $user = Database::one('SELECT balance FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) {
                throw new RuntimeException('Không tìm thấy hồ sơ thành viên. Hãy gửi /start trước.');
            }
            $price = (int) $product['price'];
            if ((int) $user['balance'] < $price) {
                throw new RuntimeException('Số dư không đủ. Hãy chọn NẠP TIỀN trước khi mua.');
            }

            $orderCode = 'HD' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(3)));
            Database::run('UPDATE users SET balance = balance - ? WHERE user_id = ?', [$price, $userId]);
            $created = Database::one(
                "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) " .
                "VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING order_code, product_name, amount, status",
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
            $balance = Database::value('SELECT balance FROM users WHERE user_id = ?', [$userId]);
            return [...($created ?? []), 'balance' => (int) $balance];
        });

        $this->telegram->sendMessage($chatId, implode("\n", [
            '✅ <b>ĐÃ TẠO ĐƠN HÀNG</b>',
            'Sản phẩm: <b>' . self::escape((string) $order['product_name']) . '</b>',
            'Mã đơn: <code>' . self::escape((string) $order['order_code']) . '</code>',
            'Số tiền: ' . self::money($order['amount']),
            'Trạng thái: Chờ Admin xử lý.',
            'Số dư còn lại: ' . self::money($order['balance']),
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🧾 LỊCH SỬ ĐƠN HÀNG', 'callback_data' => 'member:orders']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showOrders(string $chatId, string $userId): void
    {
        $orders = Database::all(
            'SELECT order_code, product_name, amount, status, delivery_text, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 15',
            [$userId]
        );
        $lines = ['🧾 <b>ĐƠN HÀNG CỦA TÔI</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($orders as $order) {
            $lines[] = '• <b>' . self::escape($order['order_code']) . '</b> — ' . self::escape($order['product_name']);
            $lines[] = '  ' . self::money($order['amount']) . ' · ' . self::statusLabel($order['status']);
            if ($order['status'] === 'completed' && trim((string) $order['delivery_text']) !== '') {
                $lines[] = '  <b>Bàn giao:</b> ' . self::escape(self::truncate((string) $order['delivery_text'], 300));
            }
        }
        if ($orders === []) {
            $lines[] = 'Bạn chưa có đơn hàng.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([[['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']]]),
        ]);
    }

    private function showCustomer(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $accounts = (int) Database::value('SELECT COUNT(*) FROM linked_accounts WHERE user_id = ?', [$userId]);
        $orders = (int) Database::value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$userId]);
        $tickets = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
        $this->telegram->sendMessage($chatId, implode("\n", [
            '💎 <b>TRUNG TÂM KHÁCH HÀNG</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '💰 Số dư: <b>' . self::money($user['balance'] ?? 0) . '</b>',
            '👑 Cấp thành viên: <b>VIP ' . (int) ($user['vip_level'] ?? 0) . '</b>',
            '🔗 Tài khoản liên kết: <b>' . $accounts . '</b>',
            '🧾 Tổng đơn hàng: <b>' . $orders . '</b>',
            '🎧 Yêu cầu CSKH: <b>' . $tickets . '</b>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🚀 TÀI KHOẢN MINI GAME', 'callback_data' => 'member:live']],
                [['text' => '🧾 ĐƠN HÀNG', 'callback_data' => 'member:orders']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showDepositHistory(string $chatId, string $userId): void
    {
        $transactions = Database::all(
            "SELECT amount, reference_code, created_at FROM transactions WHERE user_id = ? AND type = 'DEPOSIT' ORDER BY created_at DESC LIMIT 10",
            [$userId]
        );
        $lines = ['💳 <b>LỊCH SỬ NẠP TIỀN</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($transactions as $transaction) {
            $lines[] = '• +' . self::money($transaction['amount']) . ' · ' . self::escape($transaction['reference_code'] ?? '—');
        }
        if ($transactions === []) {
            $lines[] = 'Chưa ghi nhận giao dịch nạp tiền.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines));
    }

    private function showReferral(string $chatId, string $userId): void
    {
        $botUsername = ltrim((string) Env::get('BOT_USERNAME', ''), '@');
        if ($botUsername === '') {
            try {
                $me = $this->telegram->getMe();
                $botUsername = (string) ($me['username'] ?? '');
            } catch (Throwable) {
                $botUsername = '';
            }
        }
        $count = (int) Database::value('SELECT COUNT(*) FROM users WHERE ref_by = ?', [$userId]);
        $reward = (int) Database::value(
            "SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'REFERRAL_REWARD'",
            [$userId]
        );
        $rate = (float) (Database::value("SELECT value FROM app_settings WHERE key = 'referral_commission_percent'") ?? '0');
        $link = $botUsername !== '' ? 'https://t.me/' . $botUsername . '?start=ref_' . $userId : 'Chưa lấy được username bot';
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🎊 <b>GIỚI THIỆU BẠN BÈ</b>',
            'Số người đã mời: <b>' . $count . '</b>',
            'Hoa hồng đã nhận: <b>' . self::money($reward) . '</b>',
            'Tỷ lệ hiện tại: <b>' . $rate . '%</b>',
            '',
            '<b>Đường dẫn giới thiệu:</b>',
            '<code>' . self::escape($link) . '</code>',
        ]), [
            'reply_markup' => $this->inlineMenu([[['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']]]),
        ]);
    }

    private function showVip(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $products = Database::all(
            "SELECT id, name, price FROM products WHERE category = 'vip' AND is_active = TRUE ORDER BY price ASC LIMIT 20"
        );
        $rows = [];
        foreach ($products as $product) {
            $rows[] = [[
                'text' => self::truncate($product['name'] . ' · ' . self::money($product['price']), 55),
                'callback_data' => 'member:order:' . $product['id'],
                'style' => 'success',
            ]];
        }
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", [
            '👑 <b>TRUNG TÂM VIP</b>',
            'Cấp hiện tại: <b>VIP ' . (int) ($user['vip_level'] ?? 0) . '</b>',
            'Tổng nạp: <b>' . self::money($user['total_deposited'] ?? 0) . '</b>',
            'Gói VIP đang mở bán: ' . count($products),
        ]), [
            'reply_markup' => $this->inlineMenu($rows),
        ]);
    }

    private function showCommunityLink(string $chatId, string $action): void
    {
        $key = $action === 'group' ? 'GROUP_URL' : 'CHANNEL_URL';
        $url = trim((string) (Database::value('SELECT value FROM app_settings WHERE key = ?', [$key]) ?: Env::get($key, '')));
        if (!preg_match('/^https:\/\/\S+$/i', $url)) {
            throw new RuntimeException('Admin chưa cấu hình ' . $key . '.');
        }
        $label = $action === 'group' ? '🗣️ MỞ NHÓM CHAT' : '📢 MỞ KÊNH THÔNG BÁO';
        $this->telegram->sendMessage($chatId, $action === 'group' ? '🗣️ <b>NHÓM CHAT HENDY</b>' : '📢 <b>KÊNH THÔNG BÁO HENDY</b>', [
            'reply_markup' => $this->inlineMenu([[['text' => $label, 'url' => $url]]]),
        ]);
    }

    private function showMatchmaking(string $chatId, string $userId): void
    {
        $stats = MatchmakingService::ensureStats($userId);
        $match = Database::one(
            "SELECT * FROM matchmaking_matches WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?) ORDER BY created_at DESC LIMIT 1",
            [$userId, $userId]
        );
        if ($match !== null) {
            $playerOne = (string) $match['player_one'];
            $playerTwo = (string) $match['player_two'];
            $isPlayerOne = $playerOne === $userId;
            $opponentId = $isPlayerOne ? $playerTwo : $playerOne;
            $opponent = Database::one('SELECT name, username FROM users WHERE user_id = ?', [$opponentId]);
            $opponentName = ($opponent['name'] ?? '') ?: (($opponent['username'] ?? '') ?: 'Thành viên Hendy');
            $opponentStats = MatchmakingService::ensureStats($opponentId);
            $opponentRating = (int) ($opponentStats['rating'] ?? 1000);
            $result = Database::one('SELECT score_one, score_two, status, reported_by FROM matchmaking_results WHERE match_id = ?', [$match['id']]);

            $summary = '🤝 <b>HENDY MATCH #' . self::escape((string) $match['id']) . '</b>' .
                "\nĐối thủ: <b>" . self::escape($opponentName) . '</b>' .
                "\nElo của bạn: <b>" . (int) $stats['rating'] . '</b> · Điểm: <b>' . (int) $stats['points'] . '</b>' .
                "\nElo đối thủ: <b>" . $opponentRating . '</b>';

            if ($match['status'] === 'disputed') {
                $this->telegram->sendMessage($chatId, $summary . "\n\n⚠️ Kết quả đang tranh chấp. Quản trị viên cần xác minh trước khi cập nhật Elo và điểm.");
                return;
            }

            if ($result !== null && $result['status'] === 'pending') {
                $scoreLabel = $isPlayerOne
                    ? ((int) $result['score_one'] . ' - ' . (int) $result['score_two'])
                    : ((int) $result['score_two'] . ' - ' . (int) $result['score_one']);
                if ($result['reported_by'] === $userId) {
                    $this->telegram->sendMessage($chatId, $summary . "\n\n⏳ Tỷ số bạn gửi: <b>" . $scoreLabel . '</b>. Đang chờ đối thủ xác nhận.');
                } else {
                    $this->telegram->sendMessage($chatId, $summary . "\n\n🧾 Đối thủ báo tỷ số (theo thứ tự của bạn): <b>" . $scoreLabel . '</b>. Bạn hãy xác nhận hoặc báo tranh chấp.', [
                        'reply_markup' => $this->inlineMenu([
                            [['text' => '✅ XÁC NHẬN KẾT QUẢ', 'callback_data' => 'member:duel-confirm:' . $match['id'] . ':yes']],
                            [['text' => '⚠️ BÁO SAI KẾT QUẢ', 'callback_data' => 'member:duel-confirm:' . $match['id'] . ':no']],
                        ]),
                    ]);
                }
                return;
            }

            $this->telegram->sendMessage(
                $chatId,
                $summary . "\n\nSau khi kết thúc trận, gửi tỷ số để đối thủ xác nhận. Chỉ chế độ Ghép nhanh mới thay đổi Elo.",
                ['reply_markup' => $this->inlineMenu([[['text' => '🧾 GỬI TỶ SỐ TRẬN ĐẤU', 'callback_data' => 'member:duel-finish:' . $match['id']]]])]
            );
            return;
        }

        $waiting = Database::one("SELECT id, mode, created_at FROM matchmaking_queue WHERE user_id = ? AND status = 'waiting' ORDER BY created_at DESC LIMIT 1", [$userId]);
        if ($waiting !== null) {
            $this->telegram->sendMessage(
                $chatId,
                '⏳ Bạn đang ở hàng chờ (' . self::escape($waiting['mode']) . '). Elo hiện tại: <b>' . (int) $stats['rating'] . '</b> · Điểm: <b>' . (int) $stats['points'] . '</b>.' . "\nHệ thống sẽ ưu tiên người có Elo tương đương và mở rộng dải ghép theo thời gian chờ.",
                ['reply_markup' => $this->inlineMenu([[['text' => 'RỜI HÀNG CHỜ', 'callback_data' => 'member:duel-leave']]])]
            );
            return;
        }

        $leaders = MatchmakingService::leaderboard(5);
        $ranking = [];
        foreach ($leaders as $index => $leader) {
            $ranking[] = ($index + 1) . '. ' . self::escape((string) $leader['display_name']) .
                ' · Elo ' . (int) $leader['rating'] . ' · ' . (int) $leader['points'] . ' điểm';
        }
        $this->telegram->sendMessage(
            $chatId,
            '⚔️ <b>HỆ THỐNG GHÉP ĐÔI XẾP HẠNG</b>' .
            "\nElo của bạn: <b>" . (int) $stats['rating'] . '</b> · Điểm: <b>' . (int) $stats['points'] . '</b>' .
            "\nTrận: " . (int) $stats['matches_played'] . ' · Thắng: ' . (int) $stats['wins'] . ' · Thua: ' . (int) $stats['losses'] . ' · Hòa: ' . (int) $stats['draws'] .
            "\n\n🏆 <b>TOP 5 HENDY</b>\n" . ($ranking !== [] ? implode("\n", $ranking) : 'Chưa có dữ liệu xếp hạng.') .
            "\n\nChế độ Ghép nhanh có tính Elo. Luyện tập không thay đổi Elo.",
            [
                'reply_markup' => $this->inlineMenu([
                    [['text' => '🤝 GHÉP NHANH XẾP HẠNG', 'callback_data' => 'member:duel-join:quick']],
                    [['text' => '🎯 LUYỆN TẬP', 'callback_data' => 'member:duel-join:practice']],
                    [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
                ]),
            ]
        );
    }

    private function joinMatch(string $chatId, string $userId, string $mode): void
    {
        if (!in_array($mode, ['quick', 'practice'], true)) {
            throw new RuntimeException('Chế độ ghép đôi không hợp lệ.');
        }

        $stats = MatchmakingService::ensureStats($userId);
        $rating = (int) $stats['rating'];
        $match = Database::transaction(function () use ($userId, $mode, $rating): ?array {
            Database::run('SELECT pg_advisory_xact_lock(hashtext(?))', ['hendy-matchmaking:' . $mode]);
            $active = Database::one(
                "SELECT * FROM matchmaking_matches WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?) ORDER BY created_at DESC LIMIT 1 FOR UPDATE",
                [$userId, $userId]
            );
            if ($active !== null) {
                return $active;
            }

            $waiting = Database::one("SELECT id FROM matchmaking_queue WHERE user_id = ? AND status = 'waiting' ORDER BY created_at DESC LIMIT 1 FOR UPDATE", [$userId]);
            if ($waiting !== null) {
                return null;
            }

            $partner = Database::one(
                "SELECT q.id, q.user_id FROM matchmaking_queue q " .
                "LEFT JOIN matchmaking_player_stats s ON s.user_id = q.user_id " .
                "WHERE q.status = 'waiting' AND q.mode = ? AND q.user_id <> ? " .
                "AND (? <> 'quick' OR ABS(COALESCE(s.rating, 1000) - ?) <= LEAST(600, 150 + (EXTRACT(EPOCH FROM (NOW() - q.created_at)) / 30 * 25))) " .
                "ORDER BY CASE WHEN ? = 'quick' THEN ABS(COALESCE(s.rating, 1000) - ?) ELSE 0 END ASC, q.created_at ASC " .
                "LIMIT 1 FOR UPDATE OF q",
                [$mode, $userId, $mode, $rating, $mode, $rating]
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

        if ($match === null) {
            $this->telegram->sendMessage($chatId, '⏳ Đã vào hàng chờ ghép đôi. Elo của bạn: <b>' . $rating . '</b>. Hệ thống ưu tiên đối thủ có Elo gần bạn.');
            return;
        }
        if ($match['status'] === 'disputed') {
            $this->telegram->sendMessage($chatId, '⚠️ Lượt ghép #' . self::escape((string) $match['id']) . ' đang tranh chấp và chờ quản trị viên xử lý.');
            return;
        }

        $opponentId = $match['player_one'] === $userId ? $match['player_two'] : $match['player_one'];
        $opponent = Database::one('SELECT name, username FROM users WHERE user_id = ?', [$opponentId]);
        $opponentName = ($opponent['name'] ?? '') ?: (($opponent['username'] ?? '') ?: 'Thành viên Hendy');
        $opponentRating = (int) MatchmakingService::ensureStats((string) $opponentId)['rating'];
        $this->telegram->sendMessage(
            $chatId,
            '🎮 Bạn đã được ghép với <b>' . self::escape($opponentName) . '</b>.' .
            "\nMã trận: <code>#" . self::escape((string) $match['id']) . '</code>' .
            "\nElo: <b>" . $rating . '</b> · Đối thủ: <b>' . $opponentRating . '</b>' .
            "\nKhi kết thúc, gửi tỷ số theo thứ tự Người chơi A – Người chơi B.",
            ['reply_markup' => $this->inlineMenu([[['text' => '🧾 GỬI TỶ SỐ', 'callback_data' => 'member:duel-finish:' . $match['id']]]])]
        );
        try {
            (new self($this->botKey))->telegram->sendMessage(
                $opponentId,
                '🎮 Bạn đã được ghép đôi. Mã trận #' . self::escape((string) $match['id']) . '. Hãy trao đổi và gửi tỷ số sau trận.'
            );
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo ghép đôi: ' . $error->getMessage());
        }
    }

    private function leaveMatch(string $chatId, string $userId): void
    {
        $updated = Database::run(
            "UPDATE matchmaking_queue SET status = 'cancelled', updated_at = NOW() WHERE user_id = ? AND status = 'waiting' RETURNING id",
            [$userId]
        );
        if ($updated->rowCount() === 0) {
            $active = Database::one("SELECT id FROM matchmaking_matches WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?) LIMIT 1", [$userId, $userId]);
            if ($active !== null) {
                throw new RuntimeException('Bạn đang trong trận hoặc có kết quả tranh chấp cần xử lý; không thể rời hàng chờ.');
            }
        }
        $this->telegram->sendMessage($chatId, 'Đã rời hàng chờ ghép đôi.');
    }

    private function requestMatchResult(string $chatId, string $userId, string $matchId): void
    {
        $match = Database::one(
            "SELECT id, player_one, player_two, mode FROM matchmaking_matches WHERE id = ? AND status = 'matched' AND (player_one = ? OR player_two = ?)",
            [$matchId, $userId, $userId]
        );
        if ($match === null) {
            throw new RuntimeException('Không tìm thấy trận đang hoạt động hoặc bạn không phải người chơi.');
        }

        $existing = Database::one('SELECT status, reported_by FROM matchmaking_results WHERE match_id = ?', [$matchId]);
        if ($existing !== null) {
            if ($existing['status'] === 'pending' && $existing['reported_by'] !== $userId) {
                $this->telegram->sendMessage($chatId, 'Đối thủ đã gửi tỷ số. Hãy mở mục GHÉP ĐÔI để xác nhận hoặc báo sai.');
            } elseif ($existing['status'] === 'pending') {
                $this->telegram->sendMessage($chatId, 'Bạn đã gửi tỷ số. Hệ thống đang chờ đối thủ xác nhận.');
            } else {
                $this->telegram->sendMessage($chatId, 'Kết quả trận này đã được xử lý hoặc đang tranh chấp.');
            }
            return;
        }

        $this->setPending($userId, 'match_score', ['matchId' => (string) $matchId]);
        $this->telegram->sendMessage(
            $chatId,
            '🧾 <b>GỬI KẾT QUẢ TRẬN #' . self::escape($matchId) . '</b>' .
            "\nNgười chơi A: <code>" . self::escape((string) $match['player_one']) . '</code>' .
            "\nNgười chơi B: <code>" . self::escape((string) $match['player_two']) . '</code>' .
            "\nNhập tỷ số theo đúng thứ tự A-B, ví dụ <code>2-1</code> hoặc <code>1:1</code>. Chỉ gửi kết quả trận đã hoàn tất. Đối thủ phải xác nhận thì Elo và điểm mới được cập nhật."
        );
    }

    private function confirmMatchResult(string $chatId, string $userId, string $matchId, bool $approved): void
    {
        $match = Database::one(
            'SELECT player_one, player_two FROM matchmaking_matches WHERE id = ? AND (player_one = ? OR player_two = ?)',
            [$matchId, $userId, $userId]
        );
        if ($match === null) {
            throw new RuntimeException('Không tìm thấy trận hoặc bạn không tham gia trận này.');
        }

        $result = MatchmakingService::confirm((int) $matchId, $userId, $approved);
        $otherId = $match['player_one'] === $userId ? $match['player_two'] : $match['player_one'];

        if ($result['status'] === 'disputed') {
            $message = '⚠️ Kết quả trận #' . self::escape($matchId) . ' đã được đánh dấu tranh chấp. Elo và điểm chưa thay đổi; quản trị viên sẽ kiểm tra.';
            $this->telegram->sendMessage($chatId, $message);
            try {
                (new self($this->botKey))->telegram->sendMessage($otherId, $message);
            } catch (Throwable) {
            }
            return;
        }

        $isPlayerOne = $match['player_one'] === $userId;
        $delta = $isPlayerOne ? (int) $result['ratingDeltaOne'] : (int) $result['ratingDeltaTwo'];
        $points = $isPlayerOne ? (int) $result['pointsAwardedOne'] : (int) $result['pointsAwardedTwo'];
        $rating = $isPlayerOne ? (int) $result['ratingOne'] : (int) $result['ratingTwo'];
        $message = '✅ <b>KẾT QUẢ ĐÃ XÁC NHẬN</b>' .
            "\nTrận #" . self::escape($matchId) .
            "\nElo của bạn: " . ($delta >= 0 ? '+' : '') . $delta . ' → <b>' . $rating . '</b>' .
            "\nĐiểm thưởng: <b>+" . $points . '</b>' .
            "\nĐiểm chỉ dùng cho bảng xếp hạng nội bộ, không quy đổi thành tiền.';
        $this->telegram->sendMessage($chatId, $message);
        try {
            (new self($this->botKey))->telegram->sendMessage($otherId, '✅ Kết quả trận #' . self::escape($matchId) . ' đã được hai bên xác nhận. Hãy mở GHÉP ĐÔI để xem Elo và điểm mới.');
        } catch (Throwable) {
        }
    }

    private function processMatchScore(string $chatId, string $userId, string $text, array $payload): bool
    {
        $matched = preg_match('~\\A\\s*([0-9]{1,3})\\s*[-:]\\s*([0-9]{1,3})\\s*\\z~', $text, $scores);
        if ($matched !== 1) {
            $this->telegram->sendMessage($chatId, 'Tỷ số không hợp lệ. Hãy nhập A-B, ví dụ 2-1.');
            return true;
        }

        $matchId = (string) ($payload['matchId'] ?? '');
        if (preg_match('~\\A[0-9]{1,18}\\z~', $matchId) !== 1) {
            $this->clearPending($userId);
            $this->telegram->sendMessage($chatId, 'Phiên gửi tỷ số không hợp lệ. Hãy mở lại chức năng GHÉP ĐÔI.');
            return true;
        }

        try {
            $submission = MatchmakingService::report((int) $matchId, $userId, (int) $scores[1], (int) $scores[2]);
            $this->clearPending($userId);
            $this->telegram->sendMessage($chatId, 'Đã gửi tỷ số trận #' . self::escape($matchId) . ': ' . (int) $scores[1] . '-' . (int) $scores[2] . '. Đang chờ đối thủ xác nhận.');

            $keyboard = [
                [['text' => 'XÁC NHẬN', 'callback_data' => 'member:duel-confirm:' . $matchId . ':yes']],
                [['text' => 'BÁO SAI', 'callback_data' => 'member:duel-confirm:' . $matchId . ':no']],
            ];
            try {
                (new self($this->botKey))->telegram->sendMessage(
                    (string) $submission['opponentId'],
                    'Đối thủ đã gửi tỷ số trận #' . self::escape($matchId) . ': ' . (int) $scores[1] . '-' . (int) $scores[2] . '. Hãy xác nhận hoặc báo sai.',
                    ['reply_markup' => ['inline_keyboard' => $keyboard]]
                );
            } catch (Throwable $error) {
                error_log('Không gửi được yêu cầu xác nhận tỷ số: ' . $error->getMessage());
            }
        } catch (Throwable $error) {
            $this->telegram->sendMessage($chatId, 'Chưa thể gửi tỷ số: ' . self::escape($error->getMessage()));
        }

        return true;
    }

    private function showDeposit(string $chatId, string $userId, int $amount): void
    {
        if ($amount < 10000 || $amount > 500000000) {
            throw new RuntimeException('Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
        }
        $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME']);
        $bank = $settings['VIETQR_BANK_ID'] ?: (string) Env::get('VIETQR_BANK_ID', '');
        $account = $settings['VIETQR_ACCOUNT_NO'] ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
        $holder = $settings['VIETQR_ACCOUNT_NAME'] ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
        if ($bank === '' || $account === '' || $holder === '') {
            throw new RuntimeException('Admin chưa cấu hình tài khoản VietQR.');
        }
        $path = rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png';
        $query = http_build_query(['amount' => $amount, 'addInfo' => 'NAP ' . $userId, 'accountName' => $holder]);
        $url = 'https://img.vietqr.io/image/' . $path . '?' . $query;

        $caption = implode("\n", [
            '💳 <b>THÔNG TIN NẠP TIỀN</b>',
            'Số tiền: <b>' . self::money($amount) . '</b>',
            'Ngân hàng: <b>' . self::escape($bank) . '</b>',
            'Số tài khoản: <code>' . self::escape($account) . '</code>',
            'Chủ tài khoản: <b>' . self::escape($holder) . '</b>',
            'Nội dung chuyển khoản: <code>NAP ' . $userId . '</code>',
            '',
            'Ví được cộng khi webhook ngân hàng hợp lệ xác nhận giao dịch.',
        ]);
        try {
            $this->telegram->sendPhoto($chatId, $url, $caption, [
                'reply_markup' => $this->inlineMenu([[['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']]]),
            ]);
        } catch (Throwable) {
            $this->telegram->sendMessage($chatId, $caption . "\n\nKhông tải được ảnh QR. Vui lòng dùng thông tin chuyển khoản ở trên.");
        }
    }

    private function processPending(string $chatId, string $userId, string $text, array $pending): bool
    {
        if (strtotime((string) $pending['expires_at']) < time()) {
            $this->clearPending($userId);
            return false;
        }

        $payload = self::jsonArray($pending['payload'] ?? []);
        switch ((string) $pending['input_type']) {
            case 'match_score':
                return $this->processMatchScore($chatId, $userId, $text, $payload);

            case 'wallet':
                if (!preg_match('/^\d{4,12}$/', $text) || (int) $text < 10000 || (int) $text > 500000000) {
                    $this->telegram->sendMessage($chatId, 'Số tiền hợp lệ từ 10.000 đến 500.000.000 VNĐ. Ví dụ: 50000.');
                    return true;
                }
                try {
                    $this->showDeposit($chatId, $userId, (int) $text);
                    $this->clearPending($userId);
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, 'Chưa thể tạo thông tin nạp tiền: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'giftcode':
                try {
                    $reward = $this->redeemGiftcode($userId, $text);
                    $this->telegram->sendMessage($chatId, '🎉 Kích hoạt Giftcode thành công. Ví được cộng <b>' . self::money($reward) . '</b>.');
                    $this->clearPending($userId);
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Giftcode không hợp lệ: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'support':
                $this->clearPending($userId);
                if (mb_strlen($text) < 5) {
                    $this->telegram->sendMessage($chatId, 'Nội dung cần ít nhất 5 ký tự. Hãy chọn LIÊN HỆ CSKH để gửi lại.');
                    return true;
                }
                $ticket = Database::one(
                    "INSERT INTO support_tickets (user_id, subject, message) VALUES (?, 'Yêu cầu hỗ trợ Telegram', ?) RETURNING id",
                    [$userId, mb_substr($text, 0, 3000)]
                );
                if ($this->adminId !== '') {
                    try {
                        $this->telegram->sendMessage($this->adminId, '🆕 YÊU CẦU CSKH #' . $ticket['id'] . "\nTelegram ID: " . $userId . "\nNội dung:\n" . self::escape(mb_substr($text, 0, 2500)));
                    } catch (Throwable $error) {
                        error_log('Không gửi được thông báo CSKH: ' . $error->getMessage());
                    }
                }
                $this->telegram->sendMessage($chatId, '✅ Đã tạo yêu cầu hỗ trợ #' . $ticket['id'] . '. CSKH sẽ phản hồi qua Telegram.');
                return true;

            case 'link_username':
                $brand = strtoupper((string) ($payload['brand'] ?? ''));
                if (!in_array($brand, self::BRANDS, true) || mb_strlen($text) < 3 || mb_strlen($text) > 64 || preg_match('/[\x00-\x1F]/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Tên đăng nhập không hợp lệ. Không gửi mật khẩu hoặc OTP.');
                    return true;
                }
                Database::run(
                    'INSERT INTO linked_accounts (user_id, brand, username) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$userId, $brand, $text]
                );
                $this->clearPending($userId);
                $this->telegram->sendMessage($chatId, '🎉 Đã liên kết ' . self::escape($brand) . ' · <code>' . self::escape($text) . '</code>.');
                return true;
        }
        return false;
    }

    private function redeemGiftcode(string $userId, string $rawCode): int
    {
        $code = strtoupper(trim($rawCode));
        if (!preg_match('/^[A-Z0-9_-]{4,64}$/', $code)) {
            throw new RuntimeException('Mã Giftcode không đúng định dạng.');
        }

        return Database::transaction(function () use ($userId, $code): int {
            $gift = Database::one('SELECT * FROM giftcodes WHERE code = ? FOR UPDATE', [$code]);
            if ($gift === null) {
                throw new RuntimeException('Giftcode không tồn tại.');
            }
            if (!$this->toBool($gift['is_active']) || ($gift['expires_at'] && strtotime($gift['expires_at']) < time())) {
                throw new RuntimeException('Giftcode đã hết hạn hoặc bị khóa.');
            }
            if ((int) $gift['redeemed_count'] >= (int) $gift['max_redemptions']) {
                throw new RuntimeException('Giftcode đã hết lượt.');
            }

            $insert = Database::run(
                'INSERT INTO giftcode_redemptions (code, user_id, reward_amount) VALUES (?, ?, ?) ON CONFLICT DO NOTHING RETURNING code',
                [$code, $userId, $gift['reward_amount']]
            );
            if ($insert->rowCount() === 0) {
                throw new RuntimeException('Bạn đã sử dụng Giftcode này rồi.');
            }

            $reward = (int) $gift['reward_amount'];
            if ($reward > 0) {
                Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$reward, $userId]);
                Database::run(
                    "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'GIFTCODE', ?)",
                    ['giftcode:' . $code . ':' . $userId, $userId, $reward, 'Nhận thưởng Giftcode ' . $code]
                );
                Database::run(
                    "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'GIFTCODE', ?, 'paid')",
                    [$userId, $reward, $code]
                );
            }
            Database::run('UPDATE giftcodes SET redeemed_count = redeemed_count + 1 WHERE code = ?', [$code]);
            return $reward;
        });
    }

    private function addMoney(string $chatId, string $senderId, string $targetId, int $amount): void
    {
        if ($this->adminId === '' || $senderId !== $this->adminId) {
            $this->telegram->sendMessage($chatId, 'Bạn không có quyền sử dụng lệnh này.');
            return;
        }
        if ($amount <= 0 || $amount > 9000000000000000) {
            $this->telegram->sendMessage($chatId, 'Số tiền cộng không hợp lệ.');
            return;
        }
        Database::transaction(function () use ($targetId, $amount): void {
            Database::run('INSERT INTO users (user_id) VALUES (?) ON CONFLICT DO NOTHING', [$targetId]);
            Database::run('UPDATE users SET balance = balance + ? WHERE user_id = ?', [$amount, $targetId]);
            $reference = 'botadmin:' . bin2hex(random_bytes(12));
            Database::run(
                "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) VALUES (?, ?, ?, 'ADMIN_CREDIT', ?)",
                [$reference, $targetId, $amount, 'Cộng tiền qua lệnh Telegram /addmoney']
            );
            Database::run(
                "INSERT INTO transactions (user_id, amount, type, reference_code, status) VALUES (?, ?, 'ADMIN_CREDIT', ?, 'paid')",
                [$targetId, $amount, $reference]
            );
        });
        $this->telegram->sendMessage($chatId, 'Đã cộng ' . self::money($amount) . ' cho tài khoản ' . $targetId . '.');
        try {
            $this->telegram->sendMessage($targetId, 'Quản trị viên đã cộng ' . self::money($amount) . ' vào số dư tài khoản của bạn.');
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo cộng tiền: ' . $error->getMessage());
        }
    }

    private function openAdmin(string $chatId, string $userId): void
    {
        if ($this->adminId === '' || $userId !== $this->adminId) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản trị.');
            return;
        }
        $url = $this->webAppUrl('admin');
        if ($url === '') {
            $this->telegram->sendMessage($chatId, 'Hãy cấu hình APP_URL trong môi trường chạy để mở Dashboard.');
            return;
        }
        $this->telegram->sendMessage($chatId, '🛡️ <b>BẢNG ĐIỀU KHIỂN ADMIN</b>', [
            'reply_markup' => $this->inlineMenu([[['text' => 'MỞ BẢNG QUẢN TRỊ', 'web_app' => ['url' => $url]]]]),
        ]);
    }

    private function webAppUrl(string $section): string
    {
        $base = rtrim((string) (Env::get('WEBAPP_URL') ?: Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        if (!str_starts_with($base, 'https://')) {
            return '';
        }
        return $base . '/?section=' . rawurlencode($section) . '&bot=' . rawurlencode($this->botKey);
    }

    private function setPending(string $userId, string $type, array $payload = []): void
    {
        Database::run(
            'INSERT INTO bot_pending_inputs (user_id, bot_key, input_type, payload, expires_at) VALUES (?, ?, ?, ?::jsonb, NOW() + INTERVAL \'' . self::TTL_MINUTES . ' minutes\') ' .
            'ON CONFLICT (user_id, bot_key) DO UPDATE SET input_type = EXCLUDED.input_type, payload = EXCLUDED.payload, expires_at = EXCLUDED.expires_at',
            [$userId, $this->botKey, $type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private function getPending(string $userId): ?array
    {
        $pending = Database::one('SELECT input_type, payload, expires_at FROM bot_pending_inputs WHERE user_id = ? AND bot_key = ?', [$userId, $this->botKey]);
        if ($pending === null) {
            return null;
        }
        if (strtotime((string) $pending['expires_at']) < time()) {
            $this->clearPending($userId);
            return null;
        }
        return $pending;
    }

    private function clearPending(string $userId): void
    {
        Database::run('DELETE FROM bot_pending_inputs WHERE user_id = ? AND bot_key = ?', [$userId, $this->botKey]);
    }

    private function getUser(string $userId, string $name = '', string $username = ''): array
    {
        return Database::one(
            "INSERT INTO users (user_id, name, username) VALUES (?, ?, ?) ON CONFLICT (user_id) DO UPDATE SET " .
            "name = CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE users.name END, " .
            "username = CASE WHEN EXCLUDED.username <> '' THEN EXCLUDED.username ELSE users.username END " .
            'RETURNING user_id, name, username, balance, vip_level, total_deposited',
            [$userId, $name, $username]
        ) ?? [];
    }

    private function settings(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = Database::all("SELECT key, value FROM app_settings WHERE key IN ($placeholders)", $keys);
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' VNĐ';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Chờ xử lý',
            'processing' => 'Đang xử lý',
            'completed' => 'Hoàn tất',
            'rejected' => 'Từ chối',
            'cancelled' => 'Đã hủy',
            default => $status,
        };
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
