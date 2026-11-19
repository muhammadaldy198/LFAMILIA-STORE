/**
 * Regression tests for scripts/ci/npm-audit-gate.mjs.
 *
 * The gate replaces `npm audit --audit-level=high` with custom advisory
 * parsing and taint propagation, so these tests pin the behaviour that must
 * not regress: only GHSA-fc5q-39xc-vjpf is waived, every other high/critical
 * advisory (and everything depending on it) still fails, and npm error
 * payloads fail instead of passing as a clean scan.
 *
 * Hermetic: each report is written to a temporary file and passed to the gate
 * as its file argument, so the gate never runs `npm audit`. PATH is emptied
 * for the child process as a guard: if the gate ever fell back to spawning
 * npm, that spawn would fail and the passing scenarios would break.
 *
 * Run: node --test scripts/ci/npm-audit-gate.test.mjs (from rebuild/)
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';

const GATE = fileURLToPath(new URL('./npm-audit-gate.mjs', import.meta.url));
const REJECTED_REPORT_MESSAGE = 'npm did not return a valid vulnerability report';

const workDir = mkdtempSync(join(tmpdir(), 'npm-audit-gate-test-'));
after(() => rmSync(workDir, { recursive: true, force: true }));

let reportCounter = 0;

function runGate(report) {
  reportCounter += 1;
  const file = join(workDir, `report-${reportCounter}.json`);
  writeFileSync(file, JSON.stringify(report));
  const result = spawnSync(process.execPath, [GATE, file], {
    encoding: 'utf8',
    env: { PATH: '' },
  });
  assert.equal(result.error, undefined, 'gate process could not be started');
  return {
    status: result.status,
    stdout: result.stdout,
    stderr: result.stderr,
    waived: parseWaived(result.stdout),
    blocking: parseBlocking(result.stderr),
  };
}

function parseWaived(stdout) {
  const line = stdout.split('\n').find((l) => l.startsWith('WAIVED by temporary exception'));
  if (!line) return [];
  return line.slice(line.indexOf('): ') + 3).split(', ').sort();
}

function parseBlocking(stderr) {
  return stderr
    .split('\n')
    .filter((l) => l.startsWith('- '))
    .map((l) => l.slice(2, l.lastIndexOf(' (')))
    .sort();
}

const sorted = (names) => [...names].sort();

// --- report builders (shape of `npm audit --json`, auditReportVersion 2) ---

const PICOMATCH_ADVISORY = {
  source: 1117001,
  name: 'picomatch',
  dependency: 'picomatch',
  title: 'picomatch ReDoS through single-branch repeated extglobs',
  url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf',
  severity: 'high',
  cwe: ['CWE-1333'],
  cvss: { score: 7.5, vectorString: 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:H' },
  range: '*',
};

// Fictional advisories: they only need to differ from the waived URL.
function otherAdvisory(name, severity) {
  return {
    source: 9900000 + name.length,
    name,
    dependency: name,
    title: `Fictional ${severity} advisory for ${name}`,
    url: `https://github.com/advisories/GHSA-test-${severity}-${name.replace(/[^a-z0-9]/gi, '')}`,
    severity,
    cwe: [],
    cvss: { score: 0, vectorString: null },
    range: '*',
  };
}

function vuln(name, severity, via) {
  return {
    name,
    severity,
    isDirect: false,
    via,
    effects: [],
    range: '*',
    nodes: [`node_modules/${name}`],
    fixAvailable: false,
  };
}

function report(entries) {
  const vulnerabilities = {};
  for (const entry of entries) vulnerabilities[entry.name] = entry;
  return {
    auditReportVersion: 2,
    vulnerabilities,
    metadata: {
      vulnerabilities: { info: 0, low: 0, moderate: 0, high: entries.length, critical: 0, total: entries.length },
    },
  };
}

// Shape of the CI audit at the time of the waiver: the picomatch advisory and
// seven packages that are only vulnerable because they depend on picomatch.
const CURRENT_CI_PACKAGES = [
  'picomatch',
  '@vue/language-core',
  'vue-tsc',
  'fdir',
  'tinyglobby',
  'vite',
  'laravel-vite-plugin',
  'vite-plugin-full-reload',
];

function currentCiEntries() {
  return [
    vuln('picomatch', 'high', [PICOMATCH_ADVISORY]),
    vuln('@vue/language-core', 'high', ['picomatch']),
    vuln('vue-tsc', 'high', ['@vue/language-core']),
    vuln('fdir', 'high', ['picomatch']),
    vuln('tinyglobby', 'high', ['fdir', 'picomatch']),
    vuln('vite', 'high', ['picomatch', 'tinyglobby']),
    vuln('laravel-vite-plugin', 'high', ['vite', 'vite-plugin-full-reload']),
    vuln('vite-plugin-full-reload', 'high', ['picomatch']),
  ];
}

// --- scenarios ---

test('a. current CI report (picomatch + 7 dependents) passes with all eight waived', () => {
  const r = runGate(report(currentCiEntries()));
  assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(r.waived, sorted(CURRENT_CI_PACKAGES));
  assert.deepEqual(r.blocking, []);
  assert.match(r.stdout, /npm audit gate passed/);
});

test('b. another high advisory in the vite chain fails and blocks its dependents', () => {
  const entries = currentCiEntries();
  entries.find((e) => e.name === 'vite').via.push('rollup');
  entries.push(vuln('rollup', 'high', [otherAdvisory('rollup', 'high')]));
  const r = runGate(report(entries));
  assert.equal(r.status, 1);
  assert.deepEqual(r.blocking, sorted(['rollup', 'vite', 'laravel-vite-plugin']));
  assert.deepEqual(
    r.waived,
    sorted(['picomatch', '@vue/language-core', 'vue-tsc', 'fdir', 'tinyglobby', 'vite-plugin-full-reload']),
  );
  assert.match(r.stderr, /npm audit gate FAILED — blocking vulnerabilities/);
  assert.match(r.stderr, /- rollup \(high\) — https:\/\/github\.com\/advisories\/GHSA-test-high-rollup/);
  assert.match(r.stderr, /- vite \(high\) — via a tainted dependency/);
  assert.doesNotMatch(r.stdout, /npm audit gate passed/);
});

test('c. empty vulnerabilities object passes', () => {
  const r = runGate(report([]));
  assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(r.waived, []);
  assert.deepEqual(r.blocking, []);
  assert.match(r.stdout, /npm audit gate passed/);
});

test('d. a package with the picomatch advisory AND another high advisory fails', () => {
  const r = runGate(
    report([vuln('picomatch', 'high', [PICOMATCH_ADVISORY, otherAdvisory('picomatch', 'high')])]),
  );
  assert.equal(r.status, 1);
  assert.deepEqual(r.blocking, ['picomatch']);
  assert.deepEqual(r.waived, []);
  assert.match(r.stderr, /- picomatch \(high\) — https:\/\/github\.com\/advisories\/GHSA-test-high-picomatch/);
  assert.doesNotMatch(r.stderr, /GHSA-fc5q-39xc-vjpf/);
});

test('e. npm error payload {"error": {...}} is rejected, not treated as clean', () => {
  const r = runGate({
    error: {
      code: 'ENOAUDIT',
      summary: 'Your configured registry does not support audit requests.',
      detail: '',
    },
  });
  assert.equal(r.status, 1);
  assert.match(r.stderr, new RegExp(REJECTED_REPORT_MESSAGE));
  assert.doesNotMatch(r.stdout, /npm audit gate passed/);

  // An error next to an empty vulnerabilities object must still be rejected;
  // this pins the explicit `error` check, not only the missing-report check.
  const withEmptyReport = runGate({
    error: { code: 'ENOAUDIT', summary: 'audit endpoint returned an error', detail: '' },
    vulnerabilities: {},
  });
  assert.equal(withEmptyReport.status, 1);
  assert.match(withEmptyReport.stderr, new RegExp(REJECTED_REPORT_MESSAGE));
  assert.doesNotMatch(withEmptyReport.stdout, /npm audit gate passed/);
});

test('f. npm error payload {"message", "statusCode": 403} is rejected, not treated as clean', () => {
  const r = runGate({ message: 'Forbidden', statusCode: 403 });
  assert.equal(r.status, 1);
  assert.match(r.stderr, new RegExp(REJECTED_REPORT_MESSAGE));
  assert.doesNotMatch(r.stdout, /npm audit gate passed/);
});

test('g. picomatch advisory plus a moderate advisory still passes as waived', () => {
  const r = runGate(
    report([vuln('picomatch', 'high', [PICOMATCH_ADVISORY, otherAdvisory('picomatch', 'moderate')])]),
  );
  assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(r.waived, ['picomatch']);
  assert.deepEqual(r.blocking, []);
  assert.match(r.stdout, /npm audit gate passed/);
});

test('h. dependency cycle reaching a real advisory blocks every cycle member regardless of key order', () => {
  // Cycle members are written before the advisory source so an order-dependent
  // or memoised walk would classify them before the source is known.
  const r = runGate(
    report([
      vuln('cycle-a', 'high', ['cycle-b']),
      vuln('cycle-b', 'high', ['cycle-c']),
      vuln('cycle-c', 'high', ['cycle-a', 'real-source']),
      vuln('real-source', 'high', [otherAdvisory('real-source', 'critical')]),
      vuln('picomatch', 'high', [PICOMATCH_ADVISORY]),
    ]),
  );
  assert.equal(r.status, 1);
  assert.deepEqual(r.blocking, sorted(['cycle-a', 'cycle-b', 'cycle-c', 'real-source']));
  assert.deepEqual(r.waived, ['picomatch']);
});

test('i. dependency cycle carrying only the picomatch advisory passes with all members waived', () => {
  const r = runGate(
    report([
      vuln('cycle-a', 'high', ['cycle-b']),
      vuln('cycle-b', 'high', ['cycle-c']),
      vuln('cycle-c', 'high', ['cycle-a', 'picomatch']),
      vuln('picomatch', 'high', [PICOMATCH_ADVISORY]),
    ]),
  );
  assert.equal(r.status, 0, r.stderr);
  assert.deepEqual(r.waived, sorted(['cycle-a', 'cycle-b', 'cycle-c', 'picomatch']));
  assert.deepEqual(r.blocking, []);
  assert.match(r.stdout, /npm audit gate passed/);
});
