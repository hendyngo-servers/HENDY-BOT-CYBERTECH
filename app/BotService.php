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
        ['action' => 'wallet', 'label' => '💳 NẠP TIỀN', 'description' => 'Tạo yêu cầu chuyển khoản và theo dõi trạng thái duyệt.', 'style' => 'success'],
        ['action' => 'vietsub', 'label' => '🎬 DỊCH VỤ VIETSUB', 'description' => 'Gửi link video hợp lệ để tạo công việc phụ đề.', 'style' => 'primary'],
        ['action' => 'history', 'label' => '📜 LỊCH SỬ HOẠT ĐỘNG', 'description' => 'Xem giao dịch ví, yêu cầu nạp và video jobs.', 'style' => 'primary'],
        ['action' => 'jobs', 'label' => '📂 VIDEO JOBS', 'description' => 'Theo dõi tiến độ và kết quả yêu cầu Vietsub.', 'style' => 'primary'],
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

    public function __construct(string $botKey)
    {
        $this->botKey = $botKey === 'secondary' ? 'secondary' : 'primary';
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

        try {
            if (isset($update['message']) && is_array($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
            }
        } catch (Throwable $error) {
            error_log('[telegram:' . $this->botKey . '] ' . $error->getMessage());
            $chatId = (string) (
                $update['message']['chat']['id']
                ?? $update['callback_query']['message']['chat']['id']
                ?? $update['callback_query']['from']['id']
                ?? ''
            );
            if ($chatId !== '') {
                try {
                    $this->telegram->sendMessage($chatId, '❌ Hệ thống chưa thể xử lý yêu cầu này. Vui lòng thử lại sau hoặc liên hệ CSKH.');
                } catch (Throwable $notifyError) {
                    error_log('[telegram:' . $this->botKey . '] Không gửi được thông báo lỗi: ' . $notifyError->getMessage());
                }
            }
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
            $this->showVipWelcome($chatId, $userId);
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
                '/balance — Kiểm tra số dư ví',
                '/deposit [số tiền] — Tạo yêu cầu nạp và mã VietQR',
                '/history — Xem lịch sử ví và video jobs',
                '/vietsub — Gửi link video để tạo job phụ đề',
                '/jobs — Xem trạng thái video jobs',
                '/orders — Xem đơn hàng',
                '/admin — Mở bảng quản trị (chỉ Admin)',
                '/admin_payments — Duyệt yêu cầu nạp (Admin)',
                '/admin_jobs — Xem video jobs (Admin)',
                '/help — Hướng dẫn sử dụng',
                '',
                'Bấm nút ở bàn phím phía dưới để mở chức năng.',
            ]));
            return;
        }

        if (preg_match('/^\/balance(?:@\w+)?$/u', $text)) {
            $this->showBalance($chatId, $userId);
            return;
        }

        if (preg_match('/^\/deposit(?:@\w+)?(?:\s+(\d+))?$/u', $text, $matches)) {
            $amount = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            if ($amount > 0) {
                $this->showDeposit($chatId, $userId, $amount);
            } else {
                $this->showWalletMenu($chatId, $userId);
            }
            return;
        }

        if (preg_match('/^\/vietsub(?:@\w+)?$/u', $text)) {
            $this->startVideoRequest($chatId, $userId);
            return;
        }

        if (preg_match('/^\/(?:history|jobs)(?:@\w+)?$/u', $text, $matches)) {
            if (str_starts_with($text, '/jobs')) {
                $this->showVideoJobs($chatId, $userId);
            } else {
                $this->showUserHistory($chatId, $userId);
            }
            return;
        }

        if (preg_match('/^\/admin_payments(?:@\w+)?$/u', $text)) {
            $this->showAdminPayments($chatId, $userId);
            return;
        }

        if (preg_match('/^\/admin_jobs(?:@\w+)?$/u', $text)) {
            $this->showAdminVideoJobs($chatId, $userId);
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
        $websiteUrl = (string) ($this->settings(['website_url'])['website_url'] ?? '');
        if ($websiteUrl !== '') {
            $parts = parse_url($websiteUrl);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
                $websiteUrl = '';
            }
        }
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
            ...($websiteUrl !== '' ? ['🌐 Website chính thức: <a href="' . self::escape($websiteUrl) . '">Mở trang web</a>'] : []),
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
                'style' => in_array($styles[$action] ?? '', ['primary', 'success', 'danger'], true) ? $styles[$action] : ($item['style'] ?? 'primary'),
            ];
        }

        if (TelegramAuth::isAdmin($userId)) {
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
                    // Telegram Bot API buttons do not support arbitrary color/style fields.
                    $button = ['text' => $item['label']];
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
                // ReplyKeyboardButton has no color property in the Telegram Bot API.
                $button = ['text' => $item['label']];
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
        if (preg_match('/^deposit:(\d{4,12})$/', $action, $matches)) {
            $this->showDeposit($chatId, $userId, (int) $matches[1]);
            return;
        }
        if (preg_match('/^payment-approve:([A-Z0-9]{6,24})$/', $action, $matches)) {
            $this->approvePaymentRequest($chatId, $userId, $matches[1]);
            return;
        }
        if (preg_match('/^payment-reject:([A-Z0-9]{6,24})$/', $action, $matches)) {
            $this->rejectPaymentRequest($chatId, $userId, $matches[1]);
            return;
        }
        if (preg_match('/^video-retry:([0-9a-f-]{36})$/i', $action, $matches)) {
            $this->retryVideoJob($chatId, $userId, $matches[1]);
            return;
        }

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

        if (str_starts_with($action, 'duel-finish:') || str_starts_with($action, 'duel-join') || $action === 'duel-leave') {
            $this->showMatchmaking($chatId, $userId);
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
                $this->showWalletMenu($chatId, $userId);
                return;
            case 'deposit:custom':
                $this->setPending($userId, 'wallet');
                $this->telegram->sendMessage($chatId, '✏️ Nhập số tiền cần nạp từ 10.000 đến 500.000.000 VNĐ. Ví dụ: <code>50000</code>.');
                return;
            case 'vietsub':
                $this->startVideoRequest($chatId, $userId);
                return;
            case 'history':
                $this->showUserHistory($chatId, $userId);
                return;
            case 'jobs':
                $this->showVideoJobs($chatId, $userId);
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
            case 'vip-checkin':
                $this->handleVipCheckin($chatId, $userId);
                return;
            case 'vip-info':
                $this->showVipInfo($chatId, $userId);
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
            case 'admin-payments':
                $this->showAdminPayments($chatId, $userId);
                return;
            case 'admin-jobs':
                $this->showAdminVideoJobs($chatId, $userId);
                return;
            case 'admin-stats':
                $this->showAdminStats($chatId, $userId);
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
        $stats = Database::one(
            "SELECT COUNT(*) AS total_orders,
                    COUNT(*) FILTER (WHERE status = 'processing') AS processing_orders,
                    COUNT(*) FILTER (WHERE status = 'completed') AS completed_orders,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders
             FROM orders WHERE user_id = ?",
            [$userId]
        ) ?? [];
        $tickets = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
        $voucherUrl = $this->webAppUrl('vouchers');
        $voucherButton = $voucherUrl !== ''
            ? ['text' => '🎟️ VOUCHER 72H', 'web_app' => ['url' => $voucherUrl]]
            : ['text' => '🎟️ VOUCHER 72H', 'callback_data' => 'member:customer'];
        $this->telegram->sendMessage($chatId, implode("\n", [
            '💎 <b>TRUNG TÂM KHÁCH HÀNG</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '💰 Ví chính: <b>' . self::money($user['balance'] ?? 0) . '</b>',
            '🪙 Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>',
            '🎟 Ví Voucher: <b>' . self::money($user['voucher_wallet'] ?? 0) . '</b>',
            '💳 Tổng nạp: <b>' . self::money($user['total_deposited'] ?? 0) . '</b>',
            '👑 Cấp thành viên: <b>VIP ' . (int) ($user['vip_level'] ?? 0) . '</b>',
            '⭐ Điểm cũ: <b>' . (int) ($user['point_old'] ?? 0) . '</b>',
            '🔗 Tài khoản liên kết: <b>' . $accounts . '</b>',
            '🧾 Đơn hàng: <b>' . (int) ($stats['total_orders'] ?? 0) . '</b> · Đang xử lý: <b>' . (int) ($stats['processing_orders'] ?? 0) . '</b>',
            '✅ Hoàn tất: <b>' . (int) ($stats['completed_orders'] ?? 0) . '</b> · Đã hủy: <b>' . (int) ($stats['cancelled_orders'] ?? 0) . '</b>',
            '🎧 Yêu cầu CSKH: <b>' . $tickets . '</b>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🚀 TÀI KHOẢN MINI GAME', 'callback_data' => 'member:live']],
                [['text' => '🧾 ĐƠN HÀNG', 'callback_data' => 'member:orders']],
                [$voucherButton],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showBalance(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $this->telegram->sendMessage($chatId, '💰 <b>SỐ DƯ VÍ HENDY</b>' . "\n" .
            'Số dư hiện tại: <b>' . self::money($user['balance'] ?? 0) . '</b>', [
            'reply_markup' => $this->inlineMenu([
                [['text' => '＋ NẠP TIỀN', 'callback_data' => 'member:wallet']],
                [['text' => '📜 LỊCH SỬ', 'callback_data' => 'member:history']],
            ]),
        ]);
    }

    private function showWalletMenu(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $this->telegram->sendMessage($chatId, '💳 <b>NẠP TIỀN VÀO VÍ</b>' . "\n" .
            'Số dư hiện tại: <b>' . self::money($user['balance'] ?? 0) . '</b>' . "\n" .
            'Chọn nhanh số tiền hoặc nhập số tiền khác. Sau khi chuyển khoản, Admin sẽ kiểm tra sao kê và duyệt thủ công.', [
            'reply_markup' => $this->inlineMenu([
                [
                    ['text' => '10.000đ', 'callback_data' => 'member:deposit:10000'],
                    ['text' => '20.000đ', 'callback_data' => 'member:deposit:20000'],
                ],
                [
                    ['text' => '50.000đ', 'callback_data' => 'member:deposit:50000'],
                    ['text' => '100.000đ', 'callback_data' => 'member:deposit:100000'],
                ],
                [['text' => '✏️ NHẬP SỐ TIỀN KHÁC', 'callback_data' => 'member:deposit:custom']],
                [['text' => '📜 LỊCH SỬ NẠP', 'callback_data' => 'member:deposit-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function startVideoRequest(string $chatId, string $userId): void
    {
        $this->setPending($userId, 'video_url');
        $this->telegram->sendMessage($chatId,
            '🎬 <b>YÊU CẦU VIETSUB</b>' . "\n" .
            'Gửi link video HTTPS từ nền tảng đã được cho phép (mặc định: YouTube, TikTok, Facebook).' . "\n" .
            'Hệ thống sẽ tạo job và đưa vào hàng chờ. Việc xử lý thực tế cần cấu hình VIDEO_PROCESSOR_URL và VIDEO_PROCESSOR_SECRET.', [
            'reply_markup' => $this->inlineMenu([[['text' => 'HỦY / MENU CHÍNH', 'callback_data' => 'member:home']]]),
        ]);
    }

    private function showUserHistory(string $chatId, string $userId): void
    {
        $ledger = Database::all(
            'SELECT amount, transaction_type, reference_code, description, created_at FROM wallet_ledger WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
            [$userId]
        );
        $payments = PaymentService::forUser($userId, 5);
        $jobs = VideoJobService::forUser($userId, 5);

        $lines = ['📜 <b>LỊCH SỬ HOẠT ĐỘNG</b>', '━━━━━━━━━━━━━━━━━━━━', '<b>💰 Giao dịch ví</b>'];
        if ($ledger === []) {
            $lines[] = '• Chưa có biến động số dư.';
        } else {
            foreach ($ledger as $item) {
                $sign = (int) $item['amount'] >= 0 ? '+' : '';
                $lines[] = '• ' . $sign . self::money($item['amount']) . ' · ' . self::escape((string) $item['transaction_type']);
            }
        }

        $lines[] = '';
        $lines[] = '<b>💳 Yêu cầu nạp gần đây</b>';
        if ($payments === []) {
            $lines[] = '• Chưa tạo yêu cầu nạp.';
        } else {
            foreach ($payments as $payment) {
                $lines[] = '• <code>' . self::escape((string) $payment['payment_id']) . '</code> · ' .
                    self::money($payment['amount']) . ' · ' . self::escape(self::statusLabel((string) $payment['status']));
            }
        }

        $lines[] = '';
        $lines[] = '<b>🎬 Video jobs gần đây</b>';
        if ($jobs === []) {
            $lines[] = '• Chưa có yêu cầu Vietsub.';
        } else {
            foreach ($jobs as $job) {
                $lines[] = '• <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> · ' .
                    self::escape(self::statusLabel((string) $job['status'])) . ' (' . (int) $job['progress'] . '%)';
            }
        }

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎬 VIDEO JOBS', 'callback_data' => 'member:jobs']],
                [['text' => '💳 LỊCH SỬ NẠP', 'callback_data' => 'member:deposit-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showVideoJobs(string $chatId, string $userId): void
    {
        $jobs = VideoJobService::forUser($userId, 10);
        $lines = ['🎬 <b>VIDEO JOBS CỦA BẠN</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($jobs as $job) {
            $lines[] = '• <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> · ' .
                self::escape(self::statusLabel((string) $job['status'])) . ' · ' . (int) $job['progress'] . '%';
            $lines[] = '  Nguồn: ' . self::escape(self::truncate((string) $job['source_url'], 100));
            if ($job['status'] === 'completed' && $job['output_url'] !== '') {
                $lines[] = '  Kết quả: ' . self::escape((string) $job['output_url']);
            }
            if ($job['status'] === 'failed' && $job['error_message'] !== '') {
                $lines[] = '  Lỗi: ' . self::escape(self::truncate((string) $job['error_message'], 160));
            }
        }
        if ($jobs === []) {
            $lines[] = 'Chưa có video job nào. Bấm DỊCH VỤ VIETSUB để gửi link video.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎬 TẠO YÊU CẦU VIETSUB', 'callback_data' => 'member:vietsub']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function notifyUserOnBot(string $botKey, string $userId, string $message): void
    {
        $token = TelegramAuth::token($botKey);
        if ($token === '') {
            return;
        }
        try {
            (new TelegramApi($token))->sendMessage($userId, $message);
        } catch (Throwable $error) {
            error_log('Không gửi được thông báo user qua bot ' . $botKey . ': ' . $error->getMessage());
        }
    }

    private function notifyAdmins(string $message, array $replyMarkup = []): void
    {
        $botOrder = [$this->botKey, $this->botKey === 'primary' ? 'secondary' : 'primary'];
        $options = $replyMarkup !== [] ? ['reply_markup' => $replyMarkup] : [];

        foreach (TelegramAuth::adminIds() as $adminId) {
            $sent = false;
            foreach ($botOrder as $botKey) {
                $token = TelegramAuth::token($botKey);
                if ($token === '') {
                    continue;
                }
                try {
                    (new TelegramApi($token))->sendMessage($adminId, $message, $options);
                    $sent = true;
                    break;
                } catch (Throwable $error) {
                    // An admin may have started only the other bot. Try it once
                    // before leaving the notification for the admin dashboard.
                    error_log('Không gửi được thông báo admin qua ' . $botKey . ': ' . $error->getMessage());
                }
            }
            if (!$sent) {
                error_log('Không gửi được thông báo admin ' . $adminId . ' qua bất kỳ bot Telegram nào.');
            }
        }
    }

    private function showAdminStats(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản trị.');
            return;
        }
        $payments = PaymentService::stats();
        $jobs = VideoJobService::stats();
        $users = (int) Database::value('SELECT COUNT(*) FROM users');
        $orders = (int) Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')");
        $tickets = (int) Database::value("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','processing')");
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🛡️ <b>HENDY BOT — TỔNG QUAN QUẢN TRỊ</b>',
            '👥 Thành viên: <b>' . $users . '</b>',
            '🧾 Đơn hàng cần xử lý: <b>' . $orders . '</b>',
            '🎧 Ticket CSKH mở: <b>' . $tickets . '</b>',
            '💳 Yêu cầu nạp chờ duyệt: <b>' . $payments['pending'] . '</b>',
            '🎬 Video jobs: <b>' . $jobs['queued'] . '</b> chờ · <b>' . $jobs['processing'] . '</b> xử lý · <b>' . $jobs['failed'] . '</b> lỗi',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '💳 DUYỆT NẠP TIỀN', 'callback_data' => 'member:admin-payments']],
                [['text' => '🎬 VIDEO JOBS', 'callback_data' => 'member:admin-jobs']],
                ...(($url = $this->webAppUrl('admin')) !== '' ? [[['text' => '🖥️ MỞ DASHBOARD ADMIN', 'web_app' => ['url' => $url]]]] : []),
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showAdminPayments(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền duyệt nạp tiền.');
            return;
        }
        $pending = PaymentService::pending(15);
        if ($pending === []) {
            $this->telegram->sendMessage($chatId, '✅ Không có yêu cầu nạp tiền đang chờ.', [
                'reply_markup' => $this->inlineMenu([[['text' => '🔙 QUẢN TRỊ', 'callback_data' => 'member:admin-stats']]]),
            ]);
            return;
        }
        $this->telegram->sendMessage($chatId, '💳 <b>CÓ ' . count($pending) . ' YÊU CẦU NẠP CHỜ DUYỆT</b>' . "\n" .
            'Chỉ bấm Duyệt sau khi đã đối soát giao dịch trên sao kê ngân hàng.');
        foreach ($pending as $payment) {
            $name = trim((string) ($payment['name'] ?? '')) ?: (string) ($payment['username'] ?? 'Thành viên');
            $text = implode("\n", [
                '💳 <b>YÊU CẦU ' . self::escape((string) $payment['payment_id']) . '</b>',
                'Thành viên: <b>' . self::escape($name) . '</b>',
                'Bot nguồn: <b>' . self::escape(strtoupper((string) ($payment['bot_key'] ?? 'primary'))) . '</b>',
                'Telegram ID: <code>' . self::escape((string) $payment['user_id']) . '</code>',
                'Số tiền: <b>' . self::money($payment['amount']) . '</b>',
                'Nội dung chuyển khoản: <code>' . self::escape((string) $payment['transfer_content']) . '</code>',
                'Tài khoản nhận: <b>' . self::escape((string) $payment['bank_id']) . '</b> · <code>' . self::escape((string) $payment['account_number']) . '</code>',
                'Chủ tài khoản: ' . self::escape((string) $payment['account_holder']),
                'Tạo lúc: ' . self::escape((string) $payment['created_at']),
                '',
                '⚠️ Đây mới là yêu cầu của người dùng, không phải bằng chứng ngân hàng đã nhận tiền.',
            ]);
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => $this->inlineMenu([[
                    ['text' => '✅ ĐÃ ĐỐI SOÁT — DUYỆT', 'callback_data' => 'member:payment-approve:' . $payment['payment_id']],
                    ['text' => '❌ TỪ CHỐI', 'callback_data' => 'member:payment-reject:' . $payment['payment_id']],
                ]]),
            ]);
        }
    }

    private function approvePaymentRequest(string $chatId, string $adminId, string $paymentId): void
    {
        if (!TelegramAuth::isAdmin($adminId)) {
            throw new RuntimeException('Bạn không có quyền duyệt yêu cầu nạp.');
        }
        $result = PaymentService::approve($paymentId, $adminId);
        $this->telegram->sendMessage($chatId, '✅ Đã duyệt yêu cầu <code>' . self::escape($paymentId) . '</code>. Cộng ' .
            self::money($result['amountVnd']) . '. Số dư mới: <b>' . self::money($result['newBalance']) . '</b>.');
        $this->notifyUserOnBot(
            (string) ($result['botKey'] ?? $this->botKey),
            (string) $result['telegramId'],
            '🎉 <b>NẠP TIỀN ĐÃ ĐƯỢC ADMIN DUYỆT</b>' . "\n" .
            'Mã: <code>' . self::escape($paymentId) . '</code>' . "\n" .
            'Số tiền: <b>+' . self::money($result['amountVnd']) . '</b>' . "\n" .
            'Số dư mới: <b>' . self::money($result['newBalance']) . '</b>'
        );
    }

    private function rejectPaymentRequest(string $chatId, string $adminId, string $paymentId): void
    {
        if (!TelegramAuth::isAdmin($adminId)) {
            throw new RuntimeException('Bạn không có quyền từ chối yêu cầu nạp.');
        }
        $result = PaymentService::reject($paymentId, $adminId, 'Admin từ chối sau khi đối soát.');
        $this->telegram->sendMessage($chatId, '❌ Đã từ chối yêu cầu <code>' . self::escape($paymentId) . '</code>. Ví không thay đổi.');
        $this->notifyUserOnBot(
            (string) ($result['botKey'] ?? $this->botKey),
            (string) $result['telegramId'],
            '❌ Yêu cầu nạp <code>' . self::escape($paymentId) . '</code> chưa được duyệt. Vui lòng kiểm tra nội dung chuyển khoản hoặc liên hệ CSKH.'
        );
    }

    private function showAdminVideoJobs(string $chatId, string $userId): void
    {
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản lý video jobs.');
            return;
        }
        $stats = VideoJobService::stats();
        $jobs = VideoJobService::recent(15);
        $this->telegram->sendMessage($chatId, '🎬 <b>VIDEO JOBS</b>' . "\n" .
            'Chờ: ' . $stats['queued'] . ' · Đang xử lý: ' . $stats['processing'] . ' · Hoàn tất: ' . $stats['completed'] . ' · Lỗi: ' . $stats['failed'] . "\n" .
            'Worker chỉ dispatch khi cấu hình VIDEO_PROCESSOR_URL và VIDEO_PROCESSOR_SECRET.');
        foreach ($jobs as $job) {
            $name = trim((string) ($job['name'] ?? '')) ?: (string) ($job['username'] ?? '');
            $lines = [
                '🎬 <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> · <b>' . self::escape(self::statusLabel((string) $job['status'])) . '</b>',
                'User: <code>' . self::escape((string) $job['user_id']) . '</code> ' . self::escape($name),
                'Tiến độ: ' . (int) $job['progress'] . '%',
                'Nguồn: ' . self::escape(self::truncate((string) $job['source_url'], 150)),
            ];
            if ($job['error_message'] !== '') {
                $lines[] = 'Lỗi: ' . self::escape(self::truncate((string) $job['error_message'], 180));
            }
            $buttons = [];
            if ($job['status'] === 'failed') {
                $buttons[] = ['text' => '♻️ ĐƯA LẠI VÀO HÀNG CHỜ', 'callback_data' => 'member:video-retry:' . $job['id']];
            }
            $buttons[] = ['text' => '🔄 TẢI LẠI', 'callback_data' => 'member:admin-jobs'];
            $this->telegram->sendMessage($chatId, implode("\n", $lines), [
                'reply_markup' => $this->inlineMenu([$buttons]),
            ]);
        }
    }

    private function retryVideoJob(string $chatId, string $adminId, string $jobId): void
    {
        if (!TelegramAuth::isAdmin($adminId)) {
            throw new RuntimeException('Bạn không có quyền chạy lại video job.');
        }
        $job = VideoJobService::retry($jobId);
        $this->telegram->sendMessage($chatId, '♻️ Đã đưa job <code>' . self::escape(substr((string) $job['id'], 0, 8)) . '</code> về hàng chờ. Cần worker/provider đang hoạt động để xử lý.');
        $this->notifyUserOnBot(
            (string) ($job['bot_key'] ?? $this->botKey),
            (string) $job['user_id'],
            'Video job của bạn đã được đưa lại vào hàng chờ. Mã job: ' . substr((string) $job['id'], 0, 8) . '.'
        );
    }

    private function showDepositHistory(string $chatId, string $userId): void
    {
        $requests = PaymentService::forUser($userId, 10);
        $transactions = Database::all(
            "SELECT amount, reference_code, created_at FROM transactions WHERE user_id = ? AND type = 'DEPOSIT' ORDER BY created_at DESC LIMIT 10",
            [$userId]
        );
        $lines = ['💳 <b>LỊCH SỬ NẠP TIỀN</b>', '━━━━━━━━━━━━━━━━━━━━', '<b>Yêu cầu nạp</b>'];
        foreach ($requests as $request) {
            $lines[] = '• <code>' . self::escape((string) $request['payment_id']) . '</code> · ' .
                self::money($request['amount']) . ' · ' . self::escape(self::statusLabel((string) $request['status']));
            if ($request['status'] === 'pending') {
                $lines[] = '  Nội dung CK: <code>' . self::escape((string) $request['transfer_content']) . '</code>';
            }
        }
        if ($requests === []) {
            $lines[] = 'Chưa tạo yêu cầu nạp.';
        }
        $lines[] = '';
        $lines[] = '<b>Giao dịch đã được ghi có</b>';
        foreach ($transactions as $transaction) {
            $lines[] = '• +' . self::money($transaction['amount']) . ' · ' . self::escape((string) ($transaction['reference_code'] ?? '—'));
        }
        if ($transactions === []) {
            $lines[] = 'Chưa có giao dịch nạp được duyệt.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '＋ TẠO YÊU CẦU NẠP', 'callback_data' => 'member:wallet']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
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
        $this->showVipWelcome($chatId, $userId);
    }

    private function showVipWelcome(string $chatId, string $userId): void
    {
        $user = $this->getUser($userId);
        $status = VipService::status($userId);
        $next = $status['nextThreshold'] === null
            ? 'Bạn đang ở cấp VIP cao nhất.'
            : 'Cần nạp thêm <b>' . self::money($status['amountToNext']) . '</b> để đạt mốc tiếp theo (' . self::money($status['nextThreshold']) . ').';
        $checkinText = $status['checkedInToday'] ? '✅ Hôm nay bạn đã điểm danh.' : '🎁 Hôm nay bạn chưa điểm danh.';

        $this->telegram->sendMessage($chatId, implode("\\n", [
            '💎 <b>TRUNG TÂM VIP — TRI ÂN THÀNH VIÊN</b>',
            'Xin chào <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>!',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '━━━━━━━━━━━━━━━━━━━━',
            '👑 Hạng hiện tại: <b>VIP ' . $status['currentLevel'] . '</b>',
            '💳 Tổng nạp tích lũy: <b>' . self::money($status['totalDeposited']) . '</b>',
            '⭐ Điểm thành viên: <b>' . number_format($status['pointOld'], 0, ',', '.') . '</b>',
            $next,
            $checkinText,
            '🔥 Chuỗi điểm danh: <b>' . $status['streak'] . ' ngày</b>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => $status['checkedInToday'] ? '✅ ĐÃ ĐIỂM DANH HÔM NAY' : '🎁 ĐIỂM DANH VIP', 'callback_data' => 'member:vip-checkin']],
                [['text' => 'ℹ️ THÔNG TIN TẤT CẢ VIP', 'callback_data' => 'member:vip-info']],
                [['text' => '🔙 TRỞ VỀ', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showVipInfo(string $chatId, string $userId): void
    {
        $status = VipService::status($userId);
        $lines = [
            '👑 <b>THÔNG TIN TẤT CẢ CẤP VIP</b>',
            'Mốc xét hạng dựa trên tổng nạp tích lũy:',
            '',
        ];
        foreach (VipService::levels() as $level) {
            $current = (int) $level['level'] === $status['currentLevel'] ? '  ← HẠNG HIỆN TẠI' : '';
            $lines[] = '• <b>VIP ' . (int) $level['level'] . '</b> — ' . self::money($level['threshold']) . $current;
        }
        $lines[] = '';
        $lines[] = 'Điểm danh mỗi ngày nhận <b>' . $status['checkinPoints'] . ' điểm</b> vào điểm thành viên, không phải số dư tiền.';
        $lines[] = 'Điểm hiện có: <b>' . number_format($status['pointOld'], 0, ',', '.') . '</b>.';
        $lines[] = 'Quyền lợi chi tiết và gói nâng cấp được hiển thị theo các sản phẩm VIP do Admin cấu hình.';

        $this->telegram->sendMessage($chatId, implode("\\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎁 ĐIỂM DANH VIP', 'callback_data' => 'member:vip-checkin']],
                [['text' => '🔙 TRỞ VỀ TRUNG TÂM VIP', 'callback_data' => 'member:vip']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function handleVipCheckin(string $chatId, string $userId): void
    {
        $result = VipService::checkin($userId);
        $message = $result['alreadyCheckedIn']
            ? '✅ Bạn đã điểm danh hôm nay. Hệ thống không cộng điểm lần thứ hai.'
            : '🎉 Điểm danh thành công! +' . $result['pointsAwarded'] .
                ' điểm thành viên. Tổng điểm hiện có: ' . number_format($result['pointsTotal'], 0, ',', '.') .
                '. Chuỗi điểm danh: ' . $result['streak'] . ' ngày.';
        $this->telegram->sendMessage($chatId, $message);
        $this->showVipWelcome($chatId, $userId);
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
        // The Mini App owns the ranked queue, Elo, result confirmation and
        // dispute flow. Do not leave legacy FIFO callbacks able to finalize
        // a match without bilateral result confirmation.
        $url = $this->webAppUrl('matchmaking');
        if ($url === '') {
            $this->telegram->sendMessage($chatId, 'Ghép đôi xếp hạng cần WEBAPP_URL hoặc APP_URL HTTPS. Vui lòng liên hệ quản trị viên.');
            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            '⚔️ <b>HENDY MATCH — GHÉP ĐÔI XẾP HẠNG</b>' .
            "\nTìm đối thủ theo Elo, gửi tỷ số, xác nhận kết quả hai phía và theo dõi bảng xếp hạng." .
            "\nĐiểm thưởng là điểm nội bộ, không quy đổi thành tiền.",
            [
                'reply_markup' => $this->inlineMenu([
                    [['text' => '🚀 MỞ HỆ THỐNG GHÉP ĐÔI', 'web_app' => ['url' => $url]]],
                ]),
            ]
        );
    }

    private function showDeposit(string $chatId, string $userId, int $amount): void
    {
        if ($amount < 10000 || $amount > 500000000) {
            throw new RuntimeException('Số tiền nạp phải từ 10.000 đến 500.000.000 VNĐ.');
        }
        $settings = $this->settings(['VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME']);
        $bank = trim((string) ($settings['VIETQR_BANK_ID'] ?? '')) ?: (string) Env::get('VIETQR_BANK_ID', '');
        $account = trim((string) ($settings['VIETQR_ACCOUNT_NO'] ?? '')) ?: (string) Env::get('VIETQR_ACCOUNT_NO', '');
        $holder = trim((string) ($settings['VIETQR_ACCOUNT_NAME'] ?? '')) ?: (string) Env::get('VIETQR_ACCOUNT_NAME', '');
        if ($bank === '' || $account === '' || $holder === '') {
            throw new RuntimeException('Admin chưa cấu hình tài khoản VietQR.');
        }

        // Create a pending request with a unique transfer reference. It does
        // not credit the wallet; admins must verify the incoming bank transfer.
        $request = PaymentService::create($userId, $amount, $this->botKey, $bank, $account, $holder);
        $path = rawurlencode($bank) . '-' . rawurlencode($account) . '-compact2.png';
        $query = http_build_query([
            'amount' => $amount,
            'addInfo' => $request['transfer_content'],
            'accountName' => $holder,
        ]);
        $url = 'https://img.vietqr.io/image/' . $path . '?' . $query;

        $caption = implode("\n", [
            '💳 <b>YÊU CẦU NẠP ĐÃ ĐƯỢC TẠO</b>',
            'Mã yêu cầu: <code>' . self::escape((string) $request['payment_id']) . '</code>',
            'Số tiền: <b>' . self::money($amount) . '</b>',
            'Ngân hàng: <b>' . self::escape($bank) . '</b>',
            'Số tài khoản: <code>' . self::escape($account) . '</code>',
            'Chủ tài khoản: <b>' . self::escape($holder) . '</b>',
            'Nội dung chuyển khoản: <code>' . self::escape((string) $request['transfer_content']) . '</code>',
            '',
            '⏳ Trạng thái: <b>Chờ Admin đối soát</b>.',
            'Ví chỉ được cộng sau khi Admin kiểm tra sao kê và bấm Duyệt. Tạo yêu cầu không đồng nghĩa tiền đã được nhận.',
        ]);

        $markup = ['reply_markup' => $this->inlineMenu([
            [['text' => '📜 KIỂM TRA LỊCH SỬ NẠP', 'callback_data' => 'member:deposit-history']],
            [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
        ])];
        try {
            $this->telegram->sendPhoto($chatId, $url, $caption, $markup);
        } catch (Throwable $error) {
            $this->telegram->sendMessage($chatId, $caption . "\n\nKhông tải được ảnh QR. Vui lòng dùng đúng thông tin chuyển khoản phía trên.", $markup);
        }

        $this->notifyAdmins(
            '🔔 <b>YÊU CẦU NẠP MỚI</b>' . "\n" .
            'Mã: <code>' . self::escape((string) $request['payment_id']) . '</code> · ' .
            self::money($amount) . "\n" .
            'User: <code>' . self::escape($userId) . '</code>' . "\n" .
            'Nội dung CK: <code>' . self::escape((string) $request['transfer_content']) . '</code>' . "\n" .
            'Chỉ duyệt sau khi đối chiếu sao kê.',
            ['inline_keyboard' => [[
                ['text' => '✅ MỞ DANH SÁCH DUYỆT', 'callback_data' => 'member:admin-payments'],
            ]]]
        );
    }

    private function processPending(string $chatId, string $userId, string $text, array $pending): bool
    {
        if (strtotime((string) $pending['expires_at']) < time()) {
            $this->clearPending($userId);
            return false;
        }

        $payload = self::jsonArray($pending['payload'] ?? []);
        switch ((string) $pending['input_type']) {
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

            case 'video_url':
                try {
                    $job = VideoJobService::create($userId, $text, $this->botKey);
                    $this->clearPending($userId);
                    $shortId = substr((string) $job['id'], 0, 8);
                    $this->telegram->sendMessage($chatId, '✅ <b>ĐÃ TIẾP NHẬN VIDEO JOB</b>' . "\n" .
                        'Mã job: <code>' . self::escape($shortId) . '</code>' . "\n" .
                        'Trạng thái: <b>Chờ xử lý</b>.' . "\n" .
                        'Job đã vào hàng chờ PostgreSQL. Việc tạo phụ đề tự động cần worker và VIDEO_PROCESSOR_URL/VIDEO_PROCESSOR_SECRET được cấu hình.');
                    $this->notifyAdmins(
                        '🎬 <b>VIDEO JOB MỚI</b>' . "\n" .
                        'Job: <code>' . self::escape($shortId) . '</code>' . "\n" .
                        'User: <code>' . self::escape($userId) . '</code>' . "\n" .
                        'Nguồn: ' . self::escape(self::truncate($text, 180)),
                        ['inline_keyboard' => [[['text' => '🎬 XEM VIDEO JOBS', 'callback_data' => 'member:admin-jobs']]]]
                    );
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không thể tạo video job: ' . self::escape($error->getMessage()));
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
                $this->notifyAdmins('🆕 YÊU CẦU CSKH #' . $ticket['id'] . "\nTelegram ID: " . $userId . "\nNội dung:\n" . self::escape(mb_substr($text, 0, 2500)));
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
        if (!TelegramAuth::isAdmin($senderId)) {
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
        if (!TelegramAuth::isAdmin($userId)) {
            $this->telegram->sendMessage($chatId, '⛔ Bạn không có quyền quản trị.');
            return;
        }
        $this->showAdminStats($chatId, $userId);
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
            'RETURNING user_id, name, username, balance, vip_level, total_deposited, nyc_wallet, voucher_wallet, point_old',
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
