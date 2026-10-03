import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const appDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const repoRoot = path.resolve(appDir, '..');

function run(command, args, options = {}) {
    const result = spawnSync(command, args, {
        cwd: options.cwd || repoRoot,
        encoding: options.encoding === false ? undefined : 'utf8',
        stdio: options.stdio || 'pipe',
        env: process.env,
    });

    return result;
}

function must(command, args, options = {}) {
    const result = run(command, args, options);
    if (result.error || result.status !== 0) {
        if (result.stdout) process.stdout.write(result.stdout);
        if (result.stderr) process.stderr.write(result.stderr);
        throw new Error(command + ' failed');
    }
    return result;
}

const initialStatus = must('git', ['status', '--porcelain', '--untracked-files=all']).stdout.trim();
if (initialStatus !== '') {
    console.error('Hook smoke requires a clean working tree and index; no files were changed.');
    process.exit(1);
}

const base = must('git', ['rev-parse', 'HEAD']).stdout.trim();
const previousName = run('git', ['config', '--local', '--get', 'user.name']).stdout?.trim() || null;
const previousEmail = run('git', ['config', '--local', '--get', 'user.email']).stdout?.trim() || null;
must('git', ['config', '--local', 'user.name', 'LFAMILIA Hook Smoke']);
must('git', ['config', '--local', 'user.email', 'hook-smoke@invalid.local']);

const temporary = [
    'rebuild/app/__HookSmokeBad.php',
    'rebuild/resources/js/__HookSmokeLint.js',
    'rebuild/__hook_conflict.txt',
    'rebuild/.env.production',
    'rebuild/__hook_secret.txt',
    'rebuild/__hook_valid.md',
    'rebuild/app/__HookSmokeValid.php',
];

function cleanup() {
    run('git', ['reset', '--hard', base]);
    for (const file of temporary) {
        try {
            fs.unlinkSync(path.join(repoRoot, file));
        } catch {
            // File may already be removed by reset.
        }
    }
}

function expectBlocked(label, file, content, force = false, workingTreeReplacement = null) {
    cleanup();
    const absolute = path.join(repoRoot, file);
    fs.mkdirSync(path.dirname(absolute), { recursive: true });
    fs.writeFileSync(absolute, content);

    must('git', ['add', ...(force ? ['-f'] : []), file]);
    if (workingTreeReplacement !== null) {
        fs.writeFileSync(absolute, workingTreeReplacement);
    }

    const result = run('git', ['commit', '-m', 'hook smoke: ' + label]);
    if (result.status === 0) {
        throw new Error(label + ' was not blocked');
    }

    console.log('PASS blocked: ' + label);
}

try {
    expectBlocked(
        'PHP syntax error',
        'rebuild/app/__HookSmokeBad.php',
        '<?php\nfunction hook_smoke( {\n',
        false,
        '<?php\n\nreturn true;\n'
    );

    expectBlocked(
        'ESLint error',
        'rebuild/resources/js/__HookSmokeLint.js',
        'const hookSmokeUnused = 1;\n',
        false,
        'export const hookSmokeValid = 1;\n'
    );

    expectBlocked(
        'merge conflict marker',
        'rebuild/__hook_conflict.txt',
        '<<<<<<< ours\nleft\n=======\nright\n>>>>>>> theirs\n'
    );

    expectBlocked(
        'forbidden env file',
        'rebuild/.env.production',
        'APP_ENV=production\n',
        true
    );

    const secretText = 'client_' + 'secret = "' + 'opaque-value-938475938475938475' + '"\n';
    expectBlocked(
        'secret pattern',
        'rebuild/__hook_secret.txt',
        secretText
    );

    cleanup();
    fs.writeFileSync(path.join(repoRoot, 'rebuild/__hook_valid.md'), 'valid hook smoke file\n');
    must('git', ['add', 'rebuild/__hook_valid.md']);
    must('git', ['commit', '-m', 'hook smoke: valid staged file']);
    console.log('PASS commit: valid staged file');

    cleanup();
    fs.writeFileSync(
        path.join(repoRoot, 'rebuild/app/__HookSmokeValid.php'),
        '<?php\n\nreturn true;\n'
    );
    must('git', ['add', 'rebuild/app/__HookSmokeValid.php']);
    must('git', ['commit', '-m', 'hook smoke: valid PHP staged file']);
    console.log('PASS commit: valid PHP staged file');

    cleanup();
    const prePush = run('sh', ['rebuild/.husky/pre-push'], { stdio: 'inherit' });
    if (prePush.error || prePush.status !== 0) {
        throw new Error('pre-push hook entrypoint failed');
    }
    console.log('PASS pre-push hook entrypoint');

    cleanup();
    const status = must('git', ['status', '--porcelain', '--untracked-files=all']).stdout.trim();
    if (status !== '') {
        throw new Error('hook smoke left a dirty working tree');
    }

    console.log('Local hook smoke tests passed with a clean working tree.');
} catch (error) {
    cleanup();
    console.error(error.message);
    process.exitCode = 1;
} finally {
    cleanup();
    if (previousName === null) {
        run('git', ['config', '--local', '--unset', 'user.name']);
    } else {
        must('git', ['config', '--local', 'user.name', previousName]);
    }
    if (previousEmail === null) {
        run('git', ['config', '--local', '--unset', 'user.email']);
    } else {
        must('git', ['config', '--local', 'user.email', previousEmail]);
    }
}
