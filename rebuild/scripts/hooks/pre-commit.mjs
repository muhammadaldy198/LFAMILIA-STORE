import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const appDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const repoRoot = path.resolve(appDir, '..');
const maxBuffer = 32 * 1024 * 1024;
const maxNonMediaBytes = 10 * 1024 * 1024;
const allowedLargeMedia = /\.(?:png|jpe?g|webp|avif|gif|svg|woff2?|ttf|otf)$/i;

function run(command, args, options = {}) {
    return spawnSync(command, args, {
        cwd: options.cwd || repoRoot,
        encoding: 'utf8',
        maxBuffer,
        stdio: options.stdio || 'pipe',
        env: process.env,
    });
}

function git(args) {
    const result = run('git', args);
    if (result.error || result.status !== 0) {
        console.error('Unable to inspect the staged Git index.');
        process.exit(1);
    }

    return result.stdout;
}

function stagedFiles() {
    return git(['diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z'])
        .split('\0')
        .filter(Boolean);
}

function stagedSize(file) {
    const result = run('git', ['cat-file', '-s', ':' + file]);
    if (result.error || result.status !== 0) {
        return null;
    }

    const size = Number.parseInt(result.stdout.trim(), 10);
    return Number.isFinite(size) ? size : null;
}

function addedLines(file) {
    const result = run('git', ['diff', '--cached', '--unified=0', '--no-color', '--', file]);
    if (result.error || result.status !== 0) {
        return [];
    }

    return result.stdout
        .split(/\r?\n/)
        .filter((line) => line.startsWith('+') && !line.startsWith('+++'))
        .map((line) => line.slice(1));
}

function forbiddenReason(file) {
    const lower = file.toLowerCase();
    const base = path.posix.basename(lower);

    if (base === '.env' || (base.startsWith('.env.') && base !== '.env.example')) {
        return 'environment file';
    }

    if (/\.(?:key|p12|pfx)$/i.test(base) || /^(?:id_rsa|id_dsa|id_ecdsa|id_ed25519)$/i.test(base)) {
        return 'private credential/key file';
    }

    if (/\.(?:sql|dump|bak)(?:\.(?:gz|bz2|xz))?$/i.test(base)) {
        return 'database dump/backup';
    }

    if (/(?:^|[-_.])(?:backup|dump|secret-export|credential-export)(?:[-_.]|$)/i.test(base)
        && /\.(?:zip|tar|tgz|gz|7z)$/i.test(base)) {
        return 'backup/export archive';
    }

    if (/^(?:credentials?|service-account|client-secret)(?:[-_.].*)?\.json$/i.test(base)) {
        return 'credential JSON';
    }

    if (/^(?:debug|trace)(?:[-_.].*)?\.(?:log|txt|json)$/i.test(base)) {
        return 'temporary debug output';
    }

    if (/^rebuild\/(?:node_modules|vendor|public\/build|coverage|test-results)\//i.test(lower)
        || /^rebuild\/storage\/(?:framework|logs)\//i.test(lower)) {
        return 'generated/runtime artifact';
    }

    return null;
}

function placeholderValue(value) {
    const normalized = value.toLowerCase();
    return [
        'test',
        'fake',
        'dummy',
        'example',
        'placeholder',
        'fixture',
        'changeme',
        'change-me',
        'invalid',
        'not-a-real',
        'local-only',
    ].some((token) => normalized.includes(token));
}

const files = stagedFiles();
if (files.length === 0) {
    console.log('Pre-commit guard: no staged files.');
    process.exit(0);
}

const findings = [];
const addFinding = (file, type) => findings.push({ file, type });

const diffCheck = run('git', ['diff', '--cached', '--check']);
if (diffCheck.error || diffCheck.status !== 0) {
    addFinding('(staged diff)', 'whitespace error or unresolved conflict marker');
}

for (const file of files) {
    const forbidden = forbiddenReason(file);
    if (forbidden) {
        addFinding(file, forbidden);
        continue;
    }

    const size = stagedSize(file);
    if (size !== null && size > maxNonMediaBytes && !allowedLargeMedia.test(file)) {
        addFinding(file, 'file exceeds 10 MiB and is not an approved source/media type');
        continue;
    }

    const lines = addedLines(file);
    const isPhpRuntime = /^rebuild\/(?:app|bootstrap|config|public|routes)\/.*\.php$/i.test(file);
    const isFrontendRuntime = /^rebuild\/resources\/js\/.*\.(?:js|vue)$/i.test(file);

    for (const line of lines) {
        if (/^(?:<{7}|>{7})(?:\s|$)/.test(line)) {
            addFinding(file, 'unresolved merge conflict marker');
            break;
        }

        if (/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/.test(line)) {
            addFinding(file, 'private key material');
            break;
        }

        if (/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/.test(line)) {
            addFinding(file, 'AWS-style access key');
            break;
        }

        if (/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{40,})\b/.test(line)) {
            addFinding(file, 'GitHub-style access token');
            break;
        }

        const bearer = line.match(/\bBearer\s+([A-Za-z0-9._~+/=-]{20,})/i);
        if (bearer && !placeholderValue(bearer[1])) {
            addFinding(file, 'hardcoded bearer token');
            break;
        }

        const assignment = line.match(/(?:^|[\s,{[(])['"]?(password|api_key|server_key|client_key|client_secret|secret_key|merchant_key|merchant_secret|access_token|bot_token|webhook_secret|private_key)['"]?\s*(?:=>|:|=)\s*['"\x60]([^'"\x60\r\n]{8,})['"\x60]/i);
        if (assignment && !placeholderValue(assignment[2])) {
            addFinding(file, 'possible hardcoded ' + assignment[1].toLowerCase());
            break;
        }

        if (isPhpRuntime && /\b(?:dd|dump|var_dump|die)\s*\(/.test(line)) {
            addFinding(file, 'debug-only PHP call');
            break;
        }

        if (isFrontendRuntime && /\bconsole\.log\s*\(/.test(line)) {
            addFinding(file, 'debug-only console.log');
            break;
        }

        if (isFrontendRuntime && /(^|[^\w.])alert\s*\(/.test(line)) {
            addFinding(file, 'bare debug alert');
            break;
        }

        if ((isPhpRuntime || isFrontendRuntime)
            && /https?:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?(?:\/|['"\s]|$)/i.test(line)) {
            addFinding(file, 'hardcoded local/debug endpoint');
            break;
        }
    }
}

if (findings.length > 0) {
    console.error('Pre-commit guard blocked staged changes:');
    for (const finding of findings) {
        console.error(' - ' + finding.file + ': ' + finding.type);
    }
    console.error('Secret values are intentionally not printed.');
    process.exit(1);
}

const phpFiles = files
    .filter((file) => file.startsWith('rebuild/') && file.endsWith('.php'))
    .map((file) => file.slice('rebuild/'.length));

for (const file of phpFiles) {
    const result = run('php', ['-l', file], { cwd: appDir, stdio: 'inherit' });
    if (result.error || result.status !== 0) {
        console.error('PHP syntax guard failed for ' + file + '.');
        process.exit(1);
    }
}

if (phpFiles.length > 0) {
    const pint = path.join(appDir, 'vendor', 'bin', 'pint');
    if (!fs.existsSync(pint)) {
        console.error('Laravel Pint is unavailable. Run composer install in rebuild/.');
        process.exit(1);
    }

    const result = run('php', ['vendor/bin/pint', '--test', ...phpFiles], {
        cwd: appDir,
        stdio: 'inherit',
    });
    if (result.error || result.status !== 0) {
        console.error('Pint check failed for staged PHP files.');
        process.exit(1);
    }
}

const frontendFiles = files
    .filter((file) => /^rebuild\/resources\/js\/.*\.(?:js|vue)$/i.test(file))
    .map((file) => file.slice('rebuild/'.length));

if (frontendFiles.length > 0) {
    const eslint = path.join(appDir, 'node_modules', 'eslint', 'bin', 'eslint.js');
    if (!fs.existsSync(eslint)) {
        console.error('ESLint is unavailable. Run npm ci in rebuild/.');
        process.exit(1);
    }

    const result = run(process.execPath, [eslint, '--max-warnings=0', ...frontendFiles], {
        cwd: appDir,
        stdio: 'inherit',
    });
    if (result.error || result.status !== 0) {
        console.error('ESLint failed for staged frontend files.');
        process.exit(1);
    }
}

console.log('Pre-commit guard passed for ' + files.length + ' staged file(s).');
