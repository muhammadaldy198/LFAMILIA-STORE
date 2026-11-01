import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const appDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';

function run(command, args) {
    console.log('> ' + command + ' ' + args.join(' '));
    const result = spawnSync(command, args, {
        cwd: appDir,
        stdio: 'inherit',
        env: process.env,
    });

    if (result.error || result.status !== 0) {
        process.exit(result.status || 1);
    }
}

function phpFiles(dir) {
    if (!fs.existsSync(dir)) {
        return [];
    }

    const entries = fs.readdirSync(dir, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const absolute = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            files.push(...phpFiles(absolute));
        } else if (entry.isFile() && entry.name.endsWith('.php')) {
            files.push(path.relative(appDir, absolute));
        }
    }

    return files;
}

run(npmCommand, ['run', 'lint']);
run(npmCommand, ['run', 'check']);
run(npmCommand, ['run', 'build']);

const syntaxRoots = ['app', 'bootstrap', 'config', 'public', 'routes', 'tests'];
for (const root of syntaxRoots) {
    for (const file of phpFiles(path.join(appDir, root))) {
        run('php', ['-l', file]);
    }
}

run('php', ['vendor/bin/pint', '--test']);
console.log('Pre-push guard passed.');
