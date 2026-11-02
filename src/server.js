import express from "express";
import { createServer } from "node:http";
import cors from "cors";
import dotenv from "dotenv";
import path from "node:path";
import { fileURLToPath } from "node:url";
import productionApi from "./routes/productionApi.js";
import { errorHandler } from "./middlewares/errorHandler.js";
import { attachAdminRealtime } from "./services/adminRealtime.js";

dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const isProduction = process.env.NODE_ENV === "production";
const PORT = Number(process.env.PORT || 3000);
const app = express();

const allowedOrigins = new Set(
  String(process.env.ALLOWED_ORIGINS || "")
    .split(",")
    .map((origin) => origin.trim().replace(/\/+$/, ""))
    .filter(Boolean)
);

app.disable("x-powered-by");
app.use((req, res, next) => {
  if (req.path === "/api" || req.path.startsWith("/api/")) {
    res.setHeader("Cache-Control", "no-store, private");
    res.setHeader("Pragma", "no-cache");
  }
  res.setHeader("X-Content-Type-Options", "nosniff");
  res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
  res.setHeader("X-Frame-Options", "SAMEORIGIN");
  res.setHeader("Permissions-Policy", "camera=(), microphone=(), geolocation=()");
  next();
});

app.use(cors({
  origin(origin, callback) {
    if (!origin || allowedOrigins.has(String(origin).replace(/\/+$/, ""))) {
      return callback(null, true);
    }
    return callback(null, false);
  },
  methods: ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
  allowedHeaders: ["Content-Type", "Authorization", "X-Telegram-Init-Data", "X-Telegram-Bot-Api-Secret-Token"],
  maxAge: 600
}));

app.use(express.json({ limit: "1mb", strict: true }));
app.use(express.urlencoded({ extended: false, limit: "1mb" }));

app.get(["/health", "/healthz"], async (_req, res) => {
  try {
    const { getD1Health } = await import("./services/productionD1.js");
    const d1 = await getD1Health();
    return res.status(200).json({
      status: "ok",
      service: "Hendy Bot Telegram",
      runtime: process.version,
      d1: d1.status,
      timestamp: new Date().toISOString()
    });
  } catch (error) {
    return res.status(503).json({
      status: "degraded",
      service: "Hendy Bot Telegram",
      d1: "unavailable",
      error: isProduction ? "Cloudflare D1 không khả dụng hoặc chưa cấu hình." : error.message,
      timestamp: new Date().toISOString()
    });
  }
});

// Keep both API prefixes for the existing Mini App and versioned clients.
app.use("/api", productionApi);
app.use("/api/v1", productionApi);
app.all("/api/*", (_req, res) => res.status(404).json({ error: "API endpoint không tồn tại." }));

if (!isProduction) {
  const { createServer } = await import("vite");
  const vite = await createServer({
    configFile: path.resolve(__dirname, "../mini-app/vite.config.mjs"),
    server: { middlewareMode: true },
    appType: "spa",
    root: path.resolve(__dirname, "../mini-app")
  });
  app.use(vite.middlewares);
} else {
  const distPath = path.resolve(__dirname, "../mini-app/dist");
  app.use(express.static(distPath, { index: false, maxAge: "1h" }));
  app.get("*", (_req, res) => {
    res.sendFile(path.join(distPath, "index.html"));
  });
}

app.use(errorHandler);

const server = createServer(app);
attachAdminRealtime(server);

server.listen(PORT, "0.0.0.0", async () => {
  console.log("[Hendy Bot] HTTP service listening on port " + PORT);
  if (process.env.SET_WEBHOOK_ON_START === "true") {
    const baseUrl = process.env.BASE_URL || process.env.RENDER_EXTERNAL_URL;
    const token = process.env.BOT_TOKEN;
    const secret = process.env.WEBHOOK_SECRET;
    if (!baseUrl || !token || !secret) {
      console.error("[Hendy Bot] Không thể tự đăng ký webhook: thiếu BASE_URL, BOT_TOKEN hoặc WEBHOOK_SECRET.");
      return;
    }
    const registrations = [{key:"primary",token,secret,path:"/api/telegram/webhook"}];
    if (process.env.BOT_SECOND_TOKEN) registrations.push({
      key:"secondary", token:process.env.BOT_SECOND_TOKEN,
      secret:process.env.TELEGRAM_SECOND_WEBHOOK_SECRET, path:"/api/telegram/webhook/secondary"
    });
    for (const bot of registrations) {
      if (!bot.secret) { console.error("[Hendy Bot] Webhook " + bot.key + " bỏ qua: thiếu secret riêng."); continue; }
      try {
        const endpoint = new URL(bot.path,baseUrl);
        if (endpoint.protocol !== "https:") throw new Error("Webhook URL phải dùng HTTPS.");
        const response = await fetch("https://api.telegram.org/bot" + bot.token + "/setWebhook", {
          method:"POST",headers:{"Content-Type":"application/json"},
          body:JSON.stringify({url:endpoint.toString(),secret_token:bot.secret,allowed_updates:["message","edited_message","callback_query"]}),
          signal:AbortSignal.timeout(10000)
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.description || "Telegram setWebhook thất bại.");
        console.log("[Hendy Bot] Telegram webhook registered (" + bot.key + "): " + endpoint.toString());
      } catch (error) { console.error("[Hendy Bot] Webhook registration failed (" + bot.key + "): " + error.message); }
    }
  }
});

function shutdown(signal) {
  console.log("[Hendy Bot] Nhận " + signal + ", đang đóng HTTP server.");
  server.close((error) => {
    if (error) {
      console.error(error);
      process.exit(1);
    }
    process.exit(0);
  });
}
process.once("SIGTERM", () => shutdown("SIGTERM"));
process.once("SIGINT", () => shutdown("SIGINT"));

export default app;
