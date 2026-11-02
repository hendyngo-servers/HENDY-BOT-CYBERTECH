-- Two Telegram bots share D1 business data but have separate menus and update IDs.
CREATE TABLE IF NOT EXISTS telegram_bot_configs (
  bot_key TEXT PRIMARY KEY CHECK (bot_key IN ('primary','secondary')),
  menu_mode TEXT NOT NULL DEFAULT 'inline_keyboard' CHECK (menu_mode IN ('reply_keyboard','inline_keyboard')),
  intro_text TEXT NOT NULL DEFAULT 'Chào mừng bạn đến với Hendy Bot.',
  menu_columns INTEGER NOT NULL DEFAULT 2 CHECK (menu_columns BETWEEN 1 AND 3),
  persistent_keyboard INTEGER NOT NULL DEFAULT 1 CHECK (persistent_keyboard IN (0,1)),
  resize_keyboard INTEGER NOT NULL DEFAULT 1 CHECK (resize_keyboard IN (0,1)),
  button_config TEXT NOT NULL DEFAULT '{}',
  updated_by TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
INSERT OR IGNORE INTO telegram_bot_configs(bot_key,menu_mode,intro_text)
VALUES ('primary','inline_keyboard','Chào mừng bạn đến với Hendy Bot.'),
       ('secondary','reply_keyboard','Chào mừng bạn đến với Hendy Bot phụ.');
CREATE TABLE IF NOT EXISTS processed_telegram_updates (
  bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
  update_id TEXT NOT NULL,
  processed_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (bot_key,update_id)
);
CREATE TABLE IF NOT EXISTS telegram_bot_menu_messages (
  bot_key TEXT NOT NULL CHECK (bot_key IN ('primary','secondary')),
  chat_id TEXT NOT NULL,
  message_id INTEGER NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (bot_key,chat_id)
);
CREATE INDEX IF NOT EXISTS idx_telegram_bot_menu_messages_updated ON telegram_bot_menu_messages(bot_key,updated_at DESC);
