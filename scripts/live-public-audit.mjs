const BASE = new URL(process.env.PRODUCTION_BASE_URL || "https://lfamiliastore.my.id").origin;
const issues = [];
const warnings = [];
const results = [];
const pages = ["/","/catalog","/checkout","/contact","/faq","/leaderboard","/news","/promo","/track","/login","/payment","/privacy","/terms","/refund","/tools","/tools/win-rate","/tools/zodiac","/tools/magic-wheel"];
const apis = ["/api/products","/api/storefront","/api/home-content","/api/news","/api/leaderboard","/api/payment-methods","/api/payment-page-settings","/api/promotions","/api/reviews?featured=1","/api/wallet"];
const protectedApis = ["/api/account","/api/account/membership","/api/account/support","/api/account/game-accounts"];
const forbiddenKeys = new Set(["providerCode","providerSku","provider_code","provider_sku","supplierPrice","supplier_price","apiKey","api_key","clientSecret","client_secret","secretKey","secret_key","privateKey","private_key"]);
const forbiddenBrands = ["doku","digiflazz","melostore"];

async function get(path, redirect = "follow") {
  const url = new URL(path, BASE);
  url.searchParams.set("live_audit", `${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`);
  const start = Date.now();
  let response;
  let lastError;
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      response = await fetch(url, { redirect, headers: { accept: "*/*", "cache-control": "no-cache", "user-agent": "lfamilia-live-audit/3" }, signal: AbortSignal.timeout(20000) });
      break;
    } catch (error) {
      lastError = error;
      if (attempt < 3) await new Promise(r => setTimeout(r, 1200));
    }
  }
  if (!response) throw lastError;
  const text = await response.text();
  const ms = Date.now() - start;
  results.push({ path, status: response.status, ms, url: response.url });
  return { response, text, ms };
}

function security(scope, response) {
  const exact = { "x-content-type-options": "nosniff", "x-frame-options": "DENY", "referrer-policy": "strict-origin-when-cross-origin" };
  for (const [name, expected] of Object.entries(exact)) if ((response.headers.get(name) || "").toLowerCase() !== expected.toLowerCase()) issues.push(`${scope}: ${name} invalid`);
  if (!response.headers.get("content-security-policy")) issues.push(`${scope}: CSP missing`);
  if (!response.headers.get("strict-transport-security")) issues.push(`${scope}: HSTS missing`);
}

function inspectJson(scope, value, trail = "root") {
  if (typeof value === "string") {
    const lower = value.toLowerCase();
    for (const brand of forbiddenBrands) if (lower.includes(brand)) issues.push(`${scope}: provider brand ${brand} leaked at ${trail}`);
    return;
  }
  if (Array.isArray(value)) return value.forEach((v,i) => inspectJson(scope,v,`${trail}[${i}]`));
  if (!value || typeof value !== "object") return;
  for (const [key, entry] of Object.entries(value)) {
    if (forbiddenKeys.has(key)) issues.push(`${scope}: internal key leaked at ${trail}.${key}`);
    inspectJson(scope, entry, `${trail}.${key}`);
  }
}

let home = "";
let coldHome = 0;
for (const path of pages) {
  try {
    const { response, text, ms } = await get(path);
    security(`PAGE ${path}`, response);
    if (response.status !== 200) issues.push(`PAGE ${path}: HTTP ${response.status}`);
    if (!(response.headers.get("content-type") || "").includes("text/html")) issues.push(`PAGE ${path}: not HTML`);
    if (text.length < 500 || /internal server error|application error|runtime error/i.test(text)) issues.push(`PAGE ${path}: invalid/error HTML`);
    if (path === "/") { home = text; coldHome = ms; if (!/LFAMILIA/i.test(text)) issues.push("PAGE /: LFAMILIA branding missing"); }
  } catch (error) { issues.push(`PAGE ${path}: request failed ${error}`); }
}

const warm = [];
for (let i=0;i<3;i++) { try { const { response, ms } = await get("/"); if (response.status === 200) warm.push(ms); } catch {} }
if (warm.length === 3) {
  const median = [...warm].sort((a,b)=>a-b)[1];
  console.log(`HOME_TIMING cold=${coldHome}ms warm=${warm.join(",")}ms median=${median}ms`);
  if (median > 3000) issues.push(`PERF /: warm median ${median}ms`);
  else if (coldHome > 5000) warnings.push(`PERF /: cold ${coldHome}ms, warm median ${median}ms`);
}

for (const path of apis) {
  try {
    const { response, text, ms } = await get(path);
    security(`API ${path}`, response);
    if (response.status !== 200) issues.push(`API ${path}: HTTP ${response.status}`);
    let json;
    try { json = JSON.parse(text); } catch { issues.push(`API ${path}: invalid JSON`); continue; }
    inspectJson(`API ${path}`, json);
    if (ms > 5000) warnings.push(`API ${path}: ${ms}ms`);
    if (path === "/api/products") {
      if (json.databaseReady !== true) issues.push("API /api/products: databaseReady != true");
      if (!Array.isArray(json.products)) issues.push("API /api/products: products not array");
    }
  } catch (error) { issues.push(`API ${path}: request failed ${error}`); }
}

for (const path of protectedApis) {
  try {
    const { response, text } = await get(path, "manual");
    security(`AUTH ${path}`, response);
    if (![401,403].includes(response.status)) issues.push(`AUTH ${path}: HTTP ${response.status}, expected 401/403`);
    if (/password_hash|customer_sessions|wallet_transactions/i.test(text)) issues.push(`AUTH ${path}: private data leaked`);
  } catch (error) { issues.push(`AUTH ${path}: request failed ${error}`); }
}

try { const { response } = await get("/api/account/topups", "manual"); if (response.status !== 405) issues.push(`METHOD /api/account/topups: HTTP ${response.status}`); } catch (error) { issues.push(`METHOD /api/account/topups: ${error}`); }
for (const path of ["/api/admin/summary","/admin/panel"]) { try { const { response } = await get(path,"manual"); if (response.status === 200 || ![301,302,303,307,308,401,403].includes(response.status)) issues.push(`BOUNDARY ${path}: HTTP ${response.status}`); } catch (error) { issues.push(`BOUNDARY ${path}: ${error}`); } }

if (home) {
  const assets = new Set();
  for (const re of [/<script[^>]+src=["']([^"']+)["']/gi,/<link[^>]+href=["']([^"']+)["']/gi,/<img[^>]+src=["']([^"']+)["']/gi]) for (const m of home.matchAll(re)) { try { const u = new URL(m[1],BASE); if (u.origin === BASE && !u.pathname.startsWith("/api/")) assets.add(u.pathname + u.search); } catch {} }
  for (const asset of [...assets].slice(0,40)) { try { const { response } = await get(asset); if (response.status >= 400) issues.push(`ASSET ${asset}: HTTP ${response.status}`); } catch (error) { issues.push(`ASSET ${asset}: ${error}`); } }
  console.log(`ASSETS_CHECKED ${Math.min(assets.size,40)}`);
}

console.log("LIVE_AUDIT_MATRIX");
for (const row of results) console.log(`${row.status} ${String(row.ms).padStart(5)}ms ${row.path}`);
console.log(`LIVE_AUDIT_SUMMARY pages=${pages.length} apis=${apis.length} protected=${protectedApis.length} requests=${results.length} issues=${issues.length} warnings=${warnings.length}`);
for (const warning of warnings) console.log(`WARNING ${warning}`);
for (const issue of issues) console.error(`ISSUE ${issue}`);
if (issues.length) process.exitCode = 1;
else console.log("LIVE_PUBLIC_AUDIT_PASS");
