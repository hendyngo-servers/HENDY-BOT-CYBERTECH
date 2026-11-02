import { WebSocketServer, WebSocket } from "ws";
import { verifyTelegramWebAppData } from "../security/telegramAuth.js";
import { isTelegramAdmin } from "../middlewares/telegramAuth.js";
import { getBotSettings } from "./productionD1.js";
import { scanConfigurationLocations } from "./configScanner.js";

const clients = new Set();
const MAX_INIT_DATA_LENGTH = 16384;

function send(socket, type, payload = {}) {
  if (socket.readyState === WebSocket.OPEN) {
    socket.send(JSON.stringify({ type, payload, sentAt: new Date().toISOString() }));
  }
}

function allowedOrigin(origin) {
  if (!origin) return true;
  const allowed = new Set(
    String(process.env.ALLOWED_ORIGINS || "")
      .split(",").map((value) => value.trim().replace(/\/+$/, "")).filter(Boolean)
  );
  for (const value of [process.env.WEBAPP_URL, process.env.ADMIN_WEBAPP_URL]) {
    if (value) {
      try { allowed.add(new URL(value).origin); } catch { /* invalid env is rejected elsewhere */ }
    }
  }
  try { return allowed.has(new URL(origin).origin); }
  catch { return false; }
}

function publicConfig(settings) {
  return {
    language: settings.language || "vi",
    maintenance_mode: Boolean(settings.maintenance_mode),
    maintenance_message: settings.maintenance_message,
    support_telegram: settings.support_telegram,
    group_chat_url: settings.group_chat_url,
    news_channel_url: settings.news_channel_url,
    support_url: settings.support_url,
    ui_theme: settings.ui_theme,
    menu_config: settings.menu_config,
    bot_menu: settings.bot_menu
  };
}

export function attachAdminRealtime(server) {
  const wss = new WebSocketServer({ noServer: true, maxPayload: MAX_INIT_DATA_LENGTH + 2048, perMessageDeflate: false });

  server.on("upgrade", (request, socket, head) => {
    const pathname = new URL(request.url || "/", "http://localhost").pathname;
    if (pathname !== "/ws") {
      socket.destroy();
      return;
    }
    if (!allowedOrigin(request.headers.origin)) {
      socket.write("HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
      socket.destroy();
      return;
    }
    wss.handleUpgrade(request, socket, head, (websocket) => {
      wss.emit("connection", websocket, request);
    });
  });

  wss.on("connection", (socket) => {
    const client = { socket, userId: null, botKey: "primary", isAdmin: false, authenticated: false, alive: true };
    clients.add(client);
    const authTimeout = setTimeout(() => {
      if (!client.authenticated) socket.close(4001, "Telegram authentication required");
    }, 10000);

    socket.on("pong", () => { client.alive = true; });
    socket.on("message", async (buffer) => {
      let message;
      try { message = JSON.parse(buffer.toString("utf8")); }
      catch { socket.close(1007, "Invalid JSON"); return; }

      if (!client.authenticated) {
        if (message?.type !== "auth" || typeof message.initData !== "string" || message.initData.length > MAX_INIT_DATA_LENGTH) {
          socket.close(4001, "Telegram authentication required");
          return;
        }
        const checked = verifyTelegramWebAppData(
          message.initData,
          message.botKey === "secondary" ? process.env.BOT_SECOND_TOKEN || "" : process.env.BOT_TOKEN || "",
          { maxAgeSeconds: Number(process.env.TELEGRAM_AUTH_MAX_AGE_SECONDS || 86400) }
        );
        if (!checked.valid || !checked.user?.id) {
          socket.close(4003, "Invalid Telegram session");
          return;
        }
        client.userId = String(checked.user.id);
        client.botKey = message.botKey === "secondary" && process.env.BOT_SECOND_TOKEN ? "secondary" : "primary";
        client.isAdmin = isTelegramAdmin(client.userId, client.botKey);
        client.authenticated = true;
        clearTimeout(authTimeout);
        try {
          const settings = await getBotSettings(client.botKey);
          send(socket, "auth.ok", { userId: client.userId, isAdmin: client.isAdmin, botKey: client.botKey });
          send(socket, "public_config", publicConfig(settings));
          if (client.isAdmin) {
            send(socket, "admin.ready", { runtimeConfigAvailable: Boolean(process.env.RENDER_API_KEY && process.env.RENDER_SERVICE_ID) });
            try {
              send(socket, "config.registry", await scanConfigurationLocations());
            } catch (error) {
              send(socket, "config.registry.error", { message: error.message });
            }
          }
        } catch {
          socket.close(1011, "Configuration service unavailable");
        }
        return;
      }

      if (message?.type === "ping") send(socket, "pong", { now: Date.now() });
      if (message?.type === "scan_config") {
        if (!client.isAdmin) {
          send(socket, "error", { code: "ADMIN_FORBIDDEN", message: "Chỉ admin được quét cấu hình source." });
          return;
        }
        try {
          send(socket, "config.registry", await scanConfigurationLocations());
        } catch (error) {
          send(socket, "config.registry.error", { message: error.message });
        }
        return;
      }
      // The socket is a push channel only. Mutations must use the authenticated HTTPS admin API.
    });

    socket.on("close", () => {
      clearTimeout(authTimeout);
      clients.delete(client);
    });
    socket.on("error", () => clients.delete(client));
  });

  const heartbeat = setInterval(() => {
    for (const client of clients) {
      if (!client.authenticated) continue;
      if (!client.alive) {
        client.socket.terminate();
        clients.delete(client);
        continue;
      }
      client.alive = false;
      if (client.socket.readyState === WebSocket.OPEN) client.socket.ping();
    }
  }, 30000);
  heartbeat.unref?.();

  wss.on("close", () => clearInterval(heartbeat));
  return wss;
}

export function broadcastRealtime(type, payload = {}, options = {}) {
  const frame = JSON.stringify({ type, payload, sentAt: new Date().toISOString() });
  for (const client of clients) {
    if (client.socket.readyState !== WebSocket.OPEN) continue;
    if (options.adminOnly && !client.isAdmin) continue;
    client.socket.send(frame);
  }
}

export async function broadcastPublicConfigUpdate(settings) {
  broadcastRealtime("public_config.updated", publicConfig(settings));
}
