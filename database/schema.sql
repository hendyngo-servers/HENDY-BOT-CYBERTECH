CREATE TABLE IF NOT EXISTS users (
    user_id TEXT PRIMARY KEY,
    name TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    balance NUMERIC(18, 0) NOT NULL DEFAULT 0,
    total_deposited NUMERIC(18, 0) NOT NULL DEFAULT 0,
    vip_level INTEGER NOT NULL DEFAULT 0,
    ref_by TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE users ADD COLUMN IF NOT EXISTS name TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS username TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN IF NOT EXISTS balance NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS total_deposited NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS vip_level INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS ref_by TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS transactions (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL,
    type TEXT NOT NULL,
    reference_code TEXT,
    status TEXT NOT NULL DEFAULT 'paid',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_transactions_user_created ON transactions (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS deposit_events (
    reference_code TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS products (
    id BIGSERIAL PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL CHECK (category IN ('minigame','tools','codes','otp','proxy','vip')),
    description TEXT NOT NULL DEFAULT '',
    price NUMERIC(18, 0) NOT NULL CHECK (price >= 0),
    duration_days INTEGER NOT NULL DEFAULT 0,
    stock INTEGER,
    vip_level INTEGER NOT NULL DEFAULT 0,
    image_url TEXT,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    order_code TEXT NOT NULL UNIQUE,
    user_id TEXT NOT NULL,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    product_name TEXT NOT NULL,
    category TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount >= 0),
    status TEXT NOT NULL DEFAULT 'pending',
    admin_note TEXT NOT NULL DEFAULT '',
    delivery_text TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS wallet_ledger (
    id BIGSERIAL PRIMARY KEY,
    reference_code TEXT UNIQUE,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL,
    transaction_type TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    subject TEXT NOT NULL,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    priority TEXT NOT NULL DEFAULT 'normal',
    admin_reply TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS giftcodes (
    code TEXT PRIMARY KEY,
    reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
    max_redemptions INTEGER NOT NULL DEFAULT 1 CHECK (max_redemptions > 0),
    redeemed_count INTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMPTZ,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS giftcode_redemptions (
    code TEXT NOT NULL REFERENCES giftcodes(code) ON DELETE CASCADE,
    user_id TEXT NOT NULL,
    reward_amount NUMERIC(18, 0) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (code, user_id)
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    is_public BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS bot_menu_configs (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    menu_mode TEXT NOT NULL DEFAULT 'reply_keyboard' CHECK (menu_mode IN ('reply_keyboard','inline_keyboard')),
    intro_text TEXT NOT NULL DEFAULT '',
    menu_columns SMALLINT NOT NULL DEFAULT 2 CHECK (menu_columns BETWEEN 1 AND 3),
    persistent_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
    resize_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
    button_labels JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_descriptions JSONB NOT NULL DEFAULT '{}'::jsonb,
    button_styles JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS admin_audit (
    id BIGSERIAL PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    action TEXT NOT NULL,
    target TEXT NOT NULL DEFAULT '',
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS legacy_login_tickets (
    token_hash CHAR(64) PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS linked_accounts (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    brand TEXT NOT NULL,
    username TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id, brand, username)
);
CREATE INDEX IF NOT EXISTS idx_linked_accounts_user ON linked_accounts(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS matchmaking_matches (
    id BIGSERIAL PRIMARY KEY,
    player_one TEXT NOT NULL,
    player_two TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'quick',
    status TEXT NOT NULL DEFAULT 'matched',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS matchmaking_queue (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'quick',
    status TEXT NOT NULL DEFAULT 'waiting',
    matched_user_id TEXT,
    match_id BIGINT REFERENCES matchmaking_matches(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_matchmaking_one_waiting
    ON matchmaking_queue(user_id) WHERE status = 'waiting';
CREATE INDEX IF NOT EXISTS idx_matchmaking_mode_waiting
    ON matchmaking_queue(mode, status, created_at);

CREATE TABLE IF NOT EXISTS bot_pending_inputs (
    user_id TEXT NOT NULL,
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    input_type TEXT NOT NULL,
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    expires_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (user_id, bot_key)
);

CREATE TABLE IF NOT EXISTS telegram_updates (
    bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
    update_id BIGINT NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (bot_key, update_id)
);

CREATE INDEX IF NOT EXISTS idx_products_category_active ON products(category, is_active);
CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wallet_user_created ON wallet_ledger(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_user_created ON support_tickets(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_legacy_login_expiry ON legacy_login_tickets(expires_at);

INSERT INTO app_settings (key, value, is_public) VALUES
    ('homepage_title', 'Hendy Bot', TRUE),
    ('announcement', '', TRUE),
    ('VIETQR_BANK_ID', '', TRUE),
    ('VIETQR_ACCOUNT_NO', '', TRUE),
    ('VIETQR_ACCOUNT_NAME', '', TRUE),
    ('GROUP_URL', '', TRUE),
    ('CHANNEL_URL', '', TRUE),
    ('referral_commission_percent', '0', TRUE),
    ('support_message', 'Đội ngũ CSKH sẽ phản hồi trong thời gian sớm nhất.', TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO bot_menu_configs (bot_key, menu_mode, intro_text, menu_columns, persistent_keyboard, resize_keyboard)
VALUES
    ('primary', 'reply_keyboard', 'Chào mừng bạn đến với HENDY BOT. Chọn dịch vụ ở bàn phím phía dưới để bắt đầu.', 2, TRUE, TRUE),
    ('secondary', 'inline_keyboard', 'Chào mừng bạn đến với HENDY BOT. Chọn một chức năng để tiếp tục.', 2, TRUE, TRUE)
ON CONFLICT (bot_key) DO NOTHING;


-- Durable event stream and one-time WebSocket authentication tickets.
CREATE TABLE IF NOT EXISTS realtime_events (
    id BIGSERIAL PRIMARY KEY,
    event_type TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'admin' CHECK (audience IN ('admin','users','all')),
    payload JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_realtime_events_created ON realtime_events(created_at);
CREATE TABLE IF NOT EXISTS realtime_tickets (
    ticket_hash CHAR(64) PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_realtime_tickets_expiry ON realtime_tickets(expires_at);
CREATE TABLE IF NOT EXISTS module_artifacts (
    id BIGSERIAL PRIMARY KEY,
    module_key TEXT NOT NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending_review' CHECK (status IN ('pending_review','approved','rejected','released')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_module_artifacts_status ON module_artifacts(status, created_at DESC);
CREATE TABLE IF NOT EXISTS store_integrations (
    provider TEXT PRIMARY KEY CHECK (provider IN ('app_store_connect','google_play','microsoft_store')),
    config_public JSONB NOT NULL DEFAULT '{}'::jsonb,
    secret_refs JSONB NOT NULL DEFAULT '{}'::jsonb,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
