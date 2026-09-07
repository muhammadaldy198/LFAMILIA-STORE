import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const readJson = (file) => JSON.parse(fs.readFileSync(path.join(root, file), "utf8"));

const removedComponents = [
  "components/ui/calendar.tsx",
  "components/ui/resizable.tsx",
  "components/ui/sonner.tsx",
  "components/ui/drawer.tsx",
  "components/ui/combobox.tsx",
  "components/ui/form.tsx",
  "components/ui/input-otp.tsx",
  "components/ui/chart.tsx",
  "components/ui/message-scroller.tsx",
  "components/ui/carousel.tsx",
];

const removedDependencies = [
  "@base-ui/react",
  "@hookform/resolvers",
  "@shadcn/react",
  "date-fns",
  "embla-carousel-react",
  "input-otp",
  "next-themes",
  "react-day-picker",
  "react-hook-form",
  "react-resizable-panels",
  "recharts",
  "sonner",
  "vaul",
];

test("unused starter primitives stay removed", () => {
  for (const file of removedComponents) {
    assert.equal(fs.existsSync(path.join(root, file)), false, file);
  }
  assert.equal(fs.existsSync(path.join(root, "examples/d1")), false);
});

test("package manifests contain only LFAMILIA runtime dependencies", () => {
  const pkg = readJson("package.json");
  const lock = readJson("package-lock.json");

  assert.equal(pkg.name, "lfamilia-store");
  assert.equal(lock.name, "lfamilia-store");
  assert.equal(lock.packages[""].name, "lfamilia-store");

  for (const dependency of removedDependencies) {
    assert.equal(pkg.dependencies?.[dependency], undefined, dependency);
    assert.equal(lock.packages[""].dependencies?.[dependency], undefined, dependency);
  }
});
