const BASE = new URL(process.env.PRODUCTION_BASE_URL || "https://lfamiliastore.my.id").origin;
const issues = [];
const warnings = [];
const results = [];

const publicPages = [
  "/", "/catalog", "/checkout", "/contact", "/faq", "/leaderboard", "/news",
  "/promo", "/track", "/login", "/payment", "/privacy", "/terms", "/refund",
  "/tools", "/tools/win-rate", "/tools/zodiac", "/tools/magic-wheel",
];

const publicApis = [
  "/api/products",
  "/api/storefront",
  "/api/home-content",
  "/api/news",
  "/api/leaderboard",
  "/api/payment-methods",
  "/api/payment-page-settings",
  "/api/promotions",
  "/api/reviews?featured=1",
  "/api/wallet",
];

const protectedApis = [
  "/api/account",
  "/api/account/membership",
  "/api/account/support",
  "/api/account/game-accounts",
];

const forbiddenPublicKeys = new Set([
  "providerCode", "providerSku", "provider_code", "provider_sku",
  "supplierPrice", "supplier_price", "apiKey", "api_key",
  "clientSecret", "client_secret", "secretKey", "secret_key",
  "privateKey", "private_key",
]);
const forbiddenPublicBrands = ["digiflazz", "doku", "melostore"];

function addIssue(scope, message) { issues.push(`${scope}: ${message}`); }
function addWarning(scope, message) { warnings.push(`${scope}: ${message}`); }

async function request(path, options = {}) {
  const url = new URL(path, BASE);
  url.searchParams.set("live_audit", `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`);
  const started = Date.now();
  let response;
  let error;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      response = await fetch(url, {
        redirect: options.redirect || "follow",
        headers: {
          accept: options.accept || "*/*",
          "cache-control": "no-cache",
          "user-agent": "lfamilia-live-public-audit/2.0",
        },
        signal: AbortSignal.timeout(20_000),
      });
      break;
    } catch (caught) {
      error = caught;
      if (attempt < 3) await new Promise((resolve) => setTimeout(resolve, 1500));
    }
  }
  const ms = Date.now() - started;
  if (!response) throw error;
  const text = await response.text();
  results.push({ path, status: response.status, ms, finalUrl: response.url, contentType: response.headers.get("content-type") || "" });
  return { response, text, ms };
}

function inspectSecurityHeaders(scope, response) {
  const expected = [
    ["x-content-type-options", "nosniff"],
    ["x-frame-options", "DENY"],
    ["referrer-policy", "strict-origin-when-cross-origin"],
  ];
  for (const [name, value] of expected) {
    if ((response.headers.get(name) || "").toLowerCase() !== value.toLowerCase()) {
      addIssue(scope, `security header ${name} tidak sesuai`);
    }
  }
  if (!response.headers.get("content-security-policy")) addIssue(scope, "Content-Security-Policy tidak ada");
  if (!response.headers.get("strict-transport-security")) addIssue(scope, "HSTS tidak ada");
}

function inspectJsonValue(scope, value, trail = "root") {
  if (Array.isArray(value)) {
    value.forEach((entry, index) => inspectJsonValue(scope, entry, `${trail}[${index}]`));
    return;
  }
  if (!value || typeof value !== "object") return;
  for (const [key, entry] of Object.entries(value)) {
    if (forbiddenPublicKeys.has(key)) addIssue(scope, `metadata internal bocor di ${trail}.${key}`);
    inspectJsonValue(scope, entry, `${trail}.${key}`);
  }
}

function collectBrandLeaks(value, trail = "root", found = []) {
  if (typeof value === "string") {
    const lower = value.toLowerCase();
    for (const brand of forbiddenPublicBrands) {
      const index = lower.indexOf(brand);
      if (index >= 0) {
        const start = Math.max(0, index - 45);
        const end = Math.min(value.length, index + brand.length + 45);
        found.push({ brand, trail, snippet: value.slice(start, end).replaceAll(/\s+/g, " ") });
      }
    }
    return found;
  }
  if (Array.isArray(value)) {
    value.forEach((entry, index) => collectBrandLeaks(entry, `${trail}[${index}]`, found));
    return found;
  }
  if (!value || typeof value !== "object") return found;
  for (const [key, entry] of Object.entries(value)) collectBrandLeaks(entry, `${trail}.${key}`, found);
  return found;
}

function parseSameOriginAssets(html) {
  const urls = new Set();
  const patterns = [
    /<script[^>]+src=["']([^"']+)["']/gi,
    /<link[^>]+href=["']([^"']+)["'][^>]*>/gi,
    /<img[^>]+src=["']([^"']+)["']/gi,
  ];
  for (const pattern of patterns) {
    for (const match of html.matchAll(pattern)) {
      try {
        const url = new URL(match[1], BASE);
        if (url.origin === BASE && !url.pathname.startsWith("/api/")) urls.add(url.pathname + url.search);
      } catch {}
    }
  }
  return [...urls].slice(0, 40);
}

console.log(`LIVE PUBLIC AUDIT target=${BASE}`);

let homepageHtml = "";
let firstHomepageMs = null;
for (const path of publicPages) {
  const scope = `PAGE ${path}`;
  try {
    const { response, text, ms } = await request(path, { accept: "text/html,application/xhtml+xml" });
    if (response.status !== 200) addIssue(scope, `HTTP ${response.status}`);
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.toLowerCase().includes("text/html")) addIssue(scope, `content-type bukan HTML (${contentType || "kosong"})`);
    if (text.length < 500) addIssue(scope, `HTML terlalu pendek (${text.length} byte)`);
    if (/internal server error|application error|runtime error/i.test(text)) addIssue(scope, "indikasi error server terlihat pada HTML");
    if (/vinext-starter|create next app/i.test(text)) addIssue(scope, "starter/default branding masih muncul");
    if (!/<meta[^>]+name=["']viewport["']/i.test(text)) addWarning(scope, "meta viewport tidak terdeteksi");
    if (!/<title>[^<]+<\/title>/i.test(text)) addWarning(scope, "title HTML tidak terdeteksi");
    inspectSecurityHeaders(scope, response);
    if (path === "/") {
      homepageHtml = text;
      firstHomepageMs = ms;
      if (!/LFAMILIA/i.test(text)) addIssue(scope, "branding LFAMILIA tidak ditemukan");
    }
  } catch (error) {
    addIssue(scope, `request gagal: ${error instanceof Error ? error.message : String(error)}`);
  }
}

// Distinguish a repeatable slow homepage from a one-off Worker/D1 cold start.
const warmHomepageTimings = [];
for (let i = 0; i < 3; i += 1) {
  try {
    const { response, ms } = await request("/", { accept: "text/html,application/xhtml+xml" });
    if (response.status === 200) warmHomepageTimings.push(ms);
  } catch {}
}
if (warmHomepageTimings.length === 3) {
  const sorted = [...warmHomepageTimings].sort((a, b) => a - b);
  const median = sorted[1];
  console.log(`Homepage timing: first=${firstHomepageMs}ms warm=${warmHomepageTimings.join(",")}ms medianWarm=${median}ms`);
  if (median > 3000) addIssue("PERF /", `homepage tetap lambat setelah warm-up (median ${median}ms)`);
  else if ((firstHomepageMs ?? 0) > 5000) addWarning("PERF /", `cold request ${firstHomepageMs}ms tetapi warm median ${median}ms; cek biaya first-request Worker/D1`);
}

for (const path of publicApis) {
  const scope = `API ${path}`;
  try {
    const { response, text, ms } = await request(path, { accept: "application/json" });
    if (response.status !== 200) addIssue(scope, `HTTP ${response.status}`);
    const contentType = response.headers.get("content-type") || "";
    if (!contentType.toLowerCase().includes("application/json")) addIssue(scope, `content-type bukan JSON (${contentType || "kosong"})`);
    let payload;
    try { payload = JSON.parse(text); } catch { addIssue(scope, "body bukan JSON valid"); }
    if (payload !== undefined) {
      inspectJsonValue(scope, payload);
      for (const leak of collectBrandLeaks(payload)) {
        addIssue(scope, `nama integrasi internal "${leak.brand}" di ${leak.trail}: ${JSON.stringify(leak.snippet)}`);
      }
    }
    if (ms > 5000) addWarning(scope, `response lambat ${ms}ms`);
    inspectSecurityHeaders(scope, response);

    if (path === "/api/products" && payload) {
      if (payload.databaseReady !== true) addIssue(scope, "databaseReady bukan true");
      if (!Array.isArray(payload.products)) addIssue(scope, "products bukan array");
      else {
        for (const [index, product] of payload.products.entries()) {
          if (!product || typeof product !== "object") addIssue(scope, `products[${index}] invalid`);
          else if (!Array.isArray(product.packages)) addIssue(scope, `products[${index}].packages bukan array`);
        }
      }
    }
    if (path === "/api/payment-methods" && payload && !Array.isArray(payload.channels)) addIssue(scope, "channels bukan array");
    if (path === "/api/wallet" && payload) {
      if (!payload.settings || typeof payload.settings.enabled !== "boolean" || !Number.isFinite(Number(payload.settings.minimumAmount))) {
        addIssue(scope, "public wallet settings contract invalid");
      }
    }
  } catch (error) {
    addIssue(scope, `request gagal: ${error instanceof Error ? error.message : String(error)}`);
  }
}

for (const path of protectedApis) {
  const scope = `AUTH ${path}`;
  try {
    const { response, text } = await request(path, { accept: "application/json", redirect: "manual" });
    if (![401, 403].includes(response.status)) addIssue(scope, `tanpa sesi mengembalikan HTTP ${response.status}, seharusnya 401/403`);
    if (/wallet_transactions|customer_sessions|password_hash/i.test(text)) addIssue(scope, "data privat terlihat pada response tanpa sesi");
    inspectSecurityHeaders(scope, response);
  } catch (error) {
    addIssue(scope, `request gagal: ${error instanceof Error ? error.message : String(error)}`);
  }
}

// GET is intentionally unsupported on the POST-only top-up endpoint; this is a safe read-only method-boundary check.
try {
  const { response } = await request("/api/account/topups", { accept: "application/json", redirect: "manual" });
  if (response.status !== 405) addIssue("METHOD /api/account/topups", `GET menghasilkan HTTP ${response.status}, expected 405`);
} catch (error) {
  addIssue("METHOD /api/account/topups", `request gagal: ${error instanceof Error ? error.message : String(error)}`);
}

for (const path of ["/api/admin/summary", "/admin/panel"]) {
  const scope = `BOUNDARY ${path}`;
  try {
    const { response } = await request(path, { redirect: "manual" });
    if (response.status === 200) addIssue(scope, "area Admin dapat diakses langsung tanpa Cloudflare Access/session");
    if (![301, 302, 303, 307, 308, 401, 403].includes(response.status)) addWarning(scope, `status proteksi tidak lazim: HTTP ${response.status}`);
  } catch (error) {
    addIssue(scope, `request gagal: ${error instanceof Error ? error.message : String(error)}`);
  }
}

if (homepageHtml) {
  const assets = parseSameOriginAssets(homepageHtml);
  for (const asset of assets) {
    const scope = `ASSET ${asset}`;
    try {
      const { response } = await request(asset);
      if (response.status >= 400) addIssue(scope, `HTTP ${response.status}`);
    } catch (error) {
      addIssue(scope, `request gagal: ${error instanceof Error ? error.message : String(error)}`);
    }
  }
  console.log(`Checked ${assets.length} same-origin homepage assets.`);
}

console.log("\n=== LIVE AUDIT MATRIX ===");
for (const row of results) console.log(`${String(row.status).padEnd(4)} ${String(row.ms).padStart(5)}ms ${row.path} -> ${row.finalUrl}`);

console.log(`\nSUMMARY pages=${publicPages.length} publicApis=${publicApis.length} protectedApis=${protectedApis.length} requests=${results.length} issues=${issues.length} warnings=${warnings.length}`);
if (warnings.length) {
  console.log("\nWARNINGS");
  warnings.forEach((item) => console.log(`- ${item}`));
}
if (issues.length) {
  console.error("\nISSUES");
  issues.forEach((item) => console.error(`- ${item}`));
  process.exitCode = 1;
} else {
  console.log("\nLIVE PUBLIC AUDIT PASS: seluruh pemeriksaan read-only yang dijalankan sehat.");
}
