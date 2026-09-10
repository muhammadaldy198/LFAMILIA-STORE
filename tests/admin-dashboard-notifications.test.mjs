import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();

test("admin desktop shell keeps active notifications and account controls", () => {
  const dashboard = fs.readFileSync(path.join(root, "components/admin-dashboard.tsx"), "utf8");
  const notifications = fs.readFileSync(path.join(root, "components/admin-notifications.tsx"), "utf8");

  assert.match(dashboard, /<AdminNotifications/);
  assert.match(dashboard, /<AdminAccountMenu/);
  assert.match(dashboard, /Gamepad2/);
  assert.match(notifications, /\/api\/panel\/summary\?range=7d/);
  assert.match(notifications, /recentActivities/);
  assert.match(notifications, /recentOrders/);
  assert.match(notifications, /localStorage/);
});
