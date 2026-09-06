import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const runtimeRoots = ["app", "components", "lib", "relay", "db"];
const docs = ["README.md", "PROVIDER-CONFIG.md", "INTEGRATION-SETUP.md"];

function filesUnder(relative) {
  const absolute = path.join(root, relative);
  const stat = fs.statSync(absolute);
  if (stat.isFile()) return [absolute];
  return fs.readdirSync(absolute, { withFileTypes: true }).flatMap((entry) => {
    const child = path.join(absolute, entry.name);
    return entry.isDirectory()
      ? filesUnder(path.relative(root, child))
      : [child];
  });
}

test("Midtrans is absent from active runtime, UI, relay, schema, and current docs", () => {
  const files = [
    ...runtimeRoots.flatMap(filesUnder),
    ...docs.map((file) => path.join(root, file)),
  ].filter((file) => /\.(?:ts|tsx|js|mjs|md|jsonc?)$/i.test(file));

  const offenders = files.flatMap((file) => {
    const source = fs.readFileSync(file, "utf8");
    return /midtrans|bi-snap|bisnap/i.test(source)
      ? [path.relative(root, file)]
      : [];
  });

  assert.deepEqual(offenders, []);
});

test("historical Midtrans migration is retained only for applied D1 history", () => {
  const historical = path.join(root, "drizzle/0014_midtrans_gateway.sql");
  assert.equal(fs.existsSync(historical), true);
  const cleanup = fs.readFileSync(
    path.join(root, "drizzle/0022_remove_midtrans_runtime_config.sql"),
    "utf8",
  );
  assert.match(cleanup, /DELETE FROM integration_profiles WHERE provider = 'midtrans'/);
});
