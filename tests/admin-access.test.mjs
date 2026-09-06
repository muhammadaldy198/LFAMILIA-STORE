import assert from "node:assert/strict";
import test from "node:test";

async function loadWorker() {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}-${Math.random()}`);
  const { default: worker } = await import(workerUrl.href);
  return worker;
}

const env = {
  ASSETS: {
    fetch: async () => new Response("Not found", { status: 404 }),
  },
};

const ctx = {
  waitUntil() {},
  passThroughOnException() {},
};

test("direct admin API requires Cloudflare Access identity", async () => {
  const worker = await loadWorker();
  const response = await worker.fetch(
    new Request("http://localhost/api/admin/integrations"),
    env,
    ctx,
  );

  assert.equal(response.status, 401);
  const payload = await response.json();
  assert.equal(payload.error, "Cloudflare Access belum memvalidasi area Admin.");
});

test("admin panel requires Cloudflare Access identity before app routing", async () => {
  const worker = await loadWorker();
  const response = await worker.fetch(
    new Request("http://localhost/admin/panel"),
    env,
    ctx,
  );

  assert.equal(response.status, 401);
  assert.match(await response.text(), /Admin belum dilindungi/i);
});
