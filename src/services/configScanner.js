import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const SCAN_ROOTS = ["src", "apps", "mini-app", "cloudflare", ".github/workflows", "migrations", "scripts"];
const ROOT_FILES = [".env.example", "render.yaml", "wrangler.toml", "package.json", "README.md"];
const IGNORED_DIRS = new Set(["node_modules", "dist", ".git", ".wrangler"]);

export const CONFIG_DEFINITIONS = [
  { key: "BOT_SECOND_TOKEN", kind: "secret", owner: "Render Web Service", storage: "Render environment secret", required: [".env.example", "render.yaml", "src/middlewares/telegramAuth.js", "src/routes/productionApi.js", "src/server.js"] },
  { key: "BOT_SECOND_USERNAME", kind: "public", owner: "Render Web Service", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/routes/productionApi.js"] },
  { key: "BOT_SECOND_ADMIN_ID", kind: "public", owner: "Render Web Service", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/middlewares/telegramAuth.js"] },
  { key: "ADMIN_TELEGRAM_IDS_SECONDARY", kind: "sensitive-public", owner: "Render Web Service", storage: "Render environment variable; comma-separated admin IDs", required: [".env.example", "render.yaml", "src/middlewares/telegramAuth.js"] },
  { key: "TELEGRAM_SECOND_WEBHOOK_SECRET", kind: "secret", owner: "Render Web Service", storage: "Render environment secret", required: [".env.example", "render.yaml", "src/routes/productionApi.js", "src/server.js"] },
  { key: "WEBHOOK_SECRET", kind: "secret", owner: "Render Web Service", storage: "Render environment secret", required: [".env.example", "render.yaml", "src/routes/productionApi.js", "src/server.js"] },
  { key: "VITE_API_BASE_URL", kind: "public", owner: "GitHub Actions → Cloudflare Pages build", storage: "GitHub Actions Repository Variable", required: [".env.example", ".github/workflows/deploy-cloudflare.yml", "mini-app/src/services/api.js"] },
  { key: "ADMIN_WEBAPP_URL", kind: "public", owner: "Render Web Service", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/routes/productionApi.js"] },
  { key: "ALLOWED_ORIGINS", kind: "public", owner: "Render Web Service", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/server.js"] },
  { key: "VIETQR_BANK_ID", kind: "public", owner: "Cloudflare D1 → bot_settings", storage: "D1 setting; optional Render bootstrap value", required: [".env.example", "render.yaml", "src/services/productionD1.js"] },
  { key: "VIETQR_ACCOUNT_NO", kind: "sensitive-public", owner: "Cloudflare D1 → bot_settings", storage: "D1 setting; not exposed in admin events", required: [".env.example", "render.yaml", "src/services/productionD1.js"] },
  { key: "VIETQR_ACCOUNT_NAME", kind: "public", owner: "Cloudflare D1 → bot_settings", storage: "D1 setting", required: [".env.example", "render.yaml", "src/services/productionD1.js"] },
  { key: "GROUP_CHAT_URL", kind: "public", owner: "Cloudflare D1 → bot_settings", storage: "D1 setting", required: [".env.example", "render.yaml", "src/services/productionD1.js"] },
  { key: "NEWS_CHANNEL_URL", kind: "public", owner: "Cloudflare D1 → bot_settings", storage: "D1 setting", required: [".env.example", "render.yaml", "src/services/productionD1.js"] },
  { key: "SUPPORT_URL", kind: "public", owner: "Cloudflare D1 → bot_settings", storage: "D1 setting", required: [".env.example", "render.yaml", "src/services/productionD1.js"] },
  { key: "RENDER_API_KEY", kind: "secret", owner: "Render control plane", storage: "Render environment secret", required: [".env.example", "render.yaml", "src/services/runtimeConfigSync.js"] },
  { key: "RENDER_SERVICE_ID", kind: "public", owner: "Render control plane", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/services/runtimeConfigSync.js"] },
  { key: "GITHUB_CONFIG_TOKEN", kind: "secret", owner: "GitHub Actions configuration", storage: "Render environment secret", required: [".env.example", "render.yaml", "src/services/runtimeConfigSync.js"] },
  { key: "GITHUB_REPOSITORY_OWNER", kind: "public", owner: "GitHub repository", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/services/runtimeConfigSync.js"] },
  { key: "GITHUB_REPOSITORY_NAME", kind: "public", owner: "GitHub repository", storage: "Render environment variable", required: [".env.example", "render.yaml", "src/services/runtimeConfigSync.js"] }
];

async function walk(directory, base, files) {
  let entries;
  try { entries = await fs.readdir(directory, { withFileTypes: true }); }
  catch { return; }
  for (const entry of entries) {
    if (entry.isDirectory()) {
      if (!IGNORED_DIRS.has(entry.name)) await walk(path.join(directory, entry.name), base, files);
    } else if (entry.isFile() && /\.(?:js|jsx|mjs|cjs|ts|tsx|yml|yaml|toml|md|example|json|css|sql|html)$/.test(entry.name)) {
      files.push(path.relative(ROOT, path.join(directory, entry.name)).replaceAll(path.sep, "/"));
    }
  }
}

export async function scanConfigurationLocations() {
  const files = [...ROOT_FILES];
  for (const directory of SCAN_ROOTS) await walk(path.join(ROOT, directory), ROOT, files);
  const uniqueFiles = [...new Set(files)].sort();
  const fileContents = new Map();
  for (const relative of uniqueFiles) {
    try { fileContents.set(relative, await fs.readFile(path.join(ROOT, relative), "utf8")); }
    catch { /* a declared location may not exist yet; it will be reported as missing */ }
  }

  const variables = CONFIG_DEFINITIONS.map((definition) => {
    const locations = [];
    for (const [file, content] of fileContents) {
      const lines = content.split(/\r?\n/);
      for (let index = 0; index < lines.length; index += 1) {
        if (new RegExp("(^|[^A-Z0-9_])" + definition.key + "([^A-Z0-9_]|$)").test(lines[index])) {
          locations.push({ file, line: index + 1 });
        }
      }
    }
    const declared = new Set(locations.map((location) => location.file));
    const missing = definition.required.filter((file) => !declared.has(file));
    return {
      key: definition.key,
      kind: definition.kind,
      owner: definition.owner,
      storage: definition.storage,
      locations,
      missingLocations: missing,
      complete: missing.length === 0
    };
  });

  return {
    scannedAt: new Date().toISOString(),
    filesScanned: fileContents.size,
    variableCount: variables.length,
    variables,
    complete: variables.every((variable) => variable.complete)
  };
}
