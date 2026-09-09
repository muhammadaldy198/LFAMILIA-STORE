import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();

test("admin dashboard uses resilient brand logo and active notifications", () => {
  const dashboard = fs.readFileSync(path.join(root, "components/admin-dashboard.tsx"), "utf8");
  const logo = fs.readFileSync(path.join(root, "components/admin-brand-logo.tsx"), "utf8");
  const notifications = fs.readFileSync(path.join(root, "components/admin-notifications.tsx"), "utf8");

  assert.match(dashboard, /<AdminBrandLogo/);
  assert.match(dashboard, /<AdminNotifications/);
  assert.match(logo, /unoptimized/);
  assert.match(logo, /\/brand\/lfamilia-pixel-logo\.webp/);
  assert.match(notifications, /\/api\/panel\/summary\?range=7d/);
  assert.match(notifications, /recentActivities/);
  assert.match(notifications, /recentOrders/);
  assert.match(notifications, /paymentCallbackFailed/);
  assert.match(notifications, /sellerOff/);
  assert.match(notifications, /localStorage/);
});
