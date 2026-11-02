<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';

use Hendy\Database;
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
    "INSERT INTO bot_menu_configs (bot_key, menu_mode, button_labels) VALUES ('primary', 'reply_keyboard', '{""wallet"":""NẠP TIỀN""}'::jsonb) ON CONFLICT (bot_key) DO UPDATE SET menu_mode = EXCLUDED.menu_mode"
);
$menu = Database::one('SELECT menu_mode FROM bot_menu_configs WHERE bot_key = ?', ['primary']);
$assert($menu !== null && $menu['menu_mode'] === 'reply_keyboard', 'bot menu config');

Database::run(
    "INSERT INTO support_tickets (user_id, subject, message) VALUES (?, ?, ?)",
    [$userId, 'CI smoke ticket', 'Testing support ticket schema']
);
$ticketCount = (int) Database::value('SELECT COUNT(*) FROM support_tickets WHERE user_id = ?', [$userId]);
$assert($ticketCount === 1, 'support ticket schema');

echo "Đã chạy {$checks} PostgreSQL smoke checks.\n";
