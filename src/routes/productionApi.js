import express from "express";
import crypto from "node:crypto";
import multer from "multer";
import { executeD1Query, d1First, getD1Health, getBotSettings, setBotSetting, setTelegramBotConfig, writeAdminAudit } from "../services/productionD1.js";
import { requireTelegramAuth, requireAdmin, isTelegramAdmin } from "../middlewares/telegramAuth.js";
import { buildMainKeyboard } from "../services/telegramKeyboard.js";
import { broadcastPublicConfigUpdate, broadcastRealtime } from "../services/adminRealtime.js";
import { scanConfigurationLocations } from "../services/configScanner.js";
import { getRuntimeConfigStatus, syncRuntimeConfig } from "../services/runtimeConfigSync.js";
import { refreshTelegramMenuMessages } from "../services/telegramMenuSync.js";

const router = express.Router();
const releaseUpload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 50 * 1024 * 1024, files: 1 } });
const asyncRoute = (handler) => (req, res, next) => Promise.resolve(handler(req, res, next)).catch(next);
const id = () => crypto.randomUUID();
const text = (value, max = 500) => String(value ?? "").trim().slice(0, max);
const number = (value, fallback = 0) => Number.isSafeInteger(Number(value)) ? Number(value) : fallback;

const TELEGRAM_MENU_MODULES = ["mini_game_live","app_tool","buy_code","wallet","otp_proxy","vip_tools","support","pairing","referral","giftcode"];

function validateTelegramLink(value, field) {
  const normalized = text(value, 2048);
  if (!normalized) return "";
  let url;
  try { url = new URL(normalized); } catch { throw new Error(field + " phải là URL HTTPS hợp lệ."); }
  if (url.protocol !== "https:" || !["t.me", "telegram.me"].includes(url.hostname.toLowerCase()) || url.pathname === "/") {
    throw new Error(field + " phải là liên kết HTTPS hợp lệ thuộc t.me hoặc telegram.me.");
  }
  return url.toString();
}

function validateTheme(value) {
  const defaults = { primary: "#2563eb", accent: "#22d3ee", background: "#0a0e17", panel: "#111827" };
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("ui_theme phải là đối tượng màu hợp lệ.");
  const theme = { ...defaults };
  for (const key of Object.keys(defaults)) {
    if (value[key] !== undefined) {
      const color = String(value[key]);
      if (!/^#[0-9a-f]{6}$/i.test(color)) throw new Error("Màu " + key + " phải ở dạng #RRGGBB.");
      theme[key] = color.toLowerCase();
    }
  }
  return theme;
}

function validateMenuConfig(value) {
  if (!Array.isArray(value) || value.length > TELEGRAM_MENU_MODULES.length) throw new Error("menu_config không hợp lệ.");
  const seen = new Set();
  return value.map((item) => {
    const module = text(item?.module, 40);
    if (!TELEGRAM_MENU_MODULES.includes(module) || seen.has(module)) throw new Error("Menu có module không hợp lệ hoặc bị trùng.");
    seen.add(module);
    const label = text(item?.label, 48);
    if (!label) throw new Error("Nhãn menu không được để trống.");
    return { module, label, enabled: item?.enabled !== false };
  });
}

function formatOrder(row) {
  if (!row) return row;
  let details = {};
  try { details = typeof row.details_json === "string" ? JSON.parse(row.details_json) : (row.details || {}); } catch {}
  return {
    ...row,
    orderNumber: row.orderNumber || row.order_number || row.id,
    userId: row.userId || row.telegram_id || row.user_id,
    customerName: row.customerName || [row.first_name, row.last_name].filter(Boolean).join(" ") || "Thành viên",
    paymentStatus: row.paymentStatus || row.payment_status || "unpaid",
    createdAt: row.createdAt || row.created_at,
    updatedAt: row.updatedAt || row.updated_at,
    description: row.description || details.description || "",
    details,
    quote: row.quote || details.quote || null
  };
}


async function ensureUser(user) {
  const telegramId = String(user.id);
  await executeD1Query(
    "INSERT INTO users(telegram_id,username,first_name,last_name,language_code) VALUES(?,?,?,?,?) " +
    "ON CONFLICT(telegram_id) DO UPDATE SET username=excluded.username,first_name=excluded.first_name," +
    "last_name=excluded.last_name,language_code=excluded.language_code,updated_at=datetime('now')",
    [telegramId, user.username || null, text(user.first_name, 100), text(user.last_name, 100), text(user.language_code || "vi", 12)]
  );
  await executeD1Query(
    "INSERT INTO referral_profiles(telegram_id,referral_code) VALUES(?,?) ON CONFLICT(telegram_id) DO NOTHING",
    [telegramId, "HENDY-" + crypto.randomBytes(4).toString("hex").toUpperCase()]
  );
  const record = await d1First(
    "SELECT telegram_id AS id,username,first_name,last_name,language_code,role,status,balance," +
    "voucher_balance AS voucher,points,created_at,updated_at FROM users WHERE telegram_id=?",
    [telegramId]
  );
  if (record && isTelegramAdmin(telegramId)) record.role = "admin";
  return record;
}

async function sendTelegram(method, payload, botKey = "primary") {
  const token = botKey === "secondary" ? process.env.BOT_SECOND_TOKEN : process.env.BOT_TOKEN;
  if (!token) {
    const error = new Error("BOT_TOKEN chưa được cấu hình.");
    error.status = 503;
    throw error;
  }
  const response = await fetch("https://api.telegram.org/bot" + token + "/" + method, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(10000)
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.ok !== true) {
    const error = new Error(data.description || "Telegram API request failed.");
    error.status = 502;
    throw error;
  }
  return data.result;
}



async function handleTelegramUpdate(update, botKey = "primary") {
  const message = update.message || update.edited_message;
  const callback = update.callback_query;
  const settings = await getBotSettings(botKey);
  const telegram = (method, payload) => sendTelegram(method, payload, botKey);

  if (callback) {
    await telegram("answerCallbackQuery", { callback_query_id: callback.id });
    const chatId = callback.message?.chat?.id;
    if (chatId && String(callback.data || "").startsWith("open_")) {
      await telegram("sendMessage", {
        chat_id: chatId,
        text: "Vui lòng mở Mini App từ nút chức năng trong menu.",
        reply_markup: buildMainKeyboard(process.env, settings, botKey)
      });
    }
    return;
  }

  if (!message) return;
  const from = message.from || {};
  if (!from.id) return;
  const telegramId = String(from.id);
  const name = [from.first_name, from.last_name].filter(Boolean).join(" ") || "Thành viên";

  await executeD1Query(
    "INSERT INTO users(telegram_id,username,first_name,last_name,language_code) VALUES(?,?,?,?,?) " +
    "ON CONFLICT(telegram_id) DO UPDATE SET username=excluded.username,first_name=excluded.first_name," +
    "last_name=excluded.last_name,updated_at=datetime('now')",
    [telegramId, from.username || null, text(from.first_name, 100), text(from.last_name, 100), text(from.language_code || "vi", 12)]
  );

  const admin = isTelegramAdmin(telegramId, botKey);
  if (settings.maintenance_mode && !admin) {
    if (message.chat?.id) {
      await telegram("sendMessage", { chat_id: message.chat.id, text: settings.maintenance_message });
    }
    return;
  }

  if (message.web_app_data?.data) {
    if (message.chat?.id) {
      await telegram("sendMessage", { chat_id: message.chat.id, text: "Đã nhận dữ liệu từ Mini App." });
    }
    return;
  }

  const raw = text(message.text, 4000);
  const command = raw.split(/\s+/)[0].split("@")[0].toLowerCase();
  const chatId = message.chat?.id;
  if (!chatId) return;
  if (!raw.startsWith("/")) {
    const community = {
      "👥 NHÓM CHAT": settings.group_chat_url || process.env.GROUP_CHAT_URL,
      "📢 KÊNH THÔNG BÁO": settings.news_channel_url || process.env.NEWS_CHANNEL_URL,
      "☎️ LIÊN HỆ CSKH": settings.support_url || process.env.SUPPORT_URL
    };
    if (community[raw]) await telegram("sendMessage", { chat_id: chatId, text: community[raw] });
    return;
  }

  if (command === "/start") {
    const user = await d1First("SELECT balance FROM users WHERE telegram_id=?", [telegramId]);
    const lines = [
      settings.welcome_message,
      settings.bot_menu?.intro_text || "",
      "",
      "Xin chào " + name + "!",
      "Số dư ví: " + Number(user?.balance || 0).toLocaleString("vi-VN") + " VNĐ",
      "",
      "Chọn chức năng bạn cần ở menu bên dưới."
    ];
    if (admin) lines.push("", "Bạn có quyền quản trị. Sử dụng /admin để mở bảng điều khiển.");
    const sent = await telegram("sendMessage", { chat_id: chatId, text: lines.join("\n"), reply_markup: buildMainKeyboard(process.env, settings, botKey) });
    if (sent?.message_id) {
      // Menu persistence is best-effort during a rolling deployment where the D1 migration may still be applying.
      await executeD1Query(
        "INSERT INTO telegram_bot_menu_messages(bot_key,chat_id,message_id,updated_at) VALUES(?,?,?,datetime('now')) " +
        "ON CONFLICT(bot_key,chat_id) DO UPDATE SET message_id=excluded.message_id,updated_at=datetime('now')",
        [botKey, String(chatId), Number(sent.message_id)]
      ).catch((error) => console.warn("Could not track Telegram menu message:", error.message));
    }
  } else if (command === "/help") {
    await telegram("sendMessage", {
      chat_id: chatId,
      text: "/start - Mở menu dịch vụ\n/help - Hướng dẫn\n/id - Xem Telegram ID\n/wallet - Kiểm tra số dư" + (admin ? "\n/admin - Bảng quản trị" : "")
    });
  } else if (command === "/id") {
    await telegram("sendMessage", { chat_id: chatId, text: "Telegram ID: " + telegramId });
  } else if (command === "/wallet") {
    const user = await d1First("SELECT balance,voucher_balance FROM users WHERE telegram_id=?", [telegramId]);
    await telegram("sendMessage", {
      chat_id: chatId,
      text: "Số dư: " + Number(user?.balance || 0).toLocaleString("vi-VN") + " VNĐ\nVoucher: " + Number(user?.voucher_balance || 0).toLocaleString("vi-VN")
    });
  } else if (command === "/admin") {
    if (!admin) {
      await telegram("sendMessage", { chat_id: chatId, text: "Bạn không có quyền quản trị." });
    } else {
      let target = process.env.ADMIN_WEBAPP_URL || process.env.WEBAPP_URL;
      if (target) { const adminUrl = new URL(target); adminUrl.searchParams.set("bot", botKey); target = adminUrl.toString(); }
      if (!target) {
        await telegram("sendMessage", { chat_id: chatId, text: "Chưa cấu hình ADMIN_WEBAPP_URL." });
      } else {
        await telegram("sendMessage", {
          chat_id: chatId,
          text: "Trung tâm quản trị Hendy Bot",
          reply_markup: { inline_keyboard: [[{ text: "MỞ BẢNG QUẢN TRỊ", web_app: { url: target } }]] }
        });
      }
    }
  } else if (command === "/status") {
    await telegram("sendMessage", { chat_id: chatId, text: "Bot đang hoạt động. Dữ liệu nghiệp vụ được lưu tại Cloudflare D1." });
  }
}

function constantTimeEqual(left, right) {
  if (!left || !right) return false;
  const a = Buffer.from(String(left));
  const b = Buffer.from(String(right));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

router.get("/health", asyncRoute(async (_req, res) => {
  const health = await getD1Health();
  res.status(health.status === "connected" ? 200 : 503).json({ status: health.status, service: "hendy-bot-render", d1: health });
}));

// Telegram webhook is authenticated by Telegram's dedicated secret header, not WebApp initData.
async function acceptTelegramWebhook(req,res,botKey) {
  const expected = botKey === "secondary" ? process.env.TELEGRAM_SECOND_WEBHOOK_SECRET : process.env.WEBHOOK_SECRET;
  if (!expected || !constantTimeEqual(req.get("x-telegram-bot-api-secret-token"), expected))
    return res.status(401).json({ error: "Webhook secret không hợp lệ." });
  if (botKey === "secondary" && !process.env.BOT_SECOND_TOKEN)
    return res.status(404).json({ error: "Bot phụ chưa được cấu hình." });
  const update = req.body || {};
  if (update.update_id === undefined || update.update_id === null)
    return res.status(400).json({ error: "Telegram update thiếu update_id." });
  const updateId = String(update.update_id);
  const inserted = await d1First(
    "INSERT OR IGNORE INTO processed_telegram_updates(bot_key,update_id) VALUES(?,?) RETURNING update_id",
    [botKey, updateId]
  );
  if (!inserted) return res.json({ ok: true, duplicate: true });
  const eventType = update.message ? "message" : update.callback_query ? "callback_query" : "other";
  const telegramId = update.message?.from?.id || update.callback_query?.from?.id || null;
  const eventId = botKey + ":" + updateId;
  try {
    await handleTelegramUpdate(update, botKey);
    await executeD1Query(
      "INSERT INTO bot_events(update_id,event_type,telegram_user_id) VALUES(?,?,?) ON CONFLICT(update_id) DO NOTHING",
      [eventId, eventType, telegramId ? String(telegramId) : null]
    );
    return res.json({ ok: true });
  } catch (error) {
    await executeD1Query("DELETE FROM processed_telegram_updates WHERE bot_key=? AND update_id=?", [botKey, updateId]).catch(() => {});
    throw error;
  }
}
router.post("/telegram/webhook", asyncRoute((req,res)=>acceptTelegramWebhook(req,res,"primary")));
router.post("/telegram/webhook/secondary", asyncRoute((req,res)=>acceptTelegramWebhook(req,res,"secondary")));


router.get("/catalog", asyncRoute(async (req, res) => {
  const category = text(req.query.category || req.query.module, 80);
  const search = text(req.query.search, 100);
  const params = [];
  let sql = "SELECT id,title,slug,category,module,icon,developer,version,tagline,description,price,unit_price," +
    "r2_object_key AS r2ObjectKey,pages_deploy_url AS pagesDeployUrl,metadata_json,is_active,sort_order,created_at " +
    "FROM catalog_products WHERE is_active=1";
  if (category && category !== "all") {
    sql += " AND (category=? OR module=?)";
    params.push(category, category);
  }
  if (search) {
    sql += " AND (title LIKE ? OR tagline LIKE ? OR description LIKE ?)";
    params.push("%" + search + "%", "%" + search + "%", "%" + search + "%");
  }
  sql += " ORDER BY sort_order ASC,created_at DESC LIMIT 200";
  const result = await executeD1Query(sql, params);
  res.json({ items: result.results.map((row) => ({ ...row, ...JSON.parse(row.metadata_json || "{}") })) });
}));

router.get("/public-config", asyncRoute(async (_req, res) => {
  const settings = await getBotSettings();
  res.json({
    language: "vi",
    maintenance_mode: settings.maintenance_mode,
    maintenance_message: settings.maintenance_message,
    support_telegram: settings.support_telegram,
    group_chat_url: settings.group_chat_url,
    news_channel_url: settings.news_channel_url,
    support_url: settings.support_url,
    ui_theme: settings.ui_theme,
    menu_config: settings.menu_config
  });
}));

router.get("/releases", asyncRoute(async (_req, res) => {
  const rows = await executeD1Query("SELECT id,title,version,platform,download_url AS downloadUrl,notes,created_at FROM app_releases WHERE is_active=1 ORDER BY created_at DESC LIMIT 100");
  res.json({ releases: rows.results });
}));


router.get("/releases/:id/download", asyncRoute(async (req, res) => {
  const release = await d1First("SELECT download_url FROM app_releases WHERE id=? AND is_active=1", [text(req.params.id, 100)]);
  if (!release?.download_url || !/^https:\/\//i.test(release.download_url)) {
    return res.status(404).json({ error: "Không tìm thấy bản phát hành." });
  }
  res.redirect(302, release.download_url);
}));

router.use(requireTelegramAuth);

// Chế độ bảo trì được áp dụng cho toàn bộ API thành viên; admin vẫn có thể vận hành dashboard.
router.use(asyncRoute(async (req, res, next) => {
  if (isTelegramAdmin(req.telegramUser?.id)) return next();
  const settings = await getBotSettings();
  if (!settings.maintenance_mode) return next();
  res.set("Retry-After", "60");
  return res.status(503).json({
    error: settings.maintenance_message || "Hệ thống đang bảo trì. Vui lòng quay lại sau.",
    code: "BOT_MAINTENANCE",
    maintenance: true
  });
}));

router.get("/home", asyncRoute(async (req, res) => {
  const user = await ensureUser(req.telegramUser);
  const orderCount = await d1First("SELECT COUNT(*) AS total FROM orders WHERE telegram_id=?", [String(req.telegramUser.id)]);
  res.json({ user, orderCount: Number(orderCount?.total || 0), orderSummary: { total: Number(orderCount?.total || 0) } });
}));

router.get("/wallet", asyncRoute(async (req, res) => {
  const user = await ensureUser(req.telegramUser);
  const paymentSettings = await getBotSettings();
  const ledger = await executeD1Query(
    "SELECT id,amount_delta AS amount_delta,balance_after,entry_type,note,created_at FROM wallet_ledger " +
    "WHERE telegram_id=? ORDER BY created_at DESC LIMIT 50",
    [String(req.telegramUser.id)]
  );
  const depositRows = await executeD1Query(
    "SELECT id,amount,transfer_content AS reference,status,admin_note,created_at FROM deposits WHERE telegram_id=? ORDER BY created_at DESC LIMIT 30",
    [String(req.telegramUser.id)]
  );
  const voucherRows = await executeD1Query(
    "SELECT id,amount_delta,balance_after,entry_type,note,created_at FROM voucher_ledger WHERE telegram_id=? ORDER BY created_at DESC LIMIT 50",
    [String(req.telegramUser.id)]
  );
  res.json({
    userId: user.id,
    balance: user.balance,
    voucher: user.voucher,
    points: user.points,
    ledger: ledger.results,
    transactions: ledger.results,
    voucherLedger: voucherRows.results,
    deposits: depositRows.results,
    payment: {
      configured: Boolean(paymentSettings.vietqr_bank && paymentSettings.vietqr_account_no && paymentSettings.vietqr_account_name),
      bankName: paymentSettings.vietqr_bank || "",
      bankBin: paymentSettings.vietqr_bank || "",
      accountNumber: paymentSettings.vietqr_account_no || "",
      accountName: paymentSettings.vietqr_account_name || "",
      instruction: "Chuyển khoản đúng số tiền và nội dung tham chiếu của yêu cầu nạp. Chờ quản trị viên đối soát."
    }
  });
}));

router.get("/orders", asyncRoute(async (req, res) => {
  await ensureUser(req.telegramUser);
  const result = await executeD1Query(
    "SELECT id,telegram_id AS userId,title,module,product_id AS productId,quantity,amount,status," +
    "payment_status AS paymentStatus,details_json,created_at,updated_at FROM orders " +
    "WHERE telegram_id=? ORDER BY created_at DESC LIMIT 100",
    [String(req.telegramUser.id)]
  );
  res.json({ orders: result.results.map(formatOrder) });
}));

router.post("/orders", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  await ensureUser(req.telegramUser);
  const productId = text(req.body?.productId || req.body?.catalogItemId, 100);
  const quantity = Math.max(1, Math.min(100, number(req.body?.quantity, 1)));
  let product = null;
  if (productId) {
    product = await d1First("SELECT id,title,module,unit_price,is_active FROM catalog_products WHERE id=?", [productId]);
    if (!product || !product.is_active) return res.status(404).json({ error: "Sản phẩm không tồn tại hoặc đã ngừng bán." });
  }
  const amount = product ? Number(product.unit_price || 0) * quantity : 0;
  const orderId = "ORD-" + crypto.randomUUID().slice(0, 12).toUpperCase();
  await executeD1Query(
    "INSERT INTO orders(id,telegram_id,title,module,product_id,quantity,amount,status,payment_status,details_json) " +
    "VALUES(?,?,?,?,?,?,?,'pending','unpaid',?)",
    [orderId, userId, text(req.body?.title || product?.title || "Yêu cầu dịch vụ", 180),
      text(req.body?.module || product?.module || "tools", 80), product?.id || null, quantity, amount,
      JSON.stringify({ ...(req.body?.details && typeof req.body.details === "object" ? req.body.details : {}), description: text(req.body?.description, 2000) })]
  );
  const order = await d1First("SELECT * FROM orders WHERE id=?", [orderId]);
  res.status(201).json({ success: true, order: formatOrder(order) });
}));

router.post("/orders/:id/pay", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  const result = await d1First(
    "UPDATE orders SET payment_status='paid',status='processing',updated_at=datetime('now') " +
    "WHERE id=? AND telegram_id=? AND payment_status='unpaid' AND EXISTS " +
    "(SELECT 1 FROM users WHERE telegram_id=? AND balance>=orders.amount) RETURNING id",
    [text(req.params.id, 100), userId, userId]
  );
  if (!result) {
    const order = await d1First("SELECT status,payment_status,amount FROM orders WHERE id=? AND telegram_id=?", [req.params.id, userId]);
    if (!order) return res.status(404).json({ error: "Không tìm thấy đơn hàng." });
    if (order.payment_status === "paid") return res.json({ success: true, alreadyPaid: true });
    return res.status(400).json({ error: "Số dư không đủ hoặc đơn hàng không còn ở trạng thái có thể thanh toán." });
  }
  const updated = await d1First("SELECT * FROM orders WHERE id=?", [result.id]);
  const balance = await d1First("SELECT balance FROM users WHERE telegram_id=?", [userId]);
  res.json({ success: true, order: formatOrder(updated), balance: Number(balance?.balance || 0) });
}));


router.post("/orders/:id/quotes/:quoteId/respond", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  const order = await d1First("SELECT * FROM orders WHERE id=? AND telegram_id=?", [text(req.params.id, 100), userId]);
  if (!order) return res.status(404).json({ error: "Không tìm thấy đơn hàng." });
  let details = {};
  try { details = JSON.parse(order.details_json || "{}"); } catch {}
  const quote = details.quote;
  if (!quote || quote.id !== text(req.params.quoteId, 100) || quote.status !== "sent") {
    return res.status(409).json({ error: "Báo giá không tồn tại hoặc đã được xử lý." });
  }
  const decision = text(req.body?.decision, 20);
  if (!["accepted", "rejected"].includes(decision)) return res.status(400).json({ error: "Phản hồi báo giá không hợp lệ." });
  quote.status = decision;
  quote.respondedAt = new Date().toISOString();
  if (decision === "accepted") {
    await executeD1Query("UPDATE orders SET amount=?,status='confirmed',details_json=?,updated_at=datetime('now') WHERE id=? AND payment_status='unpaid'", [Number(quote.amount), JSON.stringify(details), order.id]);
  } else {
    await executeD1Query("UPDATE orders SET details_json=?,updated_at=datetime('now') WHERE id=?", [JSON.stringify(details), order.id]);
  }
  const updated = await d1First("SELECT * FROM orders WHERE id=?", [order.id]);
  res.json({ success: true, order: formatOrder(updated) });
}));

router.post("/orders/:id/status", requireAdmin, asyncRoute(async (req, res) => {
  const allowed = ["pending", "confirmed", "processing", "completed", "cancelled"];
  const status = text(req.body?.status, 30);
  if (!allowed.includes(status)) return res.status(400).json({ error: "Trạng thái đơn hàng không hợp lệ." });
  const updated = await d1First(
    "UPDATE orders SET status=?,updated_at=datetime('now') WHERE id=? RETURNING id,status",
    [status, text(req.params.id, 100)]
  );
  if (!updated) return res.status(404).json({ error: "Không tìm thấy đơn hàng." });
  await writeAdminAudit(req.telegramUser.id, "order.status.update", "order", updated.id, { status });
  res.json({ success: true, order: updated });
}));

router.get("/vip", asyncRoute(async (req, res) => {
  const user = await ensureUser(req.telegramUser);
  const points = Number(user.points || 0);
  const tiers = [
    { code: "basic", name: "Cơ bản", minPoints: 0, benefits: ["Truy cập dịch vụ cơ bản"] },
    { code: "silver", name: "Bạc", minPoints: 2000, benefits: ["Ưu đãi theo hạng thành viên"] },
    { code: "gold", name: "Vàng", minPoints: 4000, benefits: ["Ưu tiên hỗ trợ"] },
    { code: "diamond", name: "Kim cương", minPoints: 10000, benefits: ["Hỗ trợ ưu tiên nâng cao"] }
  ];
  const currentTier = [...tiers].reverse().find((tier) => points >= tier.minPoints) || tiers[0];
  const nextTier = tiers.find((tier) => tier.minPoints > points) || null;
  res.json({ points, currentTier, nextTier, tiers });
}));

router.get("/referral", asyncRoute(async (req, res) => {
  await ensureUser(req.telegramUser);
  const userId = String(req.telegramUser.id);
  const profile = await d1First(
    "SELECT p.referral_code,p.referred_by,p.claimed_at,u.username,u.first_name FROM referral_profiles p " +
    "LEFT JOIN users u ON u.telegram_id=p.referred_by WHERE p.telegram_id=?",
    [userId]
  );
  const counts = await d1First(
    "SELECT (SELECT COUNT(*) FROM referral_profiles WHERE referred_by=?) AS invited_count," +
    "(SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE referrer_telegram_id=? AND status IN ('pending','approved','paid')) AS total_commission," +
    "(SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE referrer_telegram_id=? AND status='paid') AS paid_commission",
    [userId, userId, userId]
  );
  const rows = await executeD1Query(
    "SELECT id,amount,commission_rate_bps AS commissionRateBps,status,order_id AS orderId,created_at AS createdAt " +
    "FROM referral_commissions WHERE referrer_telegram_id=? ORDER BY created_at DESC LIMIT 100",
    [userId]
  );
  const botUsername = text(process.env.BOT_USERNAME, 80).replace(/^@/, "");
  res.json({
    code: profile?.referral_code || null,
    inviteUrl: botUsername && profile?.referral_code ? "https://t.me/" + botUsername + "?start=ref_" + profile.referral_code : null,
    referredBy: profile?.referred_by ? { telegramId: profile.referred_by, username: profile.username, firstName: profile.first_name } : null,
    claimedAt: profile?.claimed_at || null,
    invitedCount: Number(counts?.invited_count || 0),
    totalCommission: Number(counts?.total_commission || 0),
    paidCommission: Number(counts?.paid_commission || 0),
    commissions: rows.results
  });
}));

router.post("/referral/claim", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  await ensureUser(req.telegramUser);
  const code = text(req.body?.code, 80).toUpperCase();
  if (!code) return res.status(400).json({ error: "Vui lòng nhập mã giới thiệu." });
  const inviter = await d1First("SELECT telegram_id FROM referral_profiles WHERE referral_code=?", [code]);
  if (!inviter) return res.status(404).json({ error: "Mã giới thiệu không tồn tại." });
  if (String(inviter.telegram_id) === userId) return res.status(400).json({ error: "Bạn không thể tự sử dụng mã giới thiệu của mình." });
  const inviterIsChild = await d1First("SELECT telegram_id FROM referral_profiles WHERE telegram_id=? AND referred_by=?", [String(inviter.telegram_id), userId]);
  if (inviterIsChild) return res.status(409).json({ error: "Không thể tạo liên kết giới thiệu vòng." });
  const updated = await d1First(
    "UPDATE referral_profiles SET referred_by=?,claimed_at=datetime('now') WHERE telegram_id=? AND referred_by IS NULL RETURNING telegram_id",
    [String(inviter.telegram_id), userId]
  );
  if (!updated) return res.status(409).json({ error: "Tài khoản đã được gắn mã giới thiệu hoặc không thể thay đổi." });
  await writeAdminAudit(userId, "referral.claim", "referral", userId, { inviter: String(inviter.telegram_id) });
  res.json({ success: true, message: "Đã ghi nhận mã giới thiệu." });
}));

router.get("/support/tickets", asyncRoute(async (req, res) => {
  const result = await executeD1Query(
    "SELECT id,subject,category,status,last_message,created_at,updated_at FROM support_tickets " +
    "WHERE telegram_id=? ORDER BY updated_at DESC LIMIT 100",
    [String(req.telegramUser.id)]
  );
  res.json({ tickets: result.results });
}));

router.post("/support/tickets", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  await ensureUser(req.telegramUser);
  const subject = text(req.body?.subject, 180);
  const message = text(req.body?.message, 4000);
  if (!subject || !message) return res.status(400).json({ error: "Vui lòng nhập tiêu đề và nội dung yêu cầu." });
  const ticketId = "TCK-" + crypto.randomUUID().slice(0, 12).toUpperCase();
  await executeD1Query(
    "INSERT INTO support_tickets(id,telegram_id,subject,category,status,last_message) VALUES(?,?,?,?,'open',?)",
    [ticketId, userId, subject, text(req.body?.category || "general", 60), message]
  );
  await executeD1Query(
    "INSERT INTO support_messages(id,ticket_id,sender_telegram_id,sender_type,message) VALUES(?,?,?,'user',?)",
    [crypto.randomUUID(), ticketId, userId, message]
  );
  const ticket = await d1First("SELECT * FROM support_tickets WHERE id=?", [ticketId]);
  const messages = await executeD1Query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at", [ticketId]);
  res.status(201).json({ success: true, ticket: { ticket, messages: messages.results } });
}));

router.get("/support/tickets/:id", asyncRoute(async (req, res) => {
  const ticket = await d1First("SELECT * FROM support_tickets WHERE id=? AND telegram_id=?", [text(req.params.id, 100), String(req.telegramUser.id)]);
  if (!ticket) return res.status(404).json({ error: "Không tìm thấy yêu cầu hỗ trợ." });
  const messages = await executeD1Query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at", [ticket.id]);
  res.json({ ticket, messages: messages.results });
}));

router.post("/support/tickets/:id/messages", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  const ticketId = text(req.params.id, 100);
  const message = text(req.body?.message, 4000);
  if (!message) return res.status(400).json({ error: "Nội dung phản hồi không được để trống." });
  const ticket = await d1First("SELECT id,telegram_id,status FROM support_tickets WHERE id=?", [ticketId]);
  if (!ticket) return res.status(404).json({ error: "Không tìm thấy yêu cầu hỗ trợ." });
  const admin = isTelegramAdmin(userId);
  if (!admin && ticket.telegram_id !== userId) return res.status(403).json({ error: "Không có quyền truy cập yêu cầu này." });
  await executeD1Query("INSERT INTO support_messages(id,ticket_id,sender_telegram_id,sender_type,message) VALUES(?,?,?,?,?)", [crypto.randomUUID(), ticketId, userId, admin ? "admin" : "user", message]);
  await executeD1Query("UPDATE support_tickets SET last_message=?,updated_at=datetime('now') WHERE id=?", [message, ticketId]);
  const currentTicket = await d1First("SELECT * FROM support_tickets WHERE id=?", [ticketId]);
  const messages = await executeD1Query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at", [ticketId]);
  res.json({ ticket: currentTicket, messages: messages.results });
}));

router.post("/support/tickets/:id/reply", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  const ticketId = text(req.params.id, 100);
  const message = text(req.body?.message, 4000);
  if (!message) return res.status(400).json({ error: "Nội dung phản hồi không được để trống." });
  const ticket = await d1First("SELECT id,telegram_id,status FROM support_tickets WHERE id=?", [ticketId]);
  if (!ticket) return res.status(404).json({ error: "Không tìm thấy yêu cầu hỗ trợ." });
  const admin = isTelegramAdmin(userId);
  if (!admin && ticket.telegram_id !== userId) return res.status(403).json({ error: "Không có quyền truy cập yêu cầu này." });
  await executeD1Query(
    "INSERT INTO support_messages(id,ticket_id,sender_telegram_id,sender_type,message) VALUES(?,?,?,?,?)",
    [crypto.randomUUID(), ticketId, userId, admin ? "admin" : "user", message]
  );
  await executeD1Query(
    "UPDATE support_tickets SET last_message=?,updated_at=datetime('now') WHERE id=?",
    [message, ticketId]
  );
  const messages = await executeD1Query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at", [ticketId]);
  res.json({ success: true, messages: messages.results });
}));

router.post("/deposits", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  await ensureUser(req.telegramUser);
  const amount = number(req.body?.amountVnd, 0);
  const paymentSettings = await getBotSettings();
  if (amount < 10000 || amount > 50000000) return res.status(400).json({ error: "Số tiền nạp phải từ 10.000 đến 50.000.000 VNĐ." });
  const bankId = text(paymentSettings.vietqr_bank, 20);
  const accountNo = text(paymentSettings.vietqr_account_no, 40);
  const accountName = text(paymentSettings.vietqr_account_name, 120);
  if (!bankId || !accountNo || !accountName) return res.status(503).json({ error: "Chưa cấu hình thông tin nhận thanh toán VietQR." });
  const depositId = "DEP-" + crypto.randomUUID().slice(0, 12).toUpperCase();
  const transferContent = "HENDY " + userId + " " + depositId;
  await executeD1Query(
    "INSERT INTO deposits(id,telegram_id,amount,transfer_content,bank_id,account_no,account_name) VALUES(?,?,?,?,?,?,?)",
    [depositId, userId, amount, transferContent, bankId, accountNo, accountName]
  );
  const qrUrl = "https://img.vietqr.io/image/" + encodeURIComponent(bankId) + "-" + encodeURIComponent(accountNo) +
    "-compact2.png?amount=" + amount + "&addInfo=" + encodeURIComponent(transferContent) +
    "&accountName=" + encodeURIComponent(accountName);
  res.status(201).json({
    success: true,
    deposit: { id: depositId, amount, transferContent, status: "pending" },
    request: { id: depositId, amount, reference: transferContent, status: "pending" },
    vietQrUrl,
    transfer: { amount, reference: transferContent, qrUrl, bankId, accountNo, accountName }
  });
}));

router.post("/giftcodes/redeem", asyncRoute(async (req, res) => {
  const userId = String(req.telegramUser.id);
  await ensureUser(req.telegramUser);
  const code = text(req.body?.code, 80).toUpperCase();
  if (!code) return res.status(400).json({ error: "Vui lòng nhập giftcode." });
  const inserted = await d1First(
    "INSERT INTO giftcode_redemptions(id,code,telegram_id) " +
    "SELECT ?,code,? FROM giftcodes WHERE code=? AND status='active' AND used_count<max_uses " +
    "AND (expires_at IS NULL OR expires_at>datetime('now')) " +
    "AND NOT EXISTS(SELECT 1 FROM giftcode_redemptions WHERE code=? AND telegram_id=?) RETURNING code",
    [crypto.randomUUID(), userId, code, code, userId]
  );
  if (!inserted) return res.status(400).json({ error: "Giftcode không hợp lệ, đã hết lượt hoặc bạn đã sử dụng." });
  const gift = await d1First("SELECT amount FROM giftcodes WHERE code=?", [code]);
  res.json({ success: true, code, amount: Number(gift?.amount || 0), rewardVoucher: Number(gift?.amount || 0) });
}));

router.get("/deposits", requireAdmin, asyncRoute(async (req, res) => {
  const filter = text(req.query.filter || req.query.status || "all", 20);
  const rows = await executeD1Query(
    "SELECT d.*,u.username,u.first_name,u.last_name FROM deposits d JOIN users u ON u.telegram_id=d.telegram_id " +
    (filter === "all" ? "" : "WHERE d.status=? ") + "ORDER BY d.created_at DESC LIMIT 200",
    filter === "all" ? [] : [filter]
  );
  res.json({ deposits: rows.results });
}));

router.post("/deposits/:id/review", requireAdmin, asyncRoute(async (req, res) => {
  const decision = text(req.body?.decision, 20);
  if (!["approved", "rejected"].includes(decision)) return res.status(400).json({ error: "Quyết định không hợp lệ." });
  const updated = await d1First(
    "UPDATE deposits SET status=?,admin_note=?,reviewed_at=datetime('now'),reviewer_id=? " +
    "WHERE id=? AND status='pending' RETURNING id,telegram_id,amount,status",
    [decision, text(req.body?.note, 500), String(req.telegramUser.id), text(req.params.id, 100)]
  );
  if (!updated) return res.status(409).json({ error: "Không tìm thấy yêu cầu hoặc yêu cầu đã được xử lý." });
  await writeAdminAudit(req.telegramUser.id, "deposit.review", "deposit", updated.id, { decision });
  res.json({ success: true, deposit: updated });
}));

router.get("/giftcodes", requireAdmin, asyncRoute(async (_req, res) => {
  const rows = await executeD1Query("SELECT * FROM giftcodes ORDER BY created_at DESC LIMIT 200");
  res.json({ giftcodes: rows.results.map((row) => ({
    ...row,
    rewardVoucher: Number(row.amount || 0),
    maxRedemptions: Number(row.max_uses || 1),
    actualRedemptions: Number(row.used_count || 0),
    isActive: row.status === "active",
    expiresAt: row.expires_at
  })) });
}));

router.post("/giftcodes", requireAdmin, asyncRoute(async (req, res) => {
  const prefix = text(req.body?.codePrefix || "HENDY", 20).toUpperCase().replace(/[^A-Z0-9]/g, "");
  const amount = number(req.body?.amountVnd || req.body?.amount, 0);
  const maxUses = Math.max(1, Math.min(10000, number(req.body?.maxUses, 1)));
  if (!prefix || amount < 1) return res.status(400).json({ error: "Tiền thưởng và tiền tố mã không hợp lệ." });
  const code = prefix + "-" + crypto.randomBytes(5).toString("hex").toUpperCase();
  const expiresAt = text(req.body?.expiresAt, 40) || null;
  await executeD1Query(
    "INSERT INTO giftcodes(code,amount,max_uses,expires_at,created_by) VALUES(?,?,?,?,?)",
    [code, amount, maxUses, expiresAt, String(req.telegramUser.id)]
  );
  await writeAdminAudit(req.telegramUser.id, "giftcode.create", "giftcode", code, { amount, maxUses });
  res.status(201).json({ code, amount, rewardVoucher: amount, max_uses: maxUses, maxRedemptions: maxUses, used_count: 0, actualRedemptions: 0, status: "active", isActive: true, expires_at: expiresAt, expiresAt });
}));

router.get("/admin/stats", requireAdmin, asyncRoute(async (_req, res) => {
  const started = Date.now();
  const stats = await d1First(
    "SELECT (SELECT COUNT(*) FROM users) AS users," +
    "(SELECT COUNT(*) FROM app_releases WHERE is_active=1) AS releases," +
    "(SELECT COUNT(*) FROM orders) AS total_orders," +
    "(SELECT COUNT(*) FROM orders WHERE status='pending') AS pending_orders," +
    "(SELECT COUNT(*) FROM orders WHERE status='processing') AS processing_orders," +
    "(SELECT COUNT(*) FROM orders WHERE status='completed') AS completed_orders," +
    "(SELECT COUNT(*) FROM deposits WHERE status='pending') AS pending_deposits," +
    "(SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending')) AS open_tickets," +
    "(SELECT COUNT(*) FROM bot_events) AS total_updates," +
    "(SELECT COALESCE(SUM(amount),0) FROM orders WHERE payment_status='paid' AND status<>'cancelled') AS total_revenue," +
    "(SELECT COUNT(DISTINCT telegram_user_id) FROM bot_events WHERE telegram_user_id IS NOT NULL AND created_at>=datetime('now','-1 day')) AS active_users_24h," +
    "(SELECT COUNT(*) FROM bot_events WHERE created_at>=datetime('now','-1 day')) AS updates_24h," +
    "(SELECT COUNT(*) FROM orders WHERE created_at>=datetime('now','-1 day')) AS orders_24h," +
    "(SELECT MAX(created_at) FROM bot_events) AS last_bot_event_at," +
    "(SELECT COALESCE(SUM(amount_delta),0) FROM wallet_ledger) AS ledger_net"
  );
  const settings = await getBotSettings();
  const d1Health = await getD1Health();
  res.json({
    ...stats,
    stats: {
      users: Number(stats?.users || 0),
      releases: Number(stats?.releases || 0),
      orderStats: {
        total: Number(stats?.total_orders || 0),
        pending: Number(stats?.pending_orders || 0),
        processing: Number(stats?.processing_orders || 0),
        completed: Number(stats?.completed_orders || 0)
      }
    },
    totalUsers: Number(stats?.users || 0),
    totalRevenue: Number(stats?.total_revenue || 0),
    activeUsers24h: Number(stats?.active_users_24h || 0),
    updates24h: Number(stats?.updates_24h || 0),
    orders24h: Number(stats?.orders_24h || 0),
    lastBotEventAt: stats?.last_bot_event_at || null,
    orderStats: {
      total: Number(stats?.total_orders || 0),
      pending: Number(stats?.pending_orders || 0),
      processing: Number(stats?.processing_orders || 0),
      completed: Number(stats?.completed_orders || 0)
    },
    d1Latency: d1Health.latency_ms + " ms",
    d1Status: d1Health.status,
    d1TotalQueries: d1Health.total_queries,
    d1Queries: d1Health.total_queries,
    d1ServedBy: d1Health.served_by,
    d1Database: d1Health.database,
    maintenanceMode: settings.maintenance_mode,
    maintenanceMessage: settings.maintenance_message,
    generatedAt: new Date().toISOString(),
    requestDurationMs: Date.now() - started
  });
}));

router.get("/admin/settings", requireAdmin, asyncRoute(async (_req, res) => {
  res.json({ settings: await getBotSettings() });
}));

router.post("/admin/settings", requireAdmin, asyncRoute(async (req, res) => {
  const allowedText = [
    "welcome_message", "support_telegram", "language",
    "vietqr_bank", "vietqr_account_no", "vietqr_account_name"
  ];
  const allowedLinks = ["group_chat_url", "news_channel_url", "support_url"];
  const updates = req.body && typeof req.body === "object" ? req.body : {};
  const adminId = String(req.telegramUser.id);

  for (const key of allowedText) {
    if (updates[key] !== undefined) {
      const value = text(updates[key], key === "welcome_message" ? 2000 : key === "vietqr_account_name" ? 120 : 100);
      if (key === "vietqr_bank" && value && !/^[A-Z0-9_-]{2,20}$/i.test(value)) return res.status(400).json({ error: "VIETQR_BANK_ID không hợp lệ." });
      if (key === "vietqr_account_no" && value && !/^[0-9]{4,30}$/.test(value)) return res.status(400).json({ error: "VIETQR_ACCOUNT_NO phải có 4–30 chữ số." });
      await setBotSetting(key, value, adminId);
    }
  }

  for (const key of allowedLinks) {
    if (updates[key] !== undefined) {
      let value;
      try { value = validateTelegramLink(updates[key], key.toUpperCase()); }
      catch (error) { return res.status(400).json({ error: error.message }); }
      await setBotSetting(key, value, adminId);
    }
  }

  if (updates.ui_theme !== undefined) {
    let theme;
    try { theme = validateTheme(updates.ui_theme); }
    catch (error) { return res.status(400).json({ error: error.message }); }
    await setBotSetting("ui_theme", JSON.stringify(theme), adminId);
  }

  if (updates.menu_config !== undefined) {
    let menu;
    try { menu = validateMenuConfig(updates.menu_config); }
    catch (error) { return res.status(400).json({ error: error.message }); }
    await setBotSetting("menu_config", JSON.stringify(menu), adminId);
  }

  if (updates.maintenance_message !== undefined) {
    await setBotSetting("maintenance_message", text(updates.maintenance_message, 1000), adminId);
  }

  const settings = await getBotSettings();
  await writeAdminAudit(adminId, "bot.settings.update", "settings", null, {
    keys: Object.keys(updates).filter((key) => [...allowedText, ...allowedLinks, "ui_theme", "menu_config", "maintenance_message"].includes(key))
  });
  let menuRefresh = { updated: 0, failed: 0, total: 0 };
  if (updates.menu_config !== undefined || allowedLinks.some((key) => updates[key] !== undefined)) {
    try {
      menuRefresh = await refreshTelegramMenuMessages(settings);
    } catch (error) {
      // D1 setting changes remain committed even if the optional Telegram menu-history table is not migrated yet.
      menuRefresh = { updated: 0, failed: 0, total: 0, warning: "Không thể đồng bộ menu đã gửi: " + error.message };
    }
  }
  await broadcastPublicConfigUpdate(settings);
  broadcastRealtime("admin.settings.updated", {
    updatedKeys: Object.keys(updates),
    telegramMenus: menuRefresh
  }, { adminOnly: true });
  res.json({ success: true, settings, menuRefresh });
}));

function validateBotMenuPayload(value) {
  const allowed = ["mini_game_live","app_tool","buy_code","wallet","otp_proxy","vip_tools","support","pairing","referral","giftcode"];
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("Cấu hình menu không hợp lệ.");
  if (!["reply_keyboard","inline_keyboard"].includes(value.menu_mode)) throw new Error("Kiểu menu không hợp lệ.");
  const columns = Number(value.menu_columns);
  if (!Number.isInteger(columns) || columns < 1 || columns > 3) throw new Error("Số cột menu phải từ 1 đến 3.");
  const raw = value.button_config && typeof value.button_config === "object" && !Array.isArray(value.button_config) ? value.button_config : {};
  const button_config = {};
  for (const module of allowed) {
    const item = raw[module] || {};
    const label = text(item.label,48);
    if (!label) throw new Error("Nhãn nút " + module + " không được để trống.");
    button_config[module] = {
      label, description: text(item.description,160),
      style: ["primary","success","danger"].includes(item.style) ? item.style : "primary",
      enabled: item.enabled !== false
    };
  }
  return {
    menu_mode:value.menu_mode, intro_text:text(value.intro_text,1000) || "Chào mừng bạn đến với Hendy Bot.",
    menu_columns:columns, persistent_keyboard:value.persistent_keyboard !== false,
    resize_keyboard:value.resize_keyboard !== false, button_config
  };
}
router.get("/admin/bot-menu", requireAdmin, asyncRoute(async (_req,res) => {
  const [primary,secondary] = await Promise.all([getBotSettings("primary"),getBotSettings("secondary")]);
  res.json({
    bots:[
      {botKey:"primary",configured:Boolean(process.env.BOT_TOKEN),username:process.env.BOT_USERNAME || ""},
      {botKey:"secondary",configured:Boolean(process.env.BOT_SECOND_TOKEN),username:process.env.BOT_SECOND_USERNAME || ""}
    ],
    configs:{primary:primary.bot_menu,secondary:secondary.bot_menu}
  });
}));
router.post("/admin/bot-menu", requireAdmin, asyncRoute(async (req,res) => {
  const target = text(req.body?.botKey,20);
  if (!["primary","secondary"].includes(target)) return res.status(400).json({error:"Bot cần cập nhật không hợp lệ."});
  let config;
  try { config = validateBotMenuPayload(req.body?.config); }
  catch (error) { return res.status(400).json({error:error.message}); }
  const adminId = String(req.telegramUser.id);
  await setTelegramBotConfig(target,config,adminId);
  const settings = await getBotSettings(target);
  let menuRefresh = {botKey:target,updated:0,failed:0,total:0,skipped:false};
  try { menuRefresh = await refreshTelegramMenuMessages(settings,target); }
  catch (error) { menuRefresh.warning = "Đã lưu cấu hình nhưng chưa đồng bộ menu đã gửi: " + error.message; }
  await writeAdminAudit(adminId,"telegram.bot_menu.update","telegram_bot",target,{menuMode:config.menu_mode,columns:config.menu_columns});
  broadcastRealtime("telegram.bot_menu.updated",{botKey:target,menuRefresh},{adminOnly:true});
  await broadcastPublicConfigUpdate(settings);
  res.json({success:true,config:settings.bot_menu,menuRefresh});
}));

router.get("/admin/config/registry", requireAdmin, asyncRoute(async (_req, res) => {
  res.json(await scanConfigurationLocations());
}));

router.get("/admin/runtime-config/status", requireAdmin, asyncRoute(async (_req, res) => {
  const rows = await executeD1Query(
    "SELECT key,value FROM bot_settings WHERE key IN ('runtime_admin_webapp_url','runtime_allowed_origins','runtime_vite_api_base_url')"
  );
  const values = Object.fromEntries(rows.results.map((row) => [row.key, row.value]));
  res.json({
    integration: getRuntimeConfigStatus(),
    current: {
      adminWebappUrl: values.runtime_admin_webapp_url || process.env.ADMIN_WEBAPP_URL || "",
      allowedOrigins: values.runtime_allowed_origins || process.env.ALLOWED_ORIGINS || "",
      viteApiBaseUrl: values.runtime_vite_api_base_url || ""
    }
  });
}));

router.post("/admin/runtime-config/apply", requireAdmin, asyncRoute(async (req, res) => {
  const input = req.body && typeof req.body === "object" ? req.body : {};
  const result = await syncRuntimeConfig(input);
  const adminId = String(req.telegramUser.id);
  if (input.adminWebappUrl) await setBotSetting("runtime_admin_webapp_url", String(input.adminWebappUrl).trim(), adminId);
  if (input.allowedOrigins) await setBotSetting("runtime_allowed_origins", String(input.allowedOrigins).trim(), adminId);
  if (input.viteApiBaseUrl) await setBotSetting("runtime_vite_api_base_url", String(input.viteApiBaseUrl).trim(), adminId);
  await writeAdminAudit(adminId, "runtime.config.sync", "deployment", null, {
    keys: result.updatedKeys,
    renderDeploy: Boolean(result.deployments.render),
    cloudflareWorkflow: Boolean(result.deployments.cloudflareWorkflow)
  });
  broadcastRealtime("runtime_config.updated", {
    updatedKeys: result.updatedKeys,
    deployments: result.deployments
  }, { adminOnly: true });
  res.json(result);
}));

router.post("/admin/maintenance", requireAdmin, asyncRoute(async (req, res) => {
  const enabled = req.body?.enabled === true || req.body?.enabled === "true" || req.body?.enabled === 1;
  if (req.body?.message !== undefined) await setBotSetting("maintenance_message", text(req.body.message, 1000), String(req.telegramUser.id));
  await setBotSetting("maintenance_mode", enabled ? "1" : "0", String(req.telegramUser.id));
  await writeAdminAudit(req.telegramUser.id, "bot.maintenance.toggle", "settings", "maintenance_mode", { enabled });
  const settings = await getBotSettings();
  await broadcastPublicConfigUpdate(settings);
  res.json({ success: true, maintenance_mode: settings.maintenance_mode, settings });
}));

router.get("/admin/orders", requireAdmin, asyncRoute(async (req, res) => {
  const status = text(req.query.status || "all", 30);
  const module = text(req.query.module || "all", 80);
  const query = text(req.query.q || "", 100);
  const limit = Math.max(1, Math.min(200, number(req.query.limit, 100)));
  const clauses = [];
  const params = [];
  if (status !== "all") { clauses.push("o.status=?"); params.push(status); }
  if (module !== "all") { clauses.push("o.module=?"); params.push(module); }
  if (query) { clauses.push("(o.id LIKE ? OR o.title LIKE ? OR u.username LIKE ? OR u.first_name LIKE ?)"); params.push("%" + query + "%", "%" + query + "%", "%" + query + "%", "%" + query + "%"); }
  const where = clauses.length ? "WHERE " + clauses.join(" AND ") : "";
  const rows = await executeD1Query(
    "SELECT o.*,u.username,u.first_name,u.last_name FROM orders o JOIN users u ON u.telegram_id=o.telegram_id " +
    where + " ORDER BY o.created_at DESC LIMIT " + limit,
    params
  );
  const totals = await d1First(
    "SELECT COUNT(*) AS total," +
    "SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending," +
    "SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed," +
    "SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS processing," +
    "SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed," +
    "SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled FROM orders"
  );
  res.json({
    orders: rows.results.map(formatOrder),
    total: Number(totals?.total || 0),
    stats: {
      total: Number(totals?.total || 0),
      pending: Number(totals?.pending || 0),
      confirmed: Number(totals?.confirmed || 0),
      processing: Number(totals?.processing || 0),
      completed: Number(totals?.completed || 0),
      cancelled: Number(totals?.cancelled || 0)
    }
  });
}));


router.get("/admin/releases", requireAdmin, asyncRoute(async (_req, res) => {
  const rows = await executeD1Query("SELECT * FROM app_releases ORDER BY created_at DESC LIMIT 200");
  res.json({ releases: rows.results });
}));

router.post("/admin/releases", requireAdmin, releaseUpload.single("file"), asyncRoute(async (req, res) => {
  if (!req.file) return res.status(400).json({ error: "Vui lòng chọn tệp phát hành." });
  const platform = text(req.body?.platform, 20);
  const version = text(req.body?.version, 40);
  const notes = text(req.body?.releaseNotes || req.body?.notes, 2000);
  if (!["Android", "Windows", "macOS", "Linux", "Other"].includes(platform)) return res.status(400).json({ error: "Nền tảng không hợp lệ." });
  if (!/^[0-9A-Za-z.+_-]{1,40}$/.test(version)) return res.status(400).json({ error: "Phiên bản không hợp lệ." });
  const originalName = String(req.file.originalname || "release.bin");
  const extension = originalName.toLowerCase().match(/\.(apk|zip|exe|msi|dmg|pkg|deb|rpm|tar\.gz)$/)?.[0];
  if (!extension) return res.status(400).json({ error: "Chỉ chấp nhận APK, ZIP, EXE, MSI, DMG, PKG, DEB, RPM hoặc TAR.GZ." });

  const workerBase = process.env.R2_UPLOAD_WORKER_URL || process.env.BACKUP_WORKER_URL;
  const uploadSecret = process.env.R2_UPLOAD_SECRET;
  if (!workerBase || !uploadSecret) return res.status(503).json({ error: "Chưa cấu hình R2_UPLOAD_WORKER_URL và R2_UPLOAD_SECRET trên Render." });
  const safeName = originalName.normalize("NFKD").replace(/[^a-zA-Z0-9._-]+/g, "-").slice(-100) || "release" + extension;
  const form = new FormData();
  form.set("file", new Blob([req.file.buffer], { type: req.file.mimetype || "application/octet-stream" }), safeName);
  form.set("platform", platform);
  form.set("version", version);
  form.set("filename", safeName);
  const uploadUrl = new URL("/api/storage/upload", workerBase);
  const uploaded = await fetch(uploadUrl, {
    method: "POST",
    headers: { Authorization: "Bearer " + uploadSecret },
    body: form,
    signal: AbortSignal.timeout(60000)
  });
  const uploadResult = await uploaded.json().catch(() => ({}));
  if (!uploaded.ok || !uploadResult.success || !uploadResult.key) {
    return res.status(502).json({ error: uploadResult.error || "Worker không lưu được tệp lên R2." });
  }

  const releaseId = "REL-" + crypto.randomUUID().slice(0, 12).toUpperCase();
  const downloadUrl = new URL("/api/storage/download/" + uploadResult.key.split("/").map(encodeURIComponent).join("/"), workerBase).toString();
  await executeD1Query(
    "INSERT INTO app_releases(id,title,version,platform,download_url,notes,is_active) VALUES(?,?,?,?,?,?,1)",
    [releaseId, safeName, version, platform, downloadUrl, notes]
  );
  await writeAdminAudit(req.telegramUser.id, "release.publish", "release", releaseId, {
    platform, version, filename: safeName, bytes: uploadResult.size, objectKey: uploadResult.key
  });
  const release = await d1First("SELECT * FROM app_releases WHERE id=?", [releaseId]);
  res.status(201).json({ success: true, release });
}));

router.post("/admin/releases/:id/status", requireAdmin, asyncRoute(async (req, res) => {
  const enabled = req.body?.active === true || req.body?.active === 1 || req.body?.active === "true";
  const release = await d1First("UPDATE app_releases SET is_active=? WHERE id=? RETURNING *", [enabled ? 1 : 0, text(req.params.id, 100)]);
  if (!release) return res.status(404).json({ error: "Không tìm thấy bản phát hành." });
  await writeAdminAudit(req.telegramUser.id, "release.status", "release", release.id, { active: enabled });
  res.json({ success: true, release });
}));

router.get("/admin/catalog", requireAdmin, asyncRoute(async (_req, res) => {
  const rows = await executeD1Query("SELECT * FROM catalog_products ORDER BY sort_order,created_at DESC");
  res.json({ items: rows.results });
}));

router.post("/admin/catalog", requireAdmin, asyncRoute(async (req, res) => {
  const title = text(req.body?.title, 180);
  const slug = text(req.body?.slug || title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""), 180);
  if (!title || !slug) return res.status(400).json({ error: "Tên sản phẩm và slug là bắt buộc." });
  const productId = "PRD-" + crypto.randomUUID().slice(0, 12).toUpperCase();
  const price = Math.max(0, number(req.body?.unitPrice ?? req.body?.price, 0));
  await executeD1Query(
    "INSERT INTO catalog_products(id,title,slug,category,module,description,tagline,icon,version,price,unit_price,sort_order,is_active) " +
    "VALUES(?,?,?,?,?,?,?,?,?,?,?, ?,1)",
    [productId, title, slug, text(req.body?.category || req.body?.module || "tools", 80),
      text(req.body?.module || "app_tool", 80), text(req.body?.description, 2000),
      text(req.body?.tagline, 500), text(req.body?.icon || "📦", 8), text(req.body?.version || "1.0.0", 40),
      price, price, number(req.body?.sortOrder, 100)]
  );
  await writeAdminAudit(req.telegramUser.id, "catalog.create", "product", productId, { title, price });
  res.status(201).json({ item: await d1First("SELECT * FROM catalog_products WHERE id=?", [productId]) });
}));

router.put("/admin/catalog/:id", requireAdmin, asyncRoute(async (req, res) => {
  const updated = await d1First(
    "UPDATE catalog_products SET title=?,description=?,unit_price=?,price=?,is_active=?,updated_at=datetime('now') " +
    "WHERE id=? RETURNING *",
    [text(req.body?.title, 180), text(req.body?.description, 2000),
      Math.max(0, number(req.body?.unitPrice ?? req.body?.price, 0)),
      Math.max(0, number(req.body?.unitPrice ?? req.body?.price, 0)),
      req.body?.is_active === 0 ? 0 : 1, text(req.params.id, 100)]
  );
  if (!updated) return res.status(404).json({ error: "Không tìm thấy sản phẩm." });
  await writeAdminAudit(req.telegramUser.id, "catalog.update", "product", updated.id);
  res.json({ item: updated });
}));

router.post("/admin/catalog/:id/:action", requireAdmin, asyncRoute(async (req, res) => {
  if (!["activate", "deactivate"].includes(req.params.action)) return res.status(404).json({ error: "Không tìm thấy thao tác." });
  const active = req.params.action === "activate" ? 1 : 0;
  const updated = await d1First("UPDATE catalog_products SET is_active=?,updated_at=datetime('now') WHERE id=? RETURNING id,is_active", [active, text(req.params.id, 100)]);
  if (!updated) return res.status(404).json({ error: "Không tìm thấy sản phẩm." });
  await writeAdminAudit(req.telegramUser.id, "catalog.status", "product", updated.id, { is_active: active });
  res.json({ success: true, item: updated });
}));

router.get("/admin/support/tickets", requireAdmin, asyncRoute(async (req, res) => {
  const status = text(req.query.status || "all", 30);
  const rows = await executeD1Query(
    "SELECT t.*,u.username,u.first_name,u.last_name FROM support_tickets t JOIN users u ON u.telegram_id=t.telegram_id " +
    (status === "all" ? "" : "WHERE t.status=? ") + "ORDER BY t.updated_at DESC LIMIT 200",
    status === "all" ? [] : [status]
  );
  res.json({ tickets: rows.results });
}));

router.post("/admin/support/tickets/:id/status", requireAdmin, asyncRoute(async (req, res) => {
  const status = text(req.body?.status, 30);
  if (!["open", "pending", "resolved", "closed"].includes(status)) return res.status(400).json({ error: "Trạng thái ticket không hợp lệ." });
  const updated = await d1First("UPDATE support_tickets SET status=?,updated_at=datetime('now') WHERE id=? RETURNING id,status", [status, text(req.params.id, 100)]);
  if (!updated) return res.status(404).json({ error: "Không tìm thấy ticket." });
  await writeAdminAudit(req.telegramUser.id, "support.ticket.status", "ticket", updated.id, { status });
  res.json({ success: true, status: updated.status, ticket: updated });
}));

router.get("/admin/telegram/webhook-info", requireAdmin, asyncRoute(async (_req, res) => {
  const token = process.env.BOT_TOKEN;
  if (!token) return res.status(503).json({ error: "BOT_TOKEN chưa được cấu hình." });
  const [webhookResponse, meResponse] = await Promise.all([
    fetch("https://api.telegram.org/bot" + token + "/getWebhookInfo", { signal: AbortSignal.timeout(10000) }),
    fetch("https://api.telegram.org/bot" + token + "/getMe", { signal: AbortSignal.timeout(10000) })
  ]);
  const webhookInfo = await webhookResponse.json();
  const botMe = await meResponse.json();
  if (!webhookResponse.ok || !webhookInfo.ok || !meResponse.ok || !botMe.ok) return res.status(502).json({ error: "Không thể lấy trạng thái Telegram Bot." });
  res.json({ webhookInfo, botMe });
}));

router.post("/admin/telegram/set-webhook", requireAdmin, asyncRoute(async (req, res) => {
  const base = process.env.BASE_URL || process.env.RENDER_EXTERNAL_URL;
  const token = process.env.BOT_TOKEN;
  const secret = process.env.WEBHOOK_SECRET;
  if (!base || !token || !secret) return res.status(503).json({ error: "Thiếu BASE_URL, BOT_TOKEN hoặc WEBHOOK_SECRET." });
  const expectedUrl = new URL("/api/telegram/webhook", base).toString();
  const requested = text(req.body?.webhookUrl || expectedUrl, 500);
  if (requested !== expectedUrl || !expectedUrl.startsWith("https://")) return res.status(400).json({ error: "Webhook URL phải trùng chính xác endpoint HTTPS của Render." });
  const result = await sendTelegram("setWebhook", {
    url: expectedUrl,
    secret_token: secret,
    allowed_updates: ["message", "edited_message", "callback_query"]
  });
  await writeAdminAudit(req.telegramUser.id, "telegram.webhook.set", "telegram", null, { webhookUrl: expectedUrl });
  res.json({ success: true, result, targetUrl: expectedUrl });
}));

router.post("/admin/d1/query", requireAdmin, asyncRoute(async (req, res) => {
  const sql = text(req.body?.sql, 2048).replace(/;\s*$/, "");
  if (!/^select\b/i.test(sql) || sql.includes(";") || /--|\/\*|\b(insert|update|delete|drop|alter|pragma|attach|detach|replace|create|vacuum)\b/i.test(sql)) {
    return res.status(400).json({ error: "Console chỉ cho phép một câu lệnh SELECT chỉ đọc, không có dấu chấm phẩy hay comment." });
  }
  const result = await executeD1Query(sql, Array.isArray(req.body?.params) ? req.body.params.slice(0, 50) : []);
  res.json(result);
}));

router.get("/admin/d1/health", requireAdmin, asyncRoute(async (_req, res) => {
  res.json(await getD1Health());
}));

router.get("/admin/backups", requireAdmin, asyncRoute(async (_req, res) => {
  const rows = await executeD1Query(
    "SELECT id,status,object_prefix,table_count,row_count,error_message,started_at,finished_at " +
    "FROM backup_runs ORDER BY started_at DESC LIMIT 50"
  );
  res.json({ backups: rows.results });
}));

router.post("/admin/backup", requireAdmin, asyncRoute(async (_req, res) => {
  const workerBase = process.env.BACKUP_WORKER_URL;
  const secret = process.env.BACKUP_TRIGGER_SECRET;
  if (!workerBase || !secret) return res.status(503).json({ error: "Chưa cấu hình BACKUP_WORKER_URL và BACKUP_TRIGGER_SECRET." });
  const endpoint = new URL("/api/backups/run", workerBase);
  const response = await fetch(endpoint, {
    method: "POST",
    headers: { Authorization: "Bearer " + secret, "Content-Type": "application/json" },
    body: JSON.stringify({ requestedBy: String(req.telegramUser.id) }),
    signal: AbortSignal.timeout(60000)
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) return res.status(502).json({ error: payload.error || "Worker sao lưu báo lỗi." });
  await writeAdminAudit(req.telegramUser.id, "backup.manual", "r2", null, payload);
  res.json({ success: true, backup: payload });
}));

router.post("/admin/catalog/package-deploy", requireAdmin, async (_req, res) => {
  res.status(501).json({ error: "Chức năng đóng gói cần luồng tải tệp trực tiếp vào R2 đã ký. Không ghi metadata giả hoặc báo thành công khi chưa lưu tệp." });
});

// Compatibility endpoints for the separately hosted admin and user frontends.


router.get("/links", asyncRoute(async (req, res) => {
  const rows = await executeD1Query("SELECT id,brand,account_label AS tk FROM user_links WHERE telegram_id=? ORDER BY created_at DESC", [String(req.telegramUser.id)]);
  const links = {};
  for (const row of rows.results) {
    if (!links[row.brand]) links[row.brand] = [];
    links[row.brand].push(row);
  }
  res.json({ links });
}));

router.post("/links", asyncRoute(async (req, res) => {
  const brand = text(req.body?.brand, 80);
  const label = text(req.body?.accountLabel, 180);
  if (!brand || !label) return res.status(400).json({ error: "Thiếu thương hiệu hoặc tài khoản." });
  await executeD1Query("INSERT INTO user_links(id,telegram_id,brand,account_label) VALUES(?,?,?,?) ON CONFLICT(telegram_id,brand,account_label) DO NOTHING", [crypto.randomUUID(), String(req.telegramUser.id), brand, label]);
  const rows = await executeD1Query("SELECT id,brand,account_label AS tk FROM user_links WHERE telegram_id=? ORDER BY created_at DESC", [String(req.telegramUser.id)]);
  const links = {};
  for (const row of rows.results) {
    if (!links[row.brand]) links[row.brand] = [];
    links[row.brand].push(row);
  }
  res.json({ links });
}));

router.delete("/links/:brand/:linkId", asyncRoute(async (req, res) => {
  const brand = text(req.params.brand, 80);
  const userId = String(req.telegramUser.id);
  let linkId = text(req.params.linkId, 100);
  if (/^\d+$/.test(linkId)) {
    const indexed = await d1First(
      "SELECT id FROM user_links WHERE telegram_id=? AND brand=? ORDER BY created_at DESC LIMIT 1 OFFSET ?",
      [userId, brand, Number(linkId)]
    );
    linkId = indexed?.id || "";
  }
  if (linkId) await executeD1Query("DELETE FROM user_links WHERE id=? AND brand=? AND telegram_id=?", [linkId, brand, userId]);
  const rows = await executeD1Query("SELECT id,brand,account_label AS tk FROM user_links WHERE telegram_id=? ORDER BY created_at DESC", [userId]);
  const links = {};
  for (const row of rows.results) {
    if (!links[row.brand]) links[row.brand] = [];
    links[row.brand].push(row);
  }
  res.json({ success: true, links });
}));

router.get("/admin/deposits", requireAdmin, asyncRoute(async (req, res) => {
  const status = text(req.query.status || "all", 20);
  const rows = await executeD1Query(
    "SELECT d.*,u.username,u.first_name,u.last_name FROM deposits d JOIN users u ON u.telegram_id=d.telegram_id " +
    (status === "all" ? "" : "WHERE d.status=? ") + "ORDER BY d.created_at DESC LIMIT 200",
    status === "all" ? [] : [status]
  );
  res.json({ deposits: rows.results });
}));

router.get("/admin/orders/:id", requireAdmin, asyncRoute(async (req, res) => {
  const order = await d1First("SELECT o.*,u.username,u.first_name,u.last_name FROM orders o JOIN users u ON u.telegram_id=o.telegram_id WHERE o.id=?", [text(req.params.id, 100)]);
  if (!order) return res.status(404).json({ error: "Không tìm thấy đơn hàng." });
  res.json({ order: formatOrder(order), events: [] });
}));

router.post("/admin/orders/:id/status", requireAdmin, asyncRoute(async (req, res) => {
  const allowed = ["pending", "confirmed", "processing", "completed", "cancelled"];
  const status = text(req.body?.status, 30);
  if (!allowed.includes(status)) return res.status(400).json({ error: "Trạng thái đơn hàng không hợp lệ." });
  const updated = await d1First("UPDATE orders SET status=?,updated_at=datetime('now') WHERE id=? RETURNING id,status", [status, text(req.params.id, 100)]);
  if (!updated) return res.status(404).json({ error: "Không tìm thấy đơn hàng." });
  await writeAdminAudit(req.telegramUser.id, "order.status.update", "order", updated.id, { status });
  const current = await d1First("SELECT * FROM orders WHERE id=?", [updated.id]);
  res.json({ order: formatOrder(current), events: [] });
}));

router.post("/admin/orders/:id/quote", requireAdmin, asyncRoute(async (req, res) => {
  const amount = number(req.body?.amount, -1);
  if (amount < 1) return res.status(400).json({ error: "Giá báo phải là số nguyên VND dương." });
  const existing = await d1First("SELECT * FROM orders WHERE id=?", [text(req.params.id, 100)]);
  if (!existing) return res.status(404).json({ error: "Không tìm thấy đơn hàng." });
  if (existing.payment_status === "paid") return res.status(409).json({ error: "Đơn hàng đã thanh toán, không thể thay đổi báo giá." });
  let details = {};
  try { details = JSON.parse(existing.details_json || "{}"); } catch {}
  const quote = {
    id: crypto.randomUUID(),
    amount,
    note: text(req.body?.note, 1000),
    status: "sent",
    createdAt: new Date().toISOString()
  };
  details.quote = quote;
  await executeD1Query("UPDATE orders SET details_json=?,updated_at=datetime('now') WHERE id=?", [JSON.stringify(details), existing.id]);
  const updated = await d1First("SELECT * FROM orders WHERE id=?", [existing.id]);
  await writeAdminAudit(req.telegramUser.id, "order.quote", "order", existing.id, { amount, quoteId: quote.id });
  res.json({ order: formatOrder(updated), events: [] });
}));

router.post("/admin/deposits/:id/review", requireAdmin, asyncRoute(async (req, res) => {
  const decision = text(req.body?.status || req.body?.decision, 20);
  if (!["approved", "rejected"].includes(decision)) return res.status(400).json({ error: "Quyết định không hợp lệ." });
  const updated = await d1First(
    "UPDATE deposits SET status=?,admin_note=?,reviewed_at=datetime('now'),reviewer_id=? WHERE id=? AND status='pending' RETURNING id,telegram_id,amount,status",
    [decision, text(req.body?.note, 500), String(req.telegramUser.id), text(req.params.id, 100)]
  );
  if (!updated) return res.status(409).json({ error: "Không tìm thấy yêu cầu hoặc đã được xử lý." });
  await writeAdminAudit(req.telegramUser.id, "deposit.review", "deposit", updated.id, { decision });
  res.json({ success: true, deposit: updated });
}));

router.get("/admin/giftcodes", requireAdmin, asyncRoute(async (_req, res) => {
  const rows = await executeD1Query("SELECT * FROM giftcodes ORDER BY created_at DESC LIMIT 200");
  res.json({ giftcodes: rows.results });
}));

router.post("/admin/giftcodes", requireAdmin, asyncRoute(async (req, res) => {
  const prefix = text(req.body?.codePrefix || "HENDY", 20).toUpperCase().replace(/[^A-Z0-9]/g, "");
  const amount = number(req.body?.rewardVoucher ?? req.body?.amountVnd ?? req.body?.amount, 0);
  const maxUses = Math.max(1, Math.min(10000, number(req.body?.maxUses ?? req.body?.maxRedemptions, 1)));
  if (!prefix || amount < 1) return res.status(400).json({ error: "Thông tin giftcode không hợp lệ." });
  const code = prefix + "-" + crypto.randomBytes(5).toString("hex").toUpperCase();
  const expiresAt = text(req.body?.expiresAt, 40) || null;
  await executeD1Query("INSERT INTO giftcodes(code,amount,max_uses,expires_at,created_by) VALUES(?,?,?,?,?)", [code, amount, maxUses, expiresAt, String(req.telegramUser.id)]);
  await writeAdminAudit(req.telegramUser.id, "giftcode.create", "giftcode", code, { amount, maxUses });
  res.status(201).json({ code, amount, max_uses: maxUses, used_count: 0, status: "active", expires_at: expiresAt });
}));

router.post("/admin/giftcodes/:code/:action", requireAdmin, asyncRoute(async (req, res) => {
  if (!["activate", "deactivate"].includes(req.params.action)) return res.status(404).json({ error: "Thao tác không hợp lệ." });
  const status = req.params.action === "activate" ? "active" : "revoked";
  const updated = await d1First("UPDATE giftcodes SET status=? WHERE code=? RETURNING code,status", [status, text(req.params.code, 80)]);
  if (!updated) return res.status(404).json({ error: "Không tìm thấy giftcode." });
  await writeAdminAudit(req.telegramUser.id, "giftcode.status", "giftcode", updated.code, { status });
  res.json({ success: true, giftcode: updated });
}));

router.get("/admin/support/tickets/:id", requireAdmin, asyncRoute(async (req, res) => {
  const ticket = await d1First("SELECT * FROM support_tickets WHERE id=?", [text(req.params.id, 100)]);
  if (!ticket) return res.status(404).json({ error: "Không tìm thấy ticket." });
  const messages = await executeD1Query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at", [ticket.id]);
  res.json({ ticket, messages: messages.results });
}));

router.post("/admin/support/tickets/:id/messages", requireAdmin, asyncRoute(async (req, res) => {
  const ticketId = text(req.params.id, 100);
  const message = text(req.body?.message, 4000);
  const ticket = await d1First("SELECT id FROM support_tickets WHERE id=?", [ticketId]);
  if (!ticket) return res.status(404).json({ error: "Không tìm thấy ticket." });
  if (!message) return res.status(400).json({ error: "Nội dung phản hồi không được để trống." });
  await executeD1Query("INSERT INTO support_messages(id,ticket_id,sender_telegram_id,sender_type,message) VALUES(?,?,?,'admin',?)", [crypto.randomUUID(), ticketId, String(req.telegramUser.id), message]);
  await executeD1Query("UPDATE support_tickets SET last_message=?,updated_at=datetime('now') WHERE id=?", [message, ticketId]);
  const messages = await executeD1Query("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY created_at", [ticketId]);
  res.json({ ticket, messages: messages.results });
}));

router.get("/admin/referrals/commissions", requireAdmin, asyncRoute(async (req, res) => {
  const status = text(req.query.status || "all", 20);
  const rows = await executeD1Query(
    "SELECT c.*,r.username AS referrer_username,r.first_name AS referrer_first_name," +
    "u.username AS referred_username,u.first_name AS referred_first_name " +
    "FROM referral_commissions c " +
    "LEFT JOIN users r ON r.telegram_id=c.referrer_telegram_id " +
    "LEFT JOIN users u ON u.telegram_id=c.referred_telegram_id " +
    (status === "all" ? "" : "WHERE c.status=? ") +
    "ORDER BY c.created_at DESC LIMIT 200",
    status === "all" ? [] : [status]
  );
  const counts = await d1First(
    "SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amount " +
    "FROM referral_commissions"
  );
  res.json({ commissions: rows.results, total: Number(counts?.total || 0), pendingAmount: Number(counts?.pending_amount || 0) });
}));

router.post("/admin/referrals/commissions/:id/status", requireAdmin, asyncRoute(async (req, res) => {
  const status = text(req.body?.status, 20);
  if (!["approved", "paid", "voided"].includes(status)) return res.status(400).json({ error: "Trạng thái hoa hồng không hợp lệ." });
  const updated = await d1First(
    "UPDATE referral_commissions SET status=?,admin_note=?,updated_at=datetime('now') " +
    "WHERE id=? AND status IN ('pending','approved') AND NOT (status='paid' AND ?<>'paid') RETURNING *",
    [status, text(req.body?.note, 500), text(req.params.id, 100), status]
  );
  if (!updated) return res.status(409).json({ error: "Hoa hồng không tồn tại hoặc đã được xử lý." });
  await writeAdminAudit(req.telegramUser.id, "referral.commission.status", "commission", updated.id, { status });
  res.json({ success: true, commission: updated });
}));

router.post("/admin/webhook/setup", requireAdmin, asyncRoute(async (_req, res) => {
  const base = process.env.BASE_URL || process.env.RENDER_EXTERNAL_URL;
  const expectedUrl = base ? new URL("/api/telegram/webhook", base).toString() : "";
  if (!expectedUrl || !process.env.BOT_TOKEN || !process.env.WEBHOOK_SECRET) return res.status(503).json({ error: "Thiếu cấu hình webhook trên Render." });
  const result = await sendTelegram("setWebhook", { url: expectedUrl, secret_token: process.env.WEBHOOK_SECRET, allowed_updates: ["message", "edited_message", "callback_query"] });
  res.json({ success: true, result, targetUrl: expectedUrl });
}));

export default router;
