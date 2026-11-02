import crypto from "node:crypto";

const API_BASE = "https://api.cloudflare.com/client/v4";

let queryCount = 0;

function config() {
  const accountId = process.env.CLOUDFLARE_ACCOUNT_ID;
  const databaseId = process.env.CLOUDFLARE_D1_DATABASE_ID;
  const apiToken = process.env.CLOUDFLARE_D1_API_TOKEN || process.env.CLOUDFLARE_API_TOKEN;
  if (!accountId || !databaseId || !apiToken) {
    const error = new Error("Cloudflare D1 chưa được cấu hình đầy đủ.");
    error.status = 503;
    error.code = "D1_NOT_CONFIGURED";
    throw error;
  }
  return { accountId, databaseId, apiToken };
}

export async function executeD1Query(sql, params = []) {
  if (typeof sql !== "string" || !sql.trim()) {
    throw new TypeError("Câu lệnh SQL không hợp lệ.");
  }
  if (!Array.isArray(params)) {
    throw new TypeError("Tham số SQL phải là một mảng.");
  }

  const { accountId, databaseId, apiToken } = config();
  const startedAt = Date.now();
  queryCount += 1;

  const response = await fetch(
    API_BASE + "/accounts/" + encodeURIComponent(accountId) +
      "/d1/database/" + encodeURIComponent(databaseId) + "/query",
    {
      method: "POST",
      headers: {
        Authorization: "Bearer " + apiToken,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ sql, params }),
      signal: AbortSignal.timeout(15000)
    }
  );

  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload || payload.success !== true) {
    const detail = payload?.errors?.map((item) => item.message).filter(Boolean).join("; ");
    const error = new Error(detail || "Cloudflare D1 từ chối truy vấn.");
    error.status = response.status >= 400 && response.status < 500 ? 502 : 503;
    error.code = "D1_QUERY_FAILED";
    throw error;
  }

  const first = Array.isArray(payload.result) ? payload.result[0] : null;
  return {
    success: true,
    results: Array.isArray(first?.results) ? first.results : [],
    meta: {
      duration_ms: Date.now() - startedAt,
      changes: Number(first?.meta?.changes || 0),
      served_by: "Cloudflare D1 REST API",
      database: databaseId,
      total_queries: queryCount
    }
  };
}

export async function d1First(sql, params = []) {
  const result = await executeD1Query(sql, params);
  return result.results[0] || null;
}

export async function getD1Health() {
  const startedAt = Date.now();
  const result = await executeD1Query("SELECT 1 AS ping, datetime('now') AS server_time");
  return {
    status: "connected",
    latency_ms: Date.now() - startedAt,
    total_queries: result.meta.total_queries,
    served_by: result.meta.served_by,
    database: result.meta.database,
    server_time: result.results[0]?.server_time || null
  };
}

export async function getBotSettings(botKey = "primary") {
  if (!["primary","secondary"].includes(botKey)) throw new Error("Bot key không hợp lệ.");
  const result = await executeD1Query("SELECT key,value,updated_at FROM bot_settings ORDER BY key");
  const values = Object.fromEntries(result.results.map((row) => [row.key,row.value]));
  const botConfig = await d1First(
    "SELECT bot_key,menu_mode,intro_text,menu_columns,persistent_keyboard,resize_keyboard,button_config,updated_at FROM telegram_bot_configs WHERE bot_key=?",
    [botKey]
  );
  let buttonConfig = {};
  try { buttonConfig = JSON.parse(botConfig?.button_config || "{}"); } catch { buttonConfig = {}; }
  const legacyMenu = (() => { try { return JSON.parse(values.menu_config || "[]"); } catch { return []; } })();
  if (!Object.keys(buttonConfig).length && Array.isArray(legacyMenu)) {
    for (const item of legacyMenu) if (item?.module) buttonConfig[item.module] = {
      label:item.label || "",description:"",style:"primary",enabled:item.enabled !== false
    };
  }
  const botMenu = {
    bot_key:botKey,
    configured:botKey === "primary" ? Boolean(process.env.BOT_TOKEN) : Boolean(process.env.BOT_SECOND_TOKEN),
    menu_mode:botConfig?.menu_mode || (botKey === "secondary" ? "reply_keyboard" : "inline_keyboard"),
    intro_text:botConfig?.intro_text || values.welcome_message || "Chào mừng bạn đến với Hendy Bot.",
    menu_columns:Number(botConfig?.menu_columns || 2),
    persistent_keyboard:botConfig ? Number(botConfig.persistent_keyboard) === 1 : true,
    resize_keyboard:botConfig ? Number(botConfig.resize_keyboard) === 1 : true,
    button_config:buttonConfig,updated_at:botConfig?.updated_at || null
  };
  return {
    maintenance_mode:values.maintenance_mode === "1",
    maintenance_message:values.maintenance_message || "Hệ thống đang bảo trì. Vui lòng quay lại sau.",
    welcome_message:values.welcome_message || "Xin chào! Chào mừng bạn đến với Hendy Bot.",
    support_telegram:values.support_telegram || "",language:values.language || "vi",
    vietqr_bank:values.vietqr_bank || process.env.VIETQR_BANK_ID || "",
    vietqr_account_no:values.vietqr_account_no || process.env.VIETQR_ACCOUNT_NO || "",
    vietqr_account_name:values.vietqr_account_name !== undefined ? values.vietqr_account_name : (process.env.VIETQR_ACCOUNT_NAME || ""),
    group_chat_url:values.group_chat_url !== undefined ? values.group_chat_url : (process.env.GROUP_CHAT_URL || ""),
    news_channel_url:values.news_channel_url !== undefined ? values.news_channel_url : (process.env.NEWS_CHANNEL_URL || ""),
    support_url:values.support_url !== undefined ? values.support_url : (process.env.SUPPORT_URL || ""),
    ui_theme:(()=>{try{return JSON.parse(values.ui_theme || "{}");}catch{return {};}})(),
    menu_config:legacyMenu,bot_menu:botMenu,
    updated_at:result.results.reduce((latest,row)=>row.updated_at>latest?row.updated_at:latest,"")
  };
}
export async function setTelegramBotConfig(botKey,config,updatedBy=null) {
  if (!["primary","secondary"].includes(botKey)) throw new Error("Bot key không hợp lệ.");
  await executeD1Query(
    "INSERT INTO telegram_bot_configs(bot_key,menu_mode,intro_text,menu_columns,persistent_keyboard,resize_keyboard,button_config,updated_by,updated_at) " +
    "VALUES(?,?,?,?,?,?,?,?,datetime('now')) ON CONFLICT(bot_key) DO UPDATE SET " +
    "menu_mode=excluded.menu_mode,intro_text=excluded.intro_text,menu_columns=excluded.menu_columns," +
    "persistent_keyboard=excluded.persistent_keyboard,resize_keyboard=excluded.resize_keyboard," +
    "button_config=excluded.button_config,updated_by=excluded.updated_by,updated_at=datetime('now')",
    [botKey,config.menu_mode,config.intro_text,config.menu_columns,config.persistent_keyboard?1:0,
     config.resize_keyboard?1:0,JSON.stringify(config.button_config || {}),updatedBy]
  );
}
export async function setBotSetting(key, value, updatedBy = null) {
  await executeD1Query(
    "INSERT INTO bot_settings(key,value,updated_at,updated_by) VALUES(?,?,datetime('now'),?) " +
      "ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=datetime('now'),updated_by=excluded.updated_by",
    [key, String(value), updatedBy]
  );
}

export async function writeAdminAudit(adminId, action, targetType = null, targetId = null, details = {}) {
  await executeD1Query(
    "INSERT INTO admin_audit_logs(id,admin_telegram_id,action,target_type,target_id,details_json) VALUES(?,?,?,?,?,?)",
    [crypto.randomUUID(), String(adminId), action, targetType, targetId, JSON.stringify(details)]
  );
}
