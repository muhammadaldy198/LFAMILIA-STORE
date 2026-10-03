import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const appDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const repoRoot = path.resolve(appDir, '..');
const forceInCi = process.env.LFAMILIA_ENABLE_HOOKS === '1';
const disabled =
    process.env.HUSKY === '0'
    || process.env.NODE_ENV === 'production'
    || process.env.APP_ENV === 'production'
    || (process.env.CI === 'true' && !forceInCi);

if (disabled) {
    console.log('Husky install skipped for CI/production.');
    process.exit(0);
}

if (!fs.existsSync(path.join(repoRoot, '.git'))) {
    console.log('Husky install skipped: Git worktree not available.');
    process.exit(0);
}

let husky;
try {
    ({ default: husky } = await import('husky'));
} catch (error) {
    const omit = String(process.env.npm_config_omit || '');
    if (omit.split(',').includes('dev')) {
        console.log('Husky install skipped: devDependencies are omitted.');
        process.exit(0);
    }

    console.error('Husky is unavailable. Run npm ci with development dependencies.');
    process.exit(1);
}

process.chdir(repoRoot);
const result = husky('rebuild/.husky');
if (result) {
    console.log(result);
}
