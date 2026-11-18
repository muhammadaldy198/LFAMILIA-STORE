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
 * - If npm cannot produce a vulnerability report at all (endpoint error,
 *   malformed payload), this gate FAILS — an unreadable report is never
 *   treated as a clean scan.
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

// A response without a real vulnerability report (for example an npm error
// payload such as {"error": {...}} or {"message": ..., "statusCode": 403})
// must fail the gate, never pass as a clean scan. A valid report with an
// empty vulnerabilities object is fine and passes below.
if (
  report === null ||
  typeof report !== 'object' ||
  report.error !== undefined ||
  report.vulnerabilities === null ||
  typeof report.vulnerabilities !== 'object' ||
  Array.isArray(report.vulnerabilities)
) {
  console.error(
    'npm audit gate FAILED — npm did not return a valid vulnerability report; refusing to treat an unreadable audit as a clean scan.',
  );
  process.exit(1);
}

const vulnerabilities = report.vulnerabilities;

const advisoryObjectsOf = (entry) =>
  (entry.via ?? []).filter((v) => typeof v === 'object');
const dependencyNamesOf = (entry) =>
  (entry.via ?? []).filter((v) => typeof v === 'string');

// Taint sources: packages carrying a high/critical advisory that is NOT the
// waived one. Lower-severity advisories never taint, matching the old
// `--audit-level=high` behaviour.
const tainted = new Set();
for (const [name, entry] of Object.entries(vulnerabilities)) {
  const hasBlockingAdvisory = advisoryObjectsOf(entry).some(
    (advisory) =>
      BLOCKING_SEVERITIES.has(advisory.severity) &&
      !WAIVED_ADVISORY_URLS.has(advisory.url),
  );
  if (hasBlockingAdvisory) {
    tainted.add(name);
  }
}

// Taint flows from a vulnerable package to everything that depends on it.
// npm expresses that direction as `via` name references on the dependent,
// so walk the reverse edges from every source. Reachability (instead of a
// memoised DFS) keeps the result independent of report order and correct
// around dependency cycles.
const dependentsOf = new Map();
for (const [name, entry] of Object.entries(vulnerabilities)) {
  for (const dependencyName of dependencyNamesOf(entry)) {
    if (!dependentsOf.has(dependencyName)) {
      dependentsOf.set(dependencyName, []);
    }
    dependentsOf.get(dependencyName).push(name);
  }
}
const queue = [...tainted];
while (queue.length > 0) {
  const current = queue.pop();
  for (const dependent of dependentsOf.get(current) ?? []) {
    if (!tainted.has(dependent)) {
      tainted.add(dependent);
      queue.push(dependent);
    }
  }
}

const blocking = [];
const waived = [];
for (const [name, entry] of Object.entries(vulnerabilities)) {
  if (!BLOCKING_SEVERITIES.has(entry.severity)) continue;
  (tainted.has(name) ? blocking : waived).push(name);
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
    const own = advisoryObjectsOf(entry)
      .filter(
        (advisory) =>
          BLOCKING_SEVERITIES.has(advisory.severity) &&
          !WAIVED_ADVISORY_URLS.has(advisory.url),
      )
      .map((advisory) => advisory.url);
    console.error(`- ${name} (${entry.severity})${own.length ? ` — ${own.join(', ')}` : ' — via a tainted dependency'}`);
  }
  process.exit(1);
}

console.log('npm audit gate passed: no blocking high/critical vulnerabilities.');
