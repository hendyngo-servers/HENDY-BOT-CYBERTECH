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
ALTER TABLE users ADD COLUMN IF NOT EXISTS nyc_wallet NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS voucher_wallet NUMERIC(18, 0) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS point_old BIGINT NOT NULL DEFAULT 0;


-- Daily VIP check-in. Each member can check in once per Vietnam-local calendar day.
CREATE TABLE IF NOT EXISTS vip_checkins (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    checkin_date DATE NOT NULL,
    points_awarded INTEGER NOT NULL DEFAULT 0 CHECK (points_awarded BETWEEN 0 AND 1000),
    streak_count INTEGER NOT NULL DEFAULT 1 CHECK (streak_count >= 1),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, checkin_date)
);
CREATE INDEX IF NOT EXISTS idx_vip_checkins_user_date ON vip_checkins(user_id, checkin_date DESC);

CREATE TABLE IF NOT EXISTS transactions (
    id BIGSERIAL PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL,
    type TEXT NOT NULL,
    reference_code TEXT,
    status TEXT NOT NULL DEFAULT 'paid',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS wallet_type TEXT NOT NULL DEFAULT 'MAIN';
CREATE INDEX IF NOT EXISTS idx_transactions_user_created ON transactions (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS deposit_events (
    reference_code TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Telegram bank-transfer requests are manually reviewed. A request never
-- credits a wallet until an administrator approves it.
CREATE TABLE IF NOT EXISTS payment_requests (
    payment_id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    amount NUMERIC(18, 0) NOT NULL CHECK (amount BETWEEN 10000 AND 500000000),
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key IN ('primary','secondary')),
    transfer_content TEXT NOT NULL UNIQUE,
    bank_id TEXT NOT NULL,
    account_number TEXT NOT NULL,
    account_holder TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
    reviewed_by TEXT,
    reviewed_at TIMESTAMPTZ,
    admin_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_payment_requests_status_created
    ON payment_requests(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_payment_requests_user_created
    ON payment_requests(user_id, created_at DESC);

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

ALTER TABLE wallet_ledger ADD COLUMN IF NOT EXISTS wallet_type TEXT NOT NULL DEFAULT 'MAIN';

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

ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS ticket_type TEXT NOT NULL DEFAULT 'support';
CREATE INDEX IF NOT EXISTS idx_support_tickets_type_created ON support_tickets(ticket_type, created_at DESC);

CREATE TABLE IF NOT EXISTS voucher_requests (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    order_code TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
    reviewed_by TEXT,
    reviewed_at TIMESTAMPTZ,
    admin_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_voucher_requests_status_created ON voucher_requests(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_voucher_requests_user_created ON voucher_requests(user_id, created_at DESC);

-- PostgreSQL-backed queue for video subtitle jobs. The optional worker
-- dispatches jobs to a configured provider; without a provider they stay queued.
CREATE TABLE IF NOT EXISTS video_jobs (
    id UUID PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    bot_key TEXT NOT NULL DEFAULT 'primary' CHECK (bot_key IN ('primary','secondary')),
    source_url TEXT NOT NULL,
    provider_job_id TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','completed','failed')),
    progress SMALLINT NOT NULL DEFAULT 0 CHECK (progress BETWEEN 0 AND 100),
    output_url TEXT NOT NULL DEFAULT '',
    error_message TEXT NOT NULL DEFAULT '',
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    claimed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_video_jobs_queue
    ON video_jobs(status, created_at ASC);
CREATE INDEX IF NOT EXISTS idx_video_jobs_user_created
    ON video_jobs(user_id, created_at DESC);

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

-- Default official website URL shown in the supplied Telegram welcome-screen reference.
-- Admin can change it from the application settings page; preserve later edits.
INSERT INTO app_settings (key, value, is_public)
VALUES ('website_url', 'https://hendy-bot-tele.onrender.com', TRUE)
ON CONFLICT (key) DO NOTHING;

INSERT INTO app_settings (key, value, is_public)
VALUES ('vip_checkin_points', '1', TRUE)
ON CONFLICT (key) DO NOTHING;

-- Default welcome-screen links. ON CONFLICT preserves any administrator customization.
UPDATE app_settings
SET value = 'https://hendy-bot-tele.onrender.com', updated_at = NOW()
WHERE key = 'website_url' AND value = 'https://lethituanem.com';

INSERT INTO app_settings (key, value, is_public)
VALUES
    ('GROUP_URL', 'https://t.me/Hendy_Support_Group', TRUE)
ON CONFLICT (key) DO NOTHING;

-- Legacy editable bot-name settings are retired; public Telegram URLs now come from deployment environment.
DELETE FROM app_settings WHERE key IN ('bot_username', 'bot_display_name');

-- Migrate only the old bundled defaults; preserve any administrator customization.
UPDATE app_settings
SET value = 'https://t.me/Hendy_Support_Group', updated_at = NOW()
WHERE key = 'GROUP_URL' AND value = 'https://t.me/lethituanem_nhomchat';

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
    button_icons JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE bot_menu_configs ADD COLUMN IF NOT EXISTS button_icons JSONB NOT NULL DEFAULT '{}'::jsonb;

-- Private Telegram webhook secrets: 256-bit random secret per bot, never exposed by any API.
-- Token fingerprint changes rotate only the corresponding bot's secret on the next startup.
CREATE TABLE IF NOT EXISTS telegram_webhook_secrets (
    bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
    token_fingerprint CHAR(64) NOT NULL CHECK (token_fingerprint ~ '^[a-f0-9]{64}$'),
    secret_value VARCHAR(256) NOT NULL CHECK (char_length(secret_value) BETWEEN 32 AND 256 AND secret_value !~ '[^A-Za-z0-9_-]'),
    override_fingerprint CHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Compatibility for an earlier provisioned table version.
ALTER TABLE telegram_webhook_secrets
    ADD COLUMN IF NOT EXISTS override_fingerprint CHAR(64) NOT NULL DEFAULT '';
ALTER TABLE telegram_webhook_secrets
    ALTER COLUMN secret_value TYPE VARCHAR(256);
ALTER TABLE telegram_webhook_secrets
    DROP CONSTRAINT IF EXISTS telegram_webhook_secrets_secret_value_check;
ALTER TABLE telegram_webhook_secrets
    ADD CONSTRAINT telegram_webhook_secrets_secret_value_check
    CHECK (char_length(secret_value) BETWEEN 32 AND 256 AND secret_value !~ '[^A-Za-z0-9_-]');

-- Apply the approved persistent keyboard layout while preserving unrelated settings.
UPDATE bot_menu_configs
SET menu_mode = 'reply_keyboard',
    menu_columns = 2,
    persistent_keyboard = TRUE,
    resize_keyboard = TRUE,
    button_labels = COALESCE(button_labels, '{}'::jsonb) || '{
        "live": "🎮 MINI GAME LIVE",
        "tools": "🧰 THUÊ TOOL (NEW)",
        "codes": "🎁 MUA LÌ XÌ - CODE MÀN - CODE TELE",
        "wallet": "💳 NẠP TIỀN",
        "otp": "🛡️ OTP & PROXY",
        "viptools": "👑 KHO CÔNG CỤ ĐỘC QUYỀN",
        "customer": "💠 TRUNG TÂM KHÁCH HÀNG HENDY",
        "matchmaking": "⚔️ HỆ THỐNG GHÉP ĐỐI ĐẤU",
        "ref": "🎊 GIỚI THIỆU REF",
        "vip": "👑 TRUNG TÂM VIP",
        "group": "👥 NHÓM CHAT ↗",
        "news": "📢 KÊNH THÔNG BÁO ↗",
        "gift": "🎟️ NHẬP GIFTCODE",
        "support": "🧑🏻‍💻 LIÊN HỆ CSKH ↗"
    }'::jsonb,
    button_styles = COALESCE(button_styles, '{}'::jsonb) || '{
        "live": "danger",
        "tools": "primary",
        "codes": "success",
        "wallet": "danger",
        "otp": "primary",
        "viptools": "success",
        "customer": "primary",
        "matchmaking": "danger",
        "ref": "primary",
        "vip": "success",
        "group": "primary",
        "news": "primary",
        "gift": "success",
        "support": "primary"
    }'::jsonb,
    updated_at = NOW();

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
    audience TEXT NOT NULL DEFAULT 'admin' CHECK (audience IN ('admin','users')),
    expires_at TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE realtime_tickets ADD COLUMN IF NOT EXISTS audience TEXT NOT NULL DEFAULT 'admin'
    CHECK (audience IN ('admin','users'));
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


-- Bank account/QR details are private runtime settings. Keep them available
-- to BotService/admin, but never include them in public bootstrap settings.
UPDATE app_settings
SET is_public = FALSE, updated_at = NOW()
WHERE key IN ('VIETQR_BANK_ID', 'VIETQR_ACCOUNT_NO', 'VIETQR_ACCOUNT_NAME');
