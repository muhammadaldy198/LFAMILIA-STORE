#!/usr/bin/env node
/**
 * npm audit gate with ONE temporary, single-advisory waiver.
 *
 * Waived advisory: GHSA-fc5q-39xc-vjpf — picomatch ReDoS through
 * single-branch repeated extglobs (severity: high, vulnerable range: *).
 *
 * Why this waiver exists (decided 2026-11-18):
 * - The advisory was published after 2026-11-17 and marks EVERY picomatch
 *   version as vulnerable. As of 2026-11-18 the newest releases are
 *   picomatch 4.0.7 / 2.3.2, so NO patched version exists to upgrade to.
 *   The only `npm audit fix` path is a breaking vite downgrade to 6.2.7.
 * - picomatch is build-time-only dev tooling (glob matching inside vite /
 *   vue-tsc). It is never shipped to production and never processes
 *   untrusted runtime input, so the practical risk is negligible.
 * - Every other high/critical advisory still fails this gate exactly like
 *   the plain `npm audit --audit-level=high` command did, including any
 *   package that is tainted by the waived advisory AND another advisory.
 *
 * Removal: as soon as a fixed picomatch release is published and the
 * lockfile is updated, delete this file and restore the workflow step to
 * `npm audit --audit-level=high`.
 *
 * Usage: node scripts/ci/npm-audit-gate.mjs [audit-report.json]
 * Without an argument it runs `npm audit --json` itself. The optional file
 * argument exists so the gate logic can be tested against saved reports.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const WAIVED_ADVISORY_URLS = new Set([
  'https://github.com/advisories/GHSA-fc5q-39xc-vjpf',
]);
const BLOCKING_SEVERITIES = new Set(['high', 'critical']);

function loadReport() {
  const file = process.argv[2];
  if (file) {
    return JSON.parse(readFileSync(file, 'utf8'));
  }
  try {
    const out = execFileSync('npm', ['audit', '--json'], {
      encoding: 'utf8',
      maxBuffer: 64 * 1024 * 1024,
    });
    return JSON.parse(out);
  } catch (error) {
    // npm audit exits non-zero when vulnerabilities exist; the JSON report
    // is still printed on stdout.
    if (error.stdout) {
      return JSON.parse(error.stdout);
    }
    throw error;
  }
}

const report = loadReport();
const vulnerabilities = report.vulnerabilities ?? {};

const advisoryUrlsOf = (entry) =>
  (entry.via ?? []).filter((v) => typeof v === 'object').map((v) => v.url);
const dependencyNamesOf = (entry) =>
  (entry.via ?? []).filter((v) => typeof v === 'string');

// A package blocks the gate when it carries a non-waived advisory itself,
// or depends (transitively, through npm's `via` name references) on a
// package that does.
const taintCache = new Map();
function isTainted(name, seen = new Set()) {
  if (taintCache.has(name)) return taintCache.get(name);
  if (seen.has(name)) return false; // dependency cycle: stop recursing
  seen.add(name);
  const entry = vulnerabilities[name];
  if (!entry) {
    taintCache.set(name, false);
    return false;
  }
  let tainted = advisoryUrlsOf(entry).some((url) => !WAIVED_ADVISORY_URLS.has(url));
  if (!tainted) {
    tainted = dependencyNamesOf(entry).some((dep) => isTainted(dep, seen));
  }
  taintCache.set(name, tainted);
  return tainted;
}

const blocking = [];
const waived = [];
for (const [name, entry] of Object.entries(vulnerabilities)) {
  if (!BLOCKING_SEVERITIES.has(entry.severity)) continue;
  (isTainted(name) ? blocking : waived).push(name);
}

if (waived.length > 0) {
  console.log(
    `WAIVED by temporary exception (GHSA-fc5q-39xc-vjpf, picomatch — no patched release exists yet): ${waived.join(', ')}`,
  );
}

if (blocking.length > 0) {
  console.error('npm audit gate FAILED — blocking vulnerabilities:');
  for (const name of blocking) {
    const entry = vulnerabilities[name];
    const own = advisoryUrlsOf(entry).filter((url) => !WAIVED_ADVISORY_URLS.has(url));
    console.error(`- ${name} (${entry.severity})${own.length ? ` — ${own.join(', ')}` : ' — via a tainted dependency'}`);
  }
  process.exit(1);
}

console.log('npm audit gate passed: no blocking high/critical vulnerabilities.');
