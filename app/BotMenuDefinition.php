<?php
declare(strict_types=1);

namespace Hendy;

/** Single source of truth for the persistent Telegram menu. */
final class BotMenuDefinition
{
    public const ITEMS = [
        ['action' => 'live', 'label' => '🎮 MINI GAME LIVE', 'description' => 'Chọn nhóm Mini Game Live và quản lý tài khoản.', 'style' => 'danger'],
        ['action' => 'otp', 'label' => '🛡️ OTP & PROXY', 'description' => 'Mở trung tâm OTP, Proxy và ví NYC.', 'style' => 'primary'],
        ['action' => 'wallet', 'label' => '💳 NẠP TIỀN', 'description' => 'Tạo yêu cầu nạp và xem trạng thái VietQR.', 'style' => 'success'],
        ['action' => 'vip', 'label' => '👑 TRUNG TÂM VIP', 'description' => 'Xem cấp VIP, quyền lợi và điểm danh.', 'style' => 'primary'],
        ['action' => 'codes', 'label' => '🎁 MUA LÌ XÌ · CODE MÀN · CODE TELE', 'description' => 'Mở kho lì xì, mã màn hình và mã Telegram.', 'style' => 'success'],
        ['action' => 'customer', 'label' => '💠 TRUNG TÂM KHÁCH HÀNG HENDY', 'description' => 'Hồ sơ, ví, đơn hàng và hỗ trợ khách hàng.', 'style' => 'primary'],
        ['action' => 'group', 'label' => '👥 NHÓM CHAT ↗', 'description' => 'Mở liên kết nhóm chat HENDY.', 'style' => 'primary'],
        ['action' => 'news', 'label' => '📢 KÊNH THÔNG BÁO ↗', 'description' => 'Mở kênh thông báo HENDY.', 'style' => 'primary'],
        ['action' => 'vip-coming-soon', 'label' => '💎 VIP DỊCH VỤ SẮP TRIỂN KHAI', 'description' => 'Thông báo các gói VIP dịch vụ đang được hoàn thiện.', 'style' => 'primary'],
    ];

    public const LAYOUT = [
        ['live', 'otp'],
        ['wallet', 'vip'],
        ['codes'],
        ['customer'],
        ['group', 'news'],
        ['vip-coming-soon'],
    ];

    // Keep legacy keys accepted by the admin JSON editor so old saved
    // configuration can still be opened and saved; only ITEMS render buttons.
    public const ACTIONS = [
        'live', 'otp', 'wallet', 'vip', 'codes', 'customer',
        'group', 'news', 'vip-coming-soon', 'admin',
        'tools', 'viptools', 'miniapp', 'ref', 'vietsub',
        'history', 'jobs', 'abcvip', 'gift', 'support',
    ];
}
