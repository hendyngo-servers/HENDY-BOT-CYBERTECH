<?php
declare(strict_types=1);

namespace Hendy;

use RuntimeException;
use Throwable;

final class BotService
{
    private const TTL_MINUTES = 10;
    private const BRANDS = ['QQ88', 'HI88', '78WIN', 'RR88', 'MM88', 'GG88', 'ABCVIP', 'XX8', 'U888', 'J88', 'ABC8', '88CLB', 'AD88', 'SC88', 'C168', 'CM88', 'F8BET'];

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
        ['action' => 'customer', 'label' => '🔐 QUẢN LÝ TÀI KHOẢN', 'description' => 'Chọn Mini App hoặc đăng nhập truyền thống có thời hạn 5 phút.', 'style' => 'primary'],
        ['action' => 'abcvip', 'label' => '🏦 KHO TÀI KHOẢN ABCVIP', 'description' => 'Đăng nhập qua cổng liên kết chính thức để kết nối tài khoản ABCVIP.', 'style' => 'success'],
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
        if (preg_match('/^\/(?:customer|cskh)(?:@\w+)?$/u', $text)) {
            $this->showCustomer($chatId, $userId);
            return;
        }
        if (preg_match('/^\/minigame(?:@\w+)?$/u', $text)) {
            $this->showLiveAccounts($chatId, $userId);
            return;
        }
        if (preg_match('/^\/otp(?:@\w+)?$/u', $text)) {
            $this->showOtpCenter($chatId, $userId);
            return;
        }
        if (preg_match('/^\/proxy(?:@\w+)?$/u', $text)) {
            $this->showProxyCenter($chatId, $userId);
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
            if (!preg_match('/^\d{5,20}$/', $userId)) {
                return;
            }
            if (str_starts_with($data, 'member:')) {
                $this->dispatchAction($chatId, $userId, substr($data, 7));
                return;
            }

            $legacyActions = [
                'back_home' => 'home',
                'menu_minigame' => 'live',
                'rr88_game' => 'minigame-products',
                'mm88_game' => 'minigame-products',
                'gg88_game' => 'minigame-products',
                'ad88_game' => 'minigame-products',
                'abcvip_kho' => 'minigame-products',
                'menu_tool' => 'tools',
                'menu_code' => 'codes',
                'menu_nap' => 'wallet',
                'menu_otp' => 'otp',
                'shop_otp' => 'otp-products',
                'shop_proxy' => 'proxy-products',
                'get_free_proxy' => 'proxy-products',
                'proxy_xoay' => 'proxy-products',
                'otp_abcvip' => 'otp-products',
                'otp_fb' => 'otp-products',
                'otp_tele' => 'otp-products',
                'otp_sc88' => 'otp-products',
                'menu_kho_cc' => 'viptools',
                'menu_cskh' => 'customer',
                'menu_ghep_doi' => 'duel',
                'ref' => 'ref',
                'vip' => 'vip',
                'giftcode' => 'gift',
                'cskh' => 'support',
                'tool_1' => 'tools',
                'tool_2' => 'tools',
                'tool_3' => 'tools',
                // Legacy tool_4 was labelled bulk SMS spam; this only opens the
                // normal tool catalogue and does not execute or send messages.
                'tool_4' => 'tools',
            ];
            if (!isset($legacyActions[$data])) {
                return;
            }
            $this->dispatchAction($chatId, $userId, $legacyActions[$data]);
        } catch (Throwable $error) {
            error_log('[telegram:' . $this->botKey . '] ' . $error->getMessage());
            $this->telegram->sendMessage($chatId, '❌ ' . self::escape($error->getMessage()));
        }
    }

    private function sendHome(string $chatId, array $from): void
    {
        $userId = (string) ($from['id'] ?? '');
        $firstName = trim((string) ($from['first_name'] ?? ''));
        $lastName = trim((string) ($from['last_name'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);
        $username = trim((string) ($from['username'] ?? ''));
        $user = $this->getUser($userId, $name, $username);
        $config = $this->menuConfig();
        $items = $this->menuItems($config, $userId);
        $settings = $this->settings(['website_url', 'GROUP_URL', 'CHANNEL_URL', 'bot_username', 'bot_display_name']);

        $websiteUrl = trim((string) ($settings['website_url'] ?? 'https://hendy-bot-tele.onrender.com'));
        if (!self::isHttpsUrl($websiteUrl)) {
            $websiteUrl = 'https://hendy-bot-tele.onrender.com';
        }

        $groupUrl = trim((string) ($settings['GROUP_URL'] ?? Env::get('GROUP_URL', 'https://t.me/Hendy_Support_Group')));
        if (!self::isHttpsUrl($groupUrl)) {
            $groupUrl = 'https://t.me/Hendy_Support_Group';
        }

        $botUsername = trim(ltrim((string) ($settings['bot_username'] ?? Env::get('BOT_USERNAME', 'tuanem2027_bot')), '@'));
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername)) {
            $botUsername = 'tuanem2027_bot';
        }
        $botUrl = 'https://t.me/' . rawurlencode($botUsername);
        $botDisplayName = trim(ltrim((string) ($settings['bot_display_name'] ?? 'HendyBot_Bot'), '@'));
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $botDisplayName)) {
            $botDisplayName = $botUsername;
        }

        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')))->format('G');
        $timeGreeting = match (true) {
            $hour >= 5 && $hour < 11 => 'buổi sáng',
            $hour >= 11 && $hour < 13 => 'buổi trưa',
            $hour >= 13 && $hour < 18 => 'buổi chiều',
            $hour >= 18 && $hour < 22 => 'buổi tối',
            default => 'buổi đêm',
        };

        $displayName = $firstName !== '' ? $firstName : ($name !== '' ? $name : ($username !== '' ? '@' . $username : 'bạn'));
        $welcomePhrases = [
            'Chọn một chức năng để bắt đầu trải nghiệm hệ sinh thái HENDY.',
            'Công nghệ kết nối giá trị thật — cảm ơn bạn đã đồng hành cùng HENDY.',
            'Chúc bạn có trải nghiệm nhanh chóng, thuận tiện và nhiều niềm vui.',
        ];
        $dynamicWelcome = $welcomePhrases[random_int(0, count($welcomePhrases) - 1)];
        $vipLevel = (int) ($user['vip_level'] ?? 0);
        $memberStatus = $vipLevel > 0 ? 'VIP ' . $vipLevel : 'Bình thường';
        $descriptions = array_map(
            static fn (array $item): string => '• ' . $item['label'] . ' — ' . $item['description'],
            $items
        );

        $text = implode("\n", [
            '💠 <b>⩤ BOT 💠 HENDY ⩥</b> 🤩',
            '<b>LETHITUANEM TEAM</b> · BOT SERVICE LIVESTREAM',
            '✨ <i>Công nghệ kết nối giá trị thật</i>',
            '━━━━━━━━━━━━━━━━━━━━',
            '👋 Chúc ' . $timeGreeting . ' vui vẻ, <b>' . self::escape($displayName) . '</b>!',
            '📱 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '━━━━━━━━━━━━━━━━━━━━',
            '🔢 <b>TỔNG NẠP: ' . self::money($user['total_deposited'] ?? 0) . '</b>',
            '😄 <b>Ví Chính:</b> ' . self::money($user['balance'] ?? 0),
            '😄 <b>Ví NYC:</b> ' . self::money($user['nyc_wallet'] ?? 0),
            '━━━━━━━━━━━━━━━━━━━━',
            '💠 <b>Trạng thái thành viên:</b> [' . self::escape($memberStatus) . ']',
            '━━━━━━━━━━━━━━━━━━━━',
            '🌟 <b>Website:</b> <a href="' . self::escape($websiteUrl) . '">Mở website Hendy</a>',
            '💥 <b>Nhóm:</b> <a href="' . self::escape($groupUrl) . '">HENDY · NHÓM PUBLIC</a>',
            '🤖 <b>Bot:</b> <a href="' . self::escape($botUrl) . '">@' . self::escape($botDisplayName) . '</a>',
            '',
            self::escape((string) ($config['intro_text'] ?? 'Chào mừng bạn đến với HENDY BOT.')) . ' ' . self::escape($dynamicWelcome) . ' 🚀',
            '',
            '<b>HỆ SINH THÁI DỊCH VỤ</b>',
            'Tool · Phần mềm · Dịch vụ công nghệ · Điểm thưởng game · Proxy · OTP · Livestream · Trí tuệ nhân tạo',
            '',
            '<b>HƯỚNG DẪN CHỨC NĂNG</b>',
            ...array_map(self::escape(...), $descriptions),
        ]);

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => $this->menuMarkup($items, $config),
        ]);
    }

    private static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
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
                    // Bot API 9.4 supports the predefined primary/success/danger styles.
                    $button = ['text' => $item['label'], 'style' => $item['style']];
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
                // Preserve each configured color in Reply Keyboard as well.
                $button = ['text' => $item['label'], 'style' => $item['style']];
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
        if (preg_match('/^duel-join-(quick|practice)$/', $action, $matches)) {
            $this->joinMatchmaking($chatId, $userId, $matches[1]);
            return;
        }
        if ($action === 'duel-leave') {
            try { MatchmakingService::leaveQueue($userId); }
            catch (Throwable $error) { $this->telegram->sendMessage($chatId, '⚠️ ' . self::escape($error->getMessage())); }
            $this->showMatchmaking($chatId, $userId);
            return;
        }
        if (preg_match('/^duel-report:(\\d{1,18})$/', $action, $matches)) {
            $this->startMatchReport($chatId, $userId, (int) $matches[1]);
            return;
        }
        if (preg_match('/^duel-confirm:(\\d{1,18}):(yes|no)$/', $action, $matches)) {
            $this->confirmMatchResult($chatId, $userId, (int) $matches[1], $matches[2] === 'yes');
            return;
        }

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
            if (!in_array($brand, self::BRANDS, true)) throw new RuntimeException('Thương hiệu không hợp lệ.');
            if ($brand === 'ABCVIP') {
                $this->showAbcvipConnect($chatId);
                return;
            }
            $this->setPending($userId, 'link_username', ['brand' => $brand]);
            $this->telegram->sendMessage($chatId, 'Bạn đã chọn <b>' . self::escape($brand) . '</b>. Hãy nhập username. Không gửi mật khẩu, PIN hoặc OTP.');
            return;
        }

        if (str_starts_with($action, 'duel-finish:')) {
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
                foreach (array_chunk(self::BRANDS, 3) as $chunk) {
                    $row = [];
                    foreach ($chunk as $brand) {
                        $row[] = ['text' => $brand, 'callback_data' => 'member:select-brand:' . $brand];
                    }
                    $rows[] = $row;
                }
                $this->telegram->sendMessage($chatId, '👉 <b>CHỌN HỆ THỐNG TÀI KHOẢN</b>' . "\nChỉ lưu username. ABCVIP sẽ mở cổng SSO chính thức nếu được cấu hình; không gửi mật khẩu, OTP hoặc mã xác minh.", [
                    'reply_markup' => $this->inlineMenu($rows),
                ]);
                return;
            case 'minigame-products':
                $this->showProducts($chatId, ['minigame'], '🎮 KHO SẢN PHẨM MINI GAME', $userId);
                return;
            case 'otp-products':
                $this->showProducts($chatId, ['otp'], '📱 KHO OTP SMS', $userId);
                return;
            case 'proxy-products':
            case 'proxy':
            case 'proxy-center':
                $this->showProxyCenter($chatId, $userId);
                return;
            case 'proxy-products':
                $this->showProducts($chatId, ['proxy'], '🌐 KHO PROXY', $userId);
                return;
            case 'proxy-free':
                $this->showFreeProxyProducts($chatId, $userId);
                return;
            case 'proxy-rentals':
                $this->showProxyRentals($chatId, $userId);
                return;
            case 'otp-history':
            case 'otp-rentals':
                $this->showOtpHistory($chatId, $userId);
                return;
            case 'tools':
                $this->showProducts($chatId, ['tools'], '🧰 THUÊ TOOL', $userId);
                return;
            case 'codes':
                $this->showProducts($chatId, ['codes'], '🎁 KHO MÃ CODE', $userId);
                return;
            case 'otp':
                $this->showOtpCenter($chatId, $userId);
                return;
            case 'nyc-transfer':
                $this->setPending($userId, 'nyc_transfer');
                $this->telegram->sendMessage($chatId, '🪙 Nhập số tiền chuyển từ Ví Chính sang Ví NYC (từ 1.000 đến 500.000.000 VNĐ). Đây là chuyển nội bộ, không phải nạp ngân hàng.');
                return;
            case 'otp-catalog':
                $this->showProducts($chatId, ['otp'], '📱 KHO DỊCH VỤ OTP', $userId);
                return;
            case 'viptools':
                $this->showProducts($chatId, ['vip'], '🏆 KHO VIP', $userId);
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
            case 'linked-accounts':
                $this->showLiveAccounts($chatId, $userId);
                return;
            case 'voucher72':
                $this->startVoucherRequest($chatId, $userId);
                return;
            case 'live-auto-reorder':
                $this->startLiveIssue($chatId, $userId, 'live_auto_reorder');
                return;
            case 'live-no-reward':
                $this->startLiveIssue($chatId, $userId, 'live_win_no_reward');
                return;
            case 'legacy-login':
                $this->sendLegacyLoginLink($chatId, $userId);
                return;
            case 'abcvip':
                $this->showAbcvipConnect($chatId);
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
            case 'support-abuse':
                $this->setPending($userId, 'support_abuse', ['stage' => 'order']);
                $this->telegram->sendMessage($chatId, '🆘 <b>HỖ TRỢ DÍNH LẠM DỤNG (60%)</b>' . "\n" . 'Vui lòng nhập mã đơn hàng đã hoàn tất để CSKH kiểm tra. Yêu cầu chỉ được ghi nhận sau khi đối chiếu đơn; không tự động hoàn tiền hoặc cộng voucher.');
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
        $accounts = Database::all('SELECT id, brand, username, created_at FROM linked_accounts WHERE user_id = ? ORDER BY created_at DESC LIMIT 30', [$userId]);
        $counts = Database::all('SELECT brand, COUNT(*) AS account_count FROM linked_accounts WHERE user_id = ? GROUP BY brand ORDER BY brand', [$userId]);
        $countByBrand = array_fill_keys(self::BRANDS, 0);
        foreach ($counts as $count) {
            $brand = strtoupper((string) $count['brand']);
            if (array_key_exists($brand, $countByBrand)) $countByBrand[$brand] = (int) $count['account_count'];
        }
        $lines = [
            '🚀 <b>MINIGAME LIVE · KHO TÀI KHOẢN</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Chọn thương hiệu cần sử dụng hoặc liên kết. HENDY chỉ lưu username gắn với Telegram ID; không lưu mật khẩu/OTP.',
            '',
            '<b>THỐNG KÊ TÀI KHOẢN ĐÃ LIÊN KẾT</b>',
        ];
        foreach (self::BRANDS as $brand) if (($countByBrand[$brand] ?? 0) > 0) $lines[] = '• ' . self::escape($brand) . ': <b>' . $countByBrand[$brand] . '</b>';
        $lines[] = 'Tổng tài khoản: <b>' . count($accounts) . '</b>';
        foreach ($accounts as $account) $lines[] = '• <b>' . self::escape($account['brand']) . '</b> — <code>' . self::escape($account['username']) . '</code>';
        if ($accounts === []) $lines[] = 'Bạn chưa liên kết tài khoản Mini Game nào.';

        $rows = [];
        foreach (array_chunk(self::BRANDS, 3) as $chunk) {
            $row = [];
            foreach ($chunk as $brand) $row[] = ['text' => $brand, 'callback_data' => 'member:select-brand:' . $brand];
            $rows[] = $row;
        }
        $rows[] = [
            ['text' => '⭐ AUTO ĐẶT LẠI', 'callback_data' => 'member:live-auto-reorder'],
            ['text' => '🔥 TRÚNG KHÔNG PHÁT', 'callback_data' => 'member:live-no-reward'],
        ];
        foreach (array_chunk($accounts, 2) as $chunk) {
            $row = [];
            foreach ($chunk as $account) $row[] = ['text' => 'Xóa ' . $account['brand'] . ' #' . $account['id'], 'callback_data' => 'member:link-delete:' . $account['id']];
            $rows[] = $row;
        }
        $rows[] = [['text' => '＋ LIÊN KẾT TÀI KHOẢN', 'callback_data' => 'member:link-new']];
        $rows[] = [['text' => '🏠 TRỞ VỀ MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function showProducts(string $chatId, array $categories, string $title, ?string $userId = null): void
    {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $memberVip = $userId !== null ? (int) (Database::value('SELECT vip_level FROM users WHERE user_id = ?', [$userId]) ?? 0) : 0;
        $products = Database::all(
            "SELECT id, name, price, description, stock, duration_days, vip_level FROM products " .
            "WHERE category IN ($placeholders) AND is_active = TRUE AND (stock IS NULL OR stock > 0) " .
            'ORDER BY created_at DESC LIMIT 30',
            $categories
        );
        $lines = [$title, '━━━━━━━━━━━━━━━━━━━━', 'Danh mục và giá được lấy từ kho sản phẩm PostgreSQL:'];
        $rows = [];
        foreach ($products as $product) {
            if ((int) ($product['vip_level'] ?? 0) > $memberVip) continue;
            $stock = $product['stock'] === null ? 'Còn hàng' : 'Còn ' . number_format((int) $product['stock'], 0, ',', '.') . ' sản phẩm';
            $rows[] = [['text' => self::truncate($product['name'] . ' · ' . self::money($product['price']) . ' · ' . $stock, 55), 'callback_data' => 'member:order:' . $product['id']]];
            $lines[] = '• <b>' . self::escape($product['name']) . '</b> — ' . self::money($product['price']);
            $lines[] = '  Kho: <b>' . self::escape($stock) . '</b>' .
                ((int) ($product['duration_days'] ?? 0) > 0 ? ' · Thời hạn: ' . (int) $product['duration_days'] . ' ngày' : '') .
                ((int) ($product['vip_level'] ?? 0) > 0 ? ' · Yêu cầu VIP ' . (int) $product['vip_level'] : '');
            if (trim((string) $product['description']) !== '') $lines[] = '  ' . self::escape(self::truncate((string) $product['description'], 140));
        }
        if ($rows === []) $lines[] = 'Hiện chưa có sản phẩm phù hợp hoặc tài khoản chưa đủ cấp VIP. Admin có thể quản lý kho sản phẩm.';
        $rows[] = [['text' => '🧾 ĐƠN HÀNG CỦA TÔI', 'callback_data' => 'member:orders']];
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function createOrder(string $chatId, string $userId, string $productId): void
    {
        $order = Database::transaction(function () use ($userId, $productId): array {
            $product = Database::one('SELECT * FROM products WHERE id = ? AND is_active = TRUE FOR UPDATE', [$productId]);
            if ($product === null) throw new RuntimeException('Sản phẩm không còn được bán.');
            if ($product['stock'] !== null && (int) $product['stock'] <= 0) throw new RuntimeException('Sản phẩm đã hết hàng.');

            $user = Database::one('SELECT balance, nyc_wallet, vip_level FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($user === null) throw new RuntimeException('Không tìm thấy hồ sơ thành viên. Hãy gửi /start trước.');
            if ((int) ($product['vip_level'] ?? 0) > (int) ($user['vip_level'] ?? 0)) {
                throw new RuntimeException('Sản phẩm yêu cầu VIP ' . (int) $product['vip_level'] . '. Hãy nâng cấp hạng thành viên trước.');
            }

            $walletType = in_array((string) $product['category'], ['otp', 'proxy'], true) ? 'NYC' : 'MAIN';
            $walletColumn = $walletType === 'NYC' ? 'nyc_wallet' : 'balance';
            $available = (int) ($user[$walletColumn] ?? 0);
            $price = (int) $product['price'];
            if ($available < $price) {
                throw new RuntimeException('Số dư ví ' . $walletType . ' không đủ. Cần ' . self::money($price) . '.');
            }

            $orderCode = 'HD' . strtoupper(base_convert((string) time(), 10, 36)) . strtoupper(bin2hex(random_bytes(3)));
            Database::run("UPDATE users SET {$walletColumn} = {$walletColumn} - ? WHERE user_id = ?", [$price, $userId]);
            $created = Database::one(
                "INSERT INTO orders (order_code, user_id, product_id, product_name, category, amount, status) " .
                "VALUES (?, ?, ?, ?, ?, ?, 'pending') RETURNING order_code, product_name, amount, status",
                [$orderCode, $userId, $product['id'], $product['name'], $product['category'], $price]
            );
            Database::run(
                "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type) VALUES (?, ?, ?, 'PURCHASE', ?, ?)",
                ['order:' . $orderCode, $userId, -$price, 'Thanh toán đơn ' . $orderCode, $walletType]
            );
            Database::run(
                "INSERT INTO transactions (user_id, amount, type, reference_code, status, wallet_type) VALUES (?, ?, 'PURCHASE', ?, 'paid', ?)",
                [$userId, $price, $orderCode, $walletType]
            );
            if ($product['stock'] !== null) {
                Database::run('UPDATE products SET stock = stock - 1, updated_at = NOW() WHERE id = ?', [$product['id']]);
            }
            $remaining = (int) Database::value("SELECT {$walletColumn} FROM users WHERE user_id = ?", [$userId]);
            return [...($created ?? []), 'balance' => $remaining, 'wallet_type' => $walletType];
        });

        $this->telegram->sendMessage($chatId, implode("\n", [
            '✅ <b>ĐÃ TẠO ĐƠN HÀNG</b>',
            'Sản phẩm: <b>' . self::escape((string) $order['product_name']) . '</b>',
            'Mã đơn: <code>' . self::escape((string) $order['order_code']) . '</code>',
            'Số tiền: ' . self::money($order['amount']) . ' · Ví ' . self::escape((string) $order['wallet_type']),
            'Trạng thái: Chờ Admin xử lý.',
            'Số dư ví còn lại: ' . self::money($order['balance']),
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
        $stats = Database::one(
            "SELECT COUNT(*) AS total_orders,
                    COUNT(*) FILTER (WHERE status = 'processing') AS processing_orders,
                    COUNT(*) FILTER (WHERE status = 'completed') AS completed_orders,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders
             FROM orders WHERE user_id = ?",
            [$userId]
        ) ?? [];
        $ticketCount = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
        $linkedCount = (int) Database::value('SELECT COUNT(*) FROM linked_accounts WHERE user_id = ?', [$userId]);
        $this->telegram->sendMessage($chatId, implode("\n", [
            '💎 <b>TRUNG TÂM KHÁCH HÀNG HENDY</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '👤 Thành viên: <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '💰 Ví chính: <b>' . self::money($user['balance'] ?? 0) . '</b>',
            '🪙 Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>',
            '🎟 Ví Voucher: <b>' . self::money($user['voucher_wallet'] ?? 0) . '</b>',
            '💳 Tổng nạp: <b>' . self::money($user['total_deposited'] ?? 0) . '</b>',
            '👑 Trạng thái: <b>' . ((int) ($user['vip_level'] ?? 0) > 0 ? 'VIP ' . (int) $user['vip_level'] : 'Bình thường') . '</b>',
            '🔗 Tài khoản Mini Game đã liên kết: <b>' . $linkedCount . '</b>',
            '🧾 Đơn hàng: <b>' . (int) ($stats['total_orders'] ?? 0) . '</b> · Đang xử lý: <b>' . (int) ($stats['processing_orders'] ?? 0) . '</b>',
            '✅ Hoàn tất: <b>' . (int) ($stats['completed_orders'] ?? 0) . '</b> · Đã hủy: <b>' . (int) ($stats['cancelled_orders'] ?? 0) . '</b>',
            '🎧 Ticket CSKH: <b>' . $ticketCount . '</b>',
            '',
            'Toàn bộ thao tác khách hàng được thực hiện trực tiếp bằng menu Telegram; không cần Mini App.',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '💳 LỊCH SỬ GIAO DỊCH / NẠP TIỀN', 'callback_data' => 'member:deposit-history']],
                [['text' => '🚀 TÀI KHOẢN MINI GAME ĐÃ LIÊN KẾT', 'callback_data' => 'member:linked-accounts']],
                [['text' => '🧾 ĐƠN HÀNG CỦA TÔI', 'callback_data' => 'member:orders']],
                [['text' => '🎟️ YÊU CẦU VOUCHER 72H', 'callback_data' => 'member:voucher72']],
                [['text' => '🎧 TICKET HỖ TRỢ CSKH', 'callback_data' => 'member:support']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function sendLegacyLoginLink(string $chatId, string $userId): void
    {
        $baseUrl = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        if (!self::isHttpsUrl($baseUrl)) {
            throw new RuntimeException('APP_URL HTTPS chưa được cấu hình; chưa thể tạo link đăng nhập an toàn.');
        }
        $ticket = bin2hex(random_bytes(32));
        Database::run(
            'DELETE FROM legacy_login_tickets WHERE user_id = ? AND bot_key = ? AND (consumed_at IS NOT NULL OR expires_at <= NOW())',
            [$userId, $this->botKey]
        );
        Database::run(
            "INSERT INTO legacy_login_tickets (token_hash, user_id, bot_key, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL '5 minutes')",
            [hash('sha256', $ticket), $userId, $this->botKey]
        );
        $url = $baseUrl . '/legacy-login?ticket=' . rawurlencode($ticket);
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🔑 <b>LINK ĐĂNG NHẬP TRUYỀN THỐNG</b>',
            'Link chỉ sử dụng được <b>một lần</b> và hết hạn sau <b>5 phút</b>.',
            'Không chuyển tiếp link này cho người khác.',
            '',
            '<a href="' . self::escape($url) . '">👉 BẤM ĐỂ ĐĂNG NHẬP</a>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🔐 MỞ TRANG ĐĂNG NHẬP', 'url' => $url]],
                [['text' => '↩️ QUẢN LÝ TÀI KHOẢN', 'callback_data' => 'member:customer']],
            ]),
            'disable_web_page_preview' => true,
        ]);
    }

    private function showAbcvipConnect(string $chatId): void
    {
        $settings = $this->settings(['ABCVIP_CONNECT_URL']);
        $connectUrl = trim((string) ($settings['ABCVIP_CONNECT_URL'] ?? ''));
        $rows = [];
        if (self::isHttpsUrl($connectUrl)) {
            $rows[] = [['text' => '🔐 ĐĂNG NHẬP ABCVIP ĐỂ LIÊN KẾT', 'url' => $connectUrl]];
        }
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $message = [
            '🏦 <b>KHO TÀI KHOẢN ABCVIP</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Liên kết tài khoản qua trang đăng nhập chính thức của nhà cung cấp.',
            'HENDY không yêu cầu bạn gửi mật khẩu, mã OTP hoặc PIN trong cuộc trò chuyện Telegram.',
        ];
        if (!self::isHttpsUrl($connectUrl)) {
            $message[] = '';
            $message[] = '⏳ Cổng đăng nhập ABCVIP chưa được cấu hình. Admin cần cung cấp URL OAuth/SSO chính thức và địa chỉ callback được nhà cung cấp hỗ trợ.';
            $message[] = 'Hiện tại chức năng liên kết ABCVIP đang tạm khóa; không nhập thông tin đăng nhập vào bot.';
        }
        $this->telegram->sendMessage($chatId, implode("\n", $message), [
            'reply_markup' => $this->inlineMenu($rows),
            'disable_web_page_preview' => true,
        ]);
    }

    private function startLiveIssue(string $chatId, string $userId, string $issueType): void
    {
        $title = $issueType === 'live_auto_reorder' ? 'AUTO ĐẶT LẠI' : 'TRÚNG KHÔNG PHÁT';
        $this->setPending($userId, 'live_order_issue', ['ticket_type' => $issueType]);
        $this->telegram->sendMessage($chatId, '🆘 <b>YÊU CẦU ' . self::escape($title) . '</b>' . "\n" .
            'Gửi mã đơn Mini Game để CSKH kiểm tra. Yêu cầu sẽ được Admin xem xét; bot không tự tạo đơn mới hoặc tự cộng tiền.');
    }

    private function startVoucherRequest(string $chatId, string $userId): void
    {
        $orders = VoucherService::eligibleOrders($userId, 20);
        if ($orders === []) {
            $this->telegram->sendMessage($chatId, '🎟️ Hiện chưa có đơn nào đủ điều kiện Voucher 72H. Điều kiện: đơn đang chờ/đang xử lý và đã quá 72 giờ.');
            return;
        }
        $lines = ['🎟️ <b>YÊU CẦU VOUCHER 72H</b>', 'Gửi mã đơn đủ điều kiện trong danh sách dưới đây:', ''];
        foreach ($orders as $order) $lines[] = '• <code>' . self::escape($order['order_code']) . '</code> · ' . self::escape($order['product_name']) . ' · ' . self::money($order['amount']);
        $this->setPending($userId, 'voucher_order');
        $this->telegram->sendMessage($chatId, implode("\n", $lines) . "\n\nYêu cầu cần Admin duyệt; chỉ cộng Ví Voucher sau khi được chấp thuận.");
    }

    private function showOtpCenter(string $chatId, string $userId): void
    {
        $nycBalance = (int) (Database::value('SELECT nyc_wallet FROM users WHERE user_id = ?', [$userId]) ?? 0);
        $message = "⚙️ <b>OTP & PROXY CENTER</b>\nSố dư Ví NYC: <b>" . self::money($nycBalance) . "</b>\nChọn dịch vụ. Sản phẩm/giá lấy từ kho PostgreSQL; kết nối nhà cung cấp OTP cần cấu hình riêng.";
        $this->telegram->sendMessage($chatId, $message, [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🪙 NẠP VÍ NYC TỪ VÍ CHÍNH', 'callback_data' => 'member:nyc-transfer']],
                [['text' => '📱 THUÊ OTP SMS', 'callback_data' => 'member:otp-catalog']],
                [['text' => '🌐 PROXY CENTER', 'callback_data' => 'member:proxy-center']],
                [['text' => '📜 LỊCH SỬ OTP', 'callback_data' => 'member:otp-history']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showProxyCenter(string $chatId, string $userId): void
    {
        $stats = Database::one(
            "SELECT COUNT(*) FILTER (WHERE o.status = 'completed' AND (COALESCE(p.duration_days, 0) = 0 OR o.created_at + (COALESCE(p.duration_days, 0) * INTERVAL '1 day') > NOW())) AS active_count,
                    COUNT(*) FILTER (WHERE o.status = 'completed' AND p.duration_days > 0 AND o.created_at + (p.duration_days * INTERVAL '1 day') <= NOW()) AS expired_count,
                    COUNT(*) AS total_count
             FROM orders o LEFT JOIN products p ON p.id = o.product_id
             WHERE o.user_id = ? AND o.category = 'proxy'",
            [$userId]
        ) ?? [];
        $user = $this->getUser($userId);
        $this->telegram->sendMessage($chatId, implode("\n", [
            '🌐 <b>PROXY CENTER HENDY</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '👤 Thành viên: <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>',
            '🪙 Ví NYC: <b>' . self::money($user['nyc_wallet'] ?? 0) . '</b>',
            '📦 Tổng đơn Proxy: <b>' . (int) ($stats['total_count'] ?? 0) . '</b>',
            '🟢 Proxy còn hạn/không kỳ hạn: <b>' . (int) ($stats['active_count'] ?? 0) . '</b>',
            '🔴 Proxy hết hạn: <b>' . (int) ($stats['expired_count'] ?? 0) . '</b>',
            '',
            'Proxy Free chỉ hiển thị khi kho có sản phẩm miễn phí và tài khoản đạt cấp VIP yêu cầu.',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '🎁 PROXY FREE (VIP 1+)', 'callback_data' => 'member:proxy-free']],
                [['text' => '🔄 THUÊ PROXY XOAY / TĨNH', 'callback_data' => 'member:proxy-products']],
                [['text' => '📦 KHO PROXY ĐÃ THUÊ', 'callback_data' => 'member:proxy-rentals']],
                [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
            ]),
        ]);
    }

    private function showFreeProxyProducts(string $chatId, string $userId): void
    {
        $vip = (int) ($this->getUser($userId)['vip_level'] ?? 0);
        if ($vip < 1) {
            $this->telegram->sendMessage($chatId, '🔒 Proxy Free yêu cầu từ VIP 1. Hãy xem Trung tâm VIP để biết mốc nâng hạng.');
            return;
        }
        $products = Database::all(
            "SELECT id, name, description, stock, duration_days, vip_level FROM products
             WHERE category = 'proxy' AND price = 0 AND vip_level BETWEEN 1 AND ?
               AND is_active = TRUE AND (stock IS NULL OR stock > 0)
             ORDER BY created_at DESC LIMIT 20",
            [$vip]
        );
        $rows = [];
        $lines = ['🎁 <b>KHO PROXY FREE · VIP 1+</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($products as $product) {
            $rows[] = [['text' => self::truncate($product['name'] . ' · NHẬN MIỄN PHÍ', 55), 'callback_data' => 'member:order:' . $product['id']]];
            $lines[] = '• <b>' . self::escape($product['name']) . '</b> · VIP ' . (int) $product['vip_level'];
            if (trim((string) $product['description']) !== '') $lines[] = '  ' . self::escape($product['description']);
        }
        if ($products === []) $lines[] = 'Kho chưa có sản phẩm Proxy Free đang mở bán. Vui lòng quay lại sau.';
        $rows[] = [['text' => '🔙 PROXY CENTER', 'callback_data' => 'member:proxy-center']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function showProxyRentals(string $chatId, string $userId): void
    {
        $orders = Database::all(
            "SELECT o.order_code, o.product_name, o.amount, o.status, o.delivery_text, o.created_at,
                    COALESCE(p.duration_days, 0) AS duration_days
             FROM orders o LEFT JOIN products p ON p.id = o.product_id
             WHERE o.user_id = ? AND o.category = 'proxy' ORDER BY o.created_at DESC LIMIT 20",
            [$userId]
        );
        $lines = ['📦 <b>KHO PROXY ĐÃ THUÊ</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($orders as $order) {
            $lines[] = '• <b>' . self::escape($order['product_name']) . '</b> · <code>' . self::escape($order['order_code']) . '</code>';
            $lines[] = '  ' . self::money($order['amount']) . ' · ' . self::escape(self::statusLabel((string) $order['status']));
            if ((int) $order['duration_days'] > 0) {
                $expires = (new \DateTimeImmutable((string) $order['created_at']))->modify('+' . (int) $order['duration_days'] . ' days');
                $lines[] = '  Hạn dự kiến: ' . $expires->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
            }
            if ($order['status'] === 'completed' && trim((string) $order['delivery_text']) !== '') {
                $lines[] = '  Thông tin bàn giao: <code>' . self::escape(self::truncate((string) $order['delivery_text'], 240)) . '</code>';
            }
        }
        if ($orders === []) $lines[] = 'Bạn chưa có đơn Proxy.';
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu([
            [['text' => '🌐 THUÊ THÊM PROXY', 'callback_data' => 'member:proxy-products']],
            [['text' => '🔙 PROXY CENTER', 'callback_data' => 'member:proxy-center']],
        ])]);
    }

    private function showOtpHistory(string $chatId, string $userId): void
    {
        $orders = Database::all(
            "SELECT order_code, product_name, amount, status, created_at FROM orders
             WHERE user_id = ? AND category = 'otp' ORDER BY created_at DESC LIMIT 20",
            [$userId]
        );
        $lines = ['📱 <b>LỊCH SỬ DỊCH VỤ OTP</b>', '━━━━━━━━━━━━━━━━━━━━'];
        foreach ($orders as $order) {
            $lines[] = '• <code>' . self::escape($order['order_code']) . '</code> · ' . self::escape($order['product_name']);
            $lines[] = '  ' . self::money($order['amount']) . ' · ' . self::escape(self::statusLabel((string) $order['status']));
        }
        if ($orders === []) $lines[] = 'Bạn chưa có yêu cầu OTP.';
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu([
            [['text' => '📱 MỞ KHO OTP', 'callback_data' => 'member:otp-catalog']],
            [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']],
        ])]);
    }

    private function showBalance(    private function startVideoRequest(string $chatId, string $userId): void
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
            "SELECT amount, type, reference_code, wallet_type, status, created_at
             FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
            [$userId]
        );
        $lines = ['💳 <b>LỊCH SỬ NẠP TIỀN & GIAO DỊCH</b>', '━━━━━━━━━━━━━━━━━━━━', '<b>Yêu cầu QR / chuyển khoản</b>'];
        foreach ($requests as $request) {
            $lines[] = '• <code>' . self::escape((string) $request['payment_id']) . '</code> · ' .
                self::money($request['amount']) . ' · ' . self::escape(self::statusLabel((string) $request['status']));
            $lines[] = '  Ngân hàng: <b>' . self::escape((string) $request['bank_id']) . '</b> · STK <code>' . self::escape((string) $request['account_number']) . '</code>';
            $lines[] = '  Chủ TK: <b>' . self::escape((string) $request['account_holder']) . '</b>';
            $lines[] = '  Nội dung CK: <code>' . self::escape((string) $request['transfer_content']) . '</code>';
        }
        if ($requests === []) $lines[] = 'Chưa có yêu cầu QR/chuyển khoản.';
        $lines[] = '';
        $lines[] = '<b>Giao dịch đã ghi nhận</b>';
        foreach ($transactions as $transaction) {
            $sign = in_array((string) $transaction['type'], ['DEPOSIT', 'GIFTCODE', 'REFERRAL_REWARD', 'VOUCHER_CREDIT'], true) ? '+' : '';
            $lines[] = '• ' . $sign . self::money($transaction['amount']) . ' · ' . self::escape((string) $transaction['type']) .
                ' · Ví ' . self::escape((string) ($transaction['wallet_type'] ?? 'MAIN')) .
                ' · ' . self::escape((string) ($transaction['reference_code'] ?? '—'));
        }
        if ($transactions === []) $lines[] = 'Chưa có giao dịch phát sinh.';
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'reply_markup' => $this->inlineMenu([
                [['text' => '＋ TẠO YÊU CẦU NẠP', 'callback_data' => 'member:wallet']],
                [['text' => '📜 LỊCH SỬ VÍ CHI TIẾT', 'callback_data' => 'member:history']],
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

        $barLength = 10;
        $filled = (int) round(((int) ($status['progressPercent'] ?? 0)) / 10);
        $progressBar = str_repeat('🟦', max(0, min($barLength, $filled))) . str_repeat('⬛', $barLength - max(0, min($barLength, $filled)));
        $this->telegram->sendMessage($chatId, implode("\n", [
            '💠 <b>⩤ HENDY VIP PRO ⩥</b> 👑',
            '✨ <i>Đặc quyền hội viên · Nâng tầm trải nghiệm</i>',
            '━━━━━━━━━━━━━━━━━━━━',
            'Xin chào <b>' . self::escape($user['name'] ?: 'Thành viên') . '</b>!',
            '🆔 Telegram ID: <code>' . self::escape($userId) . '</code>',
            '🏆 Hạng hiện tại: <b>VIP ' . $status['currentLevel'] . '</b>',
            '💳 Tổng nạp tích lũy: <b>' . self::money($status['totalDeposited']) . '</b>',
            '⭐ Điểm thành viên: <b>' . number_format($status['pointOld'], 0, ',', '.') . '</b>',
            '📈 Tiến trình lên hạng: ' . $progressBar . ' <b>' . (int) ($status['progressPercent'] ?? 0) . '%</b>',
            $next,
            $checkinText,
            '🔥 Chuỗi điểm danh: <b>' . $status['streak'] . ' ngày</b>',
        ]), [
            'reply_markup' => $this->inlineMenu([
                [['text' => $status['checkedInToday'] ? '✅ ĐÃ ĐIỂM DANH HÔM NAY' : '🎁 ĐIỂM DANH VIP', 'callback_data' => 'member:vip-checkin', 'style' => 'success']],
                [['text' => 'ℹ️ THÔNG TIN TẤT CẢ VIP', 'callback_data' => 'member:vip-info', 'style' => 'primary']],
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

        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
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
        $stats = MatchmakingService::ensureStats($userId);
        $match = Database::one(
            "SELECT id, player_one, player_two, mode, status, created_at
             FROM matchmaking_matches
             WHERE status IN ('matched', 'disputed') AND (player_one = ? OR player_two = ?)
             ORDER BY created_at DESC LIMIT 1",
            [$userId, $userId]
        );
        $lines = [
            '⚔️ <b>HENDY MATCH · GHÉP ĐÔI TRỰC TIẾP TRÊN TELEGRAM</b>',
            '━━━━━━━━━━━━━━━━━━━━',
            '🏅 Elo: <b>' . (int) $stats['rating'] . '</b> · Điểm: <b>' . number_format((int) $stats['points'], 0, ',', '.') . '</b>',
            '🎮 Trận: <b>' . (int) $stats['matches_played'] . '</b> · Thắng: <b>' . (int) $stats['wins'] . '</b> · Thua: <b>' . (int) $stats['losses'] . '</b>',
            '',
        ];
        $rows = [];
        if ($match !== null) {
            $opponentId = (string) ($match['player_one'] === $userId ? $match['player_two'] : $match['player_one']);
            $opponent = Database::one('SELECT name, username FROM users WHERE user_id = ?', [$opponentId]) ?? [];
            $opponentName = (string) (($opponent['name'] ?? '') ?: (($opponent['username'] ?? '') ?: 'Thành viên HENDY'));
            $lines[] = '🆚 Đối thủ: <b>' . self::escape($opponentName) . '</b>';
            $lines[] = 'Mã trận: <code>#' . (int) $match['id'] . '</code> · ' . ($match['mode'] === 'quick' ? 'Xếp hạng' : 'Luyện tập');
            if ($match['status'] === 'disputed') {
                $lines[] = '⚠️ Trận đang tranh chấp. Admin sẽ kiểm tra; Elo/điểm chưa cập nhật.';
            } else {
                $result = Database::one('SELECT score_one, score_two, status, reported_by FROM matchmaking_results WHERE match_id = ?', [$match['id']]);
                if ($result === null) {
                    $lines[] = 'Trạng thái: <b>Đã ghép đôi</b>. Gửi tỷ số sau khi trận đấu kết thúc.';
                    $rows[] = [['text' => '🧾 GỬI KẾT QUẢ TRẬN', 'callback_data' => 'member:duel-report:' . $match['id']]];
                } elseif ($result['status'] === 'pending' && (string) $result['reported_by'] !== $userId) {
                    $lines[] = 'Tỷ số đối thủ báo: <b>' . (int) $result['score_one'] . ' - ' . (int) $result['score_two'] . '</b> (thứ tự A-B).';
                    $rows[] = [
                        ['text' => '✅ XÁC NHẬN ĐÚNG', 'callback_data' => 'member:duel-confirm:' . $match['id'] . ':yes'],
                        ['text' => '⚠️ BÁO SAI', 'callback_data' => 'member:duel-confirm:' . $match['id'] . ':no'],
                    ];
                } elseif ($result['status'] === 'pending') {
                    $lines[] = 'Bạn đã gửi tỷ số <b>' . (int) $result['score_one'] . ' - ' . (int) $result['score_two'] . '</b>. Đang chờ đối thủ xác nhận.';
                }
            }
            $rows[] = [['text' => '🔄 CẬP NHẬT TRẠNG THÁI', 'callback_data' => 'member:duel']];
        } else {
            $waiting = Database::one(
                "SELECT mode, created_at FROM matchmaking_queue WHERE user_id = ? AND status = 'waiting' ORDER BY created_at DESC LIMIT 1",
                [$userId]
            );
            if ($waiting !== null) {
                $lines[] = '⏳ Bạn đang trong hàng chờ (' . ($waiting['mode'] === 'quick' ? 'Xếp hạng' : 'Luyện tập') . ').';
                $rows[] = [['text' => '⛔ RỜI HÀNG CHỜ', 'callback_data' => 'member:duel-leave']];
            } else {
                $lines[] = 'Chọn chế độ ghép đôi để tìm đối thủ.';
                $rows[] = [['text' => '🏆 GHÉP ĐÔI XẾP HẠNG', 'callback_data' => 'member:duel-join-quick']];
                $rows[] = [['text' => '🎯 CHẾ ĐỘ LUYỆN TẬP', 'callback_data' => 'member:duel-join-practice']];
            }
        }

        $leaders = MatchmakingService::leaderboard(5);
        if ($leaders !== []) {
            $lines[] = '';
            $lines[] = '<b>🏆 TOP 5 HENDY MATCH</b>';
            foreach ($leaders as $index => $leader) {
                $name = (string) (($leader['display_name'] ?? '') ?: 'Thành viên');
                $lines[] = ($index + 1) . '. ' . self::escape($name) . ' · Elo ' . (int) $leader['rating'] . ' · ' . (int) $leader['points'] . ' điểm';
            }
        }
        $rows[] = [['text' => '🏠 MENU CHÍNH', 'callback_data' => 'member:home']];
        $this->telegram->sendMessage($chatId, implode("\n", $lines), ['reply_markup' => $this->inlineMenu($rows)]);
    }

    private function joinMatchmaking(string $chatId, string $userId, string $mode): void
    {
        try {
            $result = MatchmakingService::join($userId, $mode);
            if (($result['status'] ?? '') === 'matched' && isset($result['match'])) {
                $match = $result['match'];
                $opponentId = (string) ($match['player_one'] === $userId ? $match['player_two'] : $match['player_one']);
                $opponent = Database::one('SELECT name, username FROM users WHERE user_id = ?', [$opponentId]) ?? [];
                $name = (string) (($opponent['name'] ?? '') ?: (($opponent['username'] ?? '') ?: 'Thành viên HENDY'));
                $this->telegram->sendMessage($chatId, '🎮 Đã ghép đôi với <b>' . self::escape($name) . '</b>. Mã trận <code>#' . (int) $match['id'] . '</code>.');
                $this->notifyUserOnBot($this->botKey, $opponentId, '🎮 Bạn đã được ghép đôi trong HENDY MATCH. Mã trận #' . (int) $match['id'] . '. Mở nút GHÉP ĐÔI trong bot để xem chi tiết.');
            } elseif (($result['status'] ?? '') === 'disputed') {
                $this->telegram->sendMessage($chatId, '⚠️ Bạn đang có trận tranh chấp chờ Admin xử lý.');
            } else {
                $this->telegram->sendMessage($chatId, '⏳ Đã vào hàng chờ. Bot sẽ ghép với người chơi phù hợp khi có đối thủ.');
            }
        } catch (Throwable $error) {
            $this->telegram->sendMessage($chatId, '❌ Không thể ghép đôi: ' . self::escape($error->getMessage()));
        }
        $this->showMatchmaking($chatId, $userId);
    }

    private function startMatchReport(string $chatId, string $userId, int $matchId): void
    {
        $match = Database::one(
            "SELECT id, player_one, player_two FROM matchmaking_matches
             WHERE id = ? AND status = 'matched' AND (player_one = ? OR player_two = ?)",
            [$matchId, $userId, $userId]
        );
        if ($match === null) {
            $this->telegram->sendMessage($chatId, 'Không tìm thấy trận đang hoạt động hoặc bạn không thuộc trận này.');
            return;
        }
        $this->setPending($userId, 'duel_score_me', [
            'match_id' => $matchId,
            'player_one' => (string) $match['player_one'],
            'player_two' => (string) $match['player_two'],
        ]);
        $this->telegram->sendMessage($chatId, '🧾 <b>BÁO CÁO KẾT QUẢ TRẬN #' . $matchId . '</b>' . "\nHãy nhập số điểm của bạn (0–999). Sau đó bot sẽ hỏi điểm đối thủ.");
    }

    private function confirmMatchResult(string $chatId, string $userId, int $matchId, bool $approved): void
    {
        $match = Database::one('SELECT player_one, player_two FROM matchmaking_matches WHERE id = ?', [$matchId]);
        if ($match === null) {
            $this->telegram->sendMessage($chatId, 'Không tìm thấy trận đấu.');
            return;
        }
        try {
            $result = MatchmakingService::confirm($matchId, $userId, $approved);
            $otherId = (string) ($match['player_one'] === $userId ? $match['player_two'] : $match['player_one']);
            $message = ($result['status'] ?? '') === 'disputed'
                ? '⚠️ Trận #' . $matchId . ' đang tranh chấp. Elo và điểm chưa thay đổi; Admin sẽ kiểm tra.'
                : '✅ Hai bên đã xác nhận kết quả trận #' . $matchId . '. Elo và điểm nội bộ đã cập nhật.';
            $this->telegram->sendMessage($chatId, $message);
            $this->notifyUserOnBot($this->botKey, $otherId, $message);
        } catch (Throwable $error) {
            $this->telegram->sendMessage($chatId, '❌ Không thể xác nhận: ' . self::escape($error->getMessage()));
        }
        $this->showMatchmaking($chatId, $userId);
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

            case 'support_abuse':
                $stage = (string) ($payload['stage'] ?? 'order');
                if ($stage === 'order') {
                    if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $text)) {
                        $this->telegram->sendMessage($chatId, 'Mã đơn hàng không đúng định dạng. Hãy nhập đúng mã hiển thị trong mục Đơn hàng.');
                        return true;
                    }
                    $order = Database::one(
                        "SELECT id, order_code, product_name FROM orders WHERE order_code = ? AND user_id = ? AND status = 'completed'",
                        [$text, $userId]
                    );
                    if ($order === null) {
                        $this->telegram->sendMessage($chatId, 'Không tìm thấy đơn đã hoàn tất thuộc tài khoản của bạn. Hãy kiểm tra mã đơn hoặc chọn Ticket hỗ trợ chung.');
                        return true;
                    }
                    $payload['stage'] = 'message';
                    $payload['order_id'] = (int) $order['id'];
                    $payload['order_code'] = (string) $order['order_code'];
                    $payload['product_name'] = (string) $order['product_name'];
                    $this->setPending($userId, 'support_abuse', $payload);
                    $this->telegram->sendMessage($chatId, '✅ Đã xác nhận đơn <b>' . self::escape((string) $order['order_code']) . '</b>. Hãy mô tả lỗi dính lạm dụng/không nhận điểm (ít nhất 5 ký tự). Không gửi mật khẩu hoặc OTP.');
                    return true;
                }

                if ($stage === 'message') {
                    if (mb_strlen($text) < 5) {
                        $this->telegram->sendMessage($chatId, 'Nội dung cần ít nhất 5 ký tự. Hãy mô tả rõ vấn đề bạn gặp phải.');
                        return true;
                    }
                    $orderCode = (string) ($payload['order_code'] ?? '');
                    $subject = 'Hỗ trợ dính lạm dụng (60%) · Đơn ' . $orderCode;
                    $message = 'Đơn hàng: ' . $orderCode . "\n" .
                        'Sản phẩm: ' . (string) ($payload['product_name'] ?? '') . "\n\n" .
                        mb_substr($text, 0, 2600);
                    $ticket = Database::one(
                        "INSERT INTO support_tickets (user_id, ticket_type, subject, message) VALUES (?, 'dinh_lam', ?, ?) RETURNING id",
                        [$userId, $subject, mb_substr($message, 0, 3000)]
                    );
                    $this->clearPending($userId);
                    $this->notifyAdmins(
                        '🆘 <b>YÊU CẦU DÍNH LẠM DỤNG (60%)</b>' . "\n" .
                        'Ticket: <code>#' . (int) $ticket['id'] . '</code>' . "\n" .
                        'User: <code>' . self::escape($userId) . '</code>' . "\n" .
                        'Đơn: <code>' . self::escape($orderCode) . '</code>' . "\n" .
                        'Nội dung: ' . self::escape(mb_substr($text, 0, 1600)),
                        ['inline_keyboard' => [[['text' => '🎧 MỞ QUẢN LÝ TICKET', 'callback_data' => 'member:admin']]]]
                    );
                    $this->telegram->sendMessage($chatId, '✅ Đã tạo ticket hỗ trợ dính lạm dụng #' . (int) $ticket['id'] . ' cho đơn ' . self::escape($orderCode) . '. CSKH sẽ kiểm tra và phản hồi. Việc bồi hoàn (nếu có) cần Admin xác minh thủ công.');
                    return true;
                }

                $this->clearPending($userId);
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

            case 'live_order_issue':
                if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Mã đơn không đúng định dạng. Hãy nhập lại mã trong mục Đơn hàng.');
                    return true;
                }
                $order = Database::one(
                    "SELECT id, order_code, product_name FROM orders WHERE order_code = ? AND user_id = ? AND category = 'minigame'",
                    [$text, $userId]
                );
                if ($order === null) {
                    $this->telegram->sendMessage($chatId, 'Không tìm thấy đơn Mini Game thuộc tài khoản Telegram của bạn. Hãy kiểm tra lại mã đơn.');
                    return true;
                }
                $payload['order_id'] = (int) $order['id'];
                $payload['order_code'] = (string) $order['order_code'];
                $payload['product_name'] = (string) $order['product_name'];
                $this->setPending($userId, 'live_issue_message', $payload);
                $this->telegram->sendMessage($chatId, '✅ Đã nhận diện đơn <b>' . self::escape($order['order_code']) . '</b>. Hãy mô tả tình trạng bạn gặp phải (ít nhất 5 ký tự).');
                return true;

            case 'live_issue_message':
                if (mb_strlen($text) < 5) {
                    $this->telegram->sendMessage($chatId, 'Vui lòng mô tả ít nhất 5 ký tự để CSKH có thể kiểm tra.');
                    return true;
                }
                $issueType = (string) ($payload['ticket_type'] ?? 'live_win_no_reward');
                $title = $issueType === 'live_auto_reorder' ? 'Yêu cầu Auto đặt lại' : 'Trúng nhưng chưa phát thưởng';
                $orderCode = (string) ($payload['order_code'] ?? '');
                $ticket = Database::one(
                    'INSERT INTO support_tickets (user_id, subject, message, status, priority, ticket_type) VALUES (?, ?, ?, \'open\', \'normal\', ?) RETURNING id',
                    [$userId, $title . ' · Đơn ' . $orderCode, 'Đơn: ' . $orderCode . "\nSản phẩm: " . (string) ($payload['product_name'] ?? '') . "\nMô tả: " . $text, $issueType]
                );
                $this->clearPending($userId);
                $ticketId = (string) ($ticket['id'] ?? '');
                $this->telegram->sendMessage($chatId, '✅ Đã tạo ticket <b>#' . self::escape($ticketId) . '</b>. CSKH sẽ kiểm tra đơn và phản hồi. Yêu cầu chưa tự động tạo đơn mới hoặc cộng thưởng.');
                $this->notifyAdmins('🆘 <b>TICKET MINI GAME MỚI</b>' . "\nMã ticket: <code>" . self::escape($ticketId) . '</code>' . "\nUser: <code>" . self::escape($userId) . '</code>' . "\nĐơn: <code>" . self::escape($orderCode) . '</code>' . "\nLoại: ' . self::escape($title) . "\nNội dung: " . self::escape(self::truncate($text, 500)));
                return true;

            case 'voucher_order':
                if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $text)) {
                    $this->telegram->sendMessage($chatId, 'Mã đơn không đúng định dạng. Hãy nhập lại.');
                    return true;
                }
                $eligible = VoucherService::eligibleOrders($userId, 100);
                $selected = null;
                foreach ($eligible as $candidate) {
                    if (strcasecmp((string) $candidate['order_code'], $text) === 0) { $selected = $candidate; break; }
                }
                if ($selected === null) {
                    $this->telegram->sendMessage($chatId, 'Mã đơn chưa đủ điều kiện 72 giờ, đã xử lý, hoặc không thuộc tài khoản của bạn.');
                    return true;
                }
                try {
                    $request = VoucherService::create($userId, (int) $selected['id'], 'Đề nghị Voucher 72H qua menu Telegram.');
                    $this->clearPending($userId);
                    $this->telegram->sendMessage($chatId, '✅ Đã gửi yêu cầu Voucher 72H cho đơn <code>' . self::escape($text) . '</code>. Chờ Admin xét duyệt.');
                    $this->notifyAdmins('🎟️ <b>YÊU CẦU VOUCHER 72H</b>' . "\nTicket: <code>" . self::escape((string) $request['id']) . '</code>' . "\nUser: <code>" . self::escape($userId) . '</code>' . "\nĐơn: <code>" . self::escape($text) . '</code>');
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không thể tạo yêu cầu Voucher: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'duel_score_me':
                if (!preg_match('/^\\d{1,3}$/', $text) || (int) $text > 999) {
                    $this->telegram->sendMessage($chatId, 'Điểm phải là số nguyên từ 0 đến 999. Hãy nhập lại điểm của bạn.');
                    return true;
                }
                $payload['my_score'] = (int) $text;
                $this->setPending($userId, 'duel_score_opponent', $payload);
                $this->telegram->sendMessage($chatId, 'Nhập điểm của đối thủ (0–999). Thứ tự A-B sẽ được bot tự xác định.');
                return true;

            case 'duel_score_opponent':
                if (!preg_match('/^\\d{1,3}$/', $text) || (int) $text > 999) {
                    $this->telegram->sendMessage($chatId, 'Điểm phải là số nguyên từ 0 đến 999. Hãy nhập lại điểm của đối thủ.');
                    return true;
                }
                $matchId = (int) ($payload['match_id'] ?? 0);
                $playerOne = (string) ($payload['player_one'] ?? '');
                $playerTwo = (string) ($payload['player_two'] ?? '');
                $myScore = (int) ($payload['my_score'] ?? 0);
                $opponentScore = (int) $text;
                $scoreOne = $userId === $playerOne ? $myScore : $opponentScore;
                $scoreTwo = $userId === $playerTwo ? $myScore : $opponentScore;
                try {
                    $reported = MatchmakingService::report($matchId, $userId, $scoreOne, $scoreTwo);
                    $this->clearPending($userId);
                    $this->telegram->sendMessage($chatId, '✅ Đã gửi tỷ số ' . $scoreOne . '-' . $scoreTwo . ' (thứ tự A-B). Đang chờ đối thủ xác nhận.');
                    $this->notifyUserOnBot($this->botKey, (string) $reported['opponentId'], '🧾 Đối thủ đã gửi tỷ số trận #' . $matchId . ': ' . $scoreOne . '-' . $scoreTwo . '. Mở mục GHÉP ĐÔI trong bot để xác nhận hoặc báo sai.');
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không gửi được tỷ số: ' . self::escape($error->getMessage()));
                }
                return true;

            case 'nyc_transfer':
                if (!preg_match('/^\\d{1,12}$/', $text) || (int) $text < 1000 || (int) $text > 500000000) {
                    $this->telegram->sendMessage($chatId, 'Số tiền hợp lệ từ 1.000 đến 500.000.000 VNĐ. Hãy nhập lại.');
                    return true;
                }
                $amount = (int) $text;
                try {
                    $transfer = Database::transaction(function () use ($userId, $amount): array {
                        $user = Database::one('SELECT balance, nyc_wallet FROM users WHERE user_id = ? FOR UPDATE', [$userId]);
                        if ($user === null || (int) $user['balance'] < $amount) throw new RuntimeException('Ví Chính không đủ số dư để chuyển.');
                        Database::run('UPDATE users SET balance = balance - ?, nyc_wallet = nyc_wallet + ? WHERE user_id = ?', [$amount, $amount, $userId]);
                        $reference = 'nyc-transfer:' . bin2hex(random_bytes(12));
                        Database::run(
                            "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type) VALUES (?, ?, ?, 'WALLET_TRANSFER', ?, 'MAIN')",
                            [$reference . ':out', $userId, -$amount, 'Chuyển từ Ví Chính sang Ví NYC']
                        );
                        Database::run(
                            "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description, wallet_type) VALUES (?, ?, ?, 'WALLET_TRANSFER', ?, 'NYC')",
                            [$reference . ':in', $userId, $amount, 'Nhận từ Ví Chính']
                        );
                        Database::run(
                            "INSERT INTO transactions (user_id, amount, type, reference_code, status, wallet_type) VALUES (?, ?, 'WALLET_TRANSFER_OUT', ?, 'paid', 'MAIN'), (?, ?, 'WALLET_TRANSFER_IN', ?, 'paid', 'NYC')",
                            [$userId, $amount, $reference, $userId, $amount, $reference]
                        );
                        $balances = Database::one('SELECT balance, nyc_wallet FROM users WHERE user_id = ?', [$userId]);
                        return ['main' => (int) $balances['balance'], 'nyc' => (int) $balances['nyc_wallet']];
                    });
                    $this->clearPending($userId);
                    $this->telegram->sendMessage($chatId, '✅ <b>CHUYỂN VÍ THÀNH CÔNG</b>' . "\nĐã chuyển: <b>" . self::money($amount) . "</b>" . "\nVí Chính: " . self::money($transfer['main']) . "\nVí NYC: " . self::money($transfer['nyc']));
                } catch (Throwable $error) {
                    $this->telegram->sendMessage($chatId, '❌ Không thể chuyển ví: ' . self::escape($error->getMessage()));
                }
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
        $base = rtrim((string) (Env::get('APP_URL') ?: Env::get('RENDER_EXTERNAL_URL', '')), '/');
        if (!str_starts_with($base, 'https://')) return '';
        // The only Telegram Web App route retained is the admin-only dashboard.
        return $base . '/admin?section=' . rawurlencode($section) . '&bot=' . rawurlencode($this->botKey);
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
