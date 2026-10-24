// Inventaris risiko state untuk review sebelum push.
// Pakai: node inventory.mjs [dirAwal]  (default: resources/js/Pages/Admin)
// Output: daftar ref yang menangkap objek, panel yang tidak pernah ditutup,
// router.delete tanpa onSuccess, dan salinan props ke ref lokal.
import fs from 'node:fs';
import path from 'node:path';

const root = process.argv[2] || 'resources/js/Pages/Admin';
const files = [];

(function walk(dir) {
    if (!fs.existsSync(dir)) return;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(p);
        else if (entry.name.endsWith('.vue')) files.push(p);
    }
})(root);

const PRIMITIVES = new Set(['null', 'undefined', 'true', 'false', '""', "''"]);

function blocks(text, opener) {
    // Ambil isi setiap callback `opener: (...) => { ... }` dengan pencocokan kurung.
    const out = [];
    let i = 0;
    while ((i = text.indexOf(opener, i)) !== -1) {
        const brace = text.indexOf('{', i);
        if (brace === -1) break;
        let depth = 0, j = brace;
        for (; j < text.length; j++) {
            if (text[j] === '{') depth++;
            else if (text[j] === '}' && --depth === 0) break;
        }
        out.push(text.slice(brace + 1, j));
        i = j + 1;
    }
    return out;
}

function routerCalls(text) {
    const out = [];
    const re = /router\.(delete|post|put|get|patch)\(/g;
    let m;
    while ((m = re.exec(text))) {
        let depth = 0, j = m.index + m[0].length - 1;
        for (; j < text.length; j++) {
            if (text[j] === '(') depth++;
            else if (text[j] === ')' && --depth === 0) break;
        }
        out.push({ method: m[1], call: text.slice(m.index, j + 1) });
    }
    return out;
}

const rows = [];
for (const file of files) {
    const text = fs.readFileSync(file, 'utf8');
    const refs = [...text.matchAll(/const\s+(\w+)\s*=\s*ref\(/g)].map((m) => m[1]);
    const onSuccessText = blocks(text, 'onSuccess').join('\n') + blocks(text, 'onFinish').join('\n');
    const sheets = [...text.matchAll(/:open="([^"]+)"/g)].map((m) => m[1]);

    const stale = [];
    for (const m of text.matchAll(/(\w+)\.value\s*=\s*([A-Za-z_$][\w$]*|\{)\s*[;\n)]/g)) {
        const [, ref, value] = m;
        if (!refs.includes(ref)) continue;
        // Hanya objek: identifier polos (item, row, channel, …) atau literal objek.
        if (value === '{') { stale.push({ ref, value: '{ … }' }); continue; }
        if (!/^(item|row|entry|record|data|value|admin|attempt|channel|voucher|pack|tier|payment|product|notice|asset|banner|popup|news|faq|review|page|providers|route|result)$/.test(value)) continue;
        stale.push({ ref, value });
    }

    const openNoReset = [];
    for (const expr of sheets) {
        for (const ref of refs) {
            if (!new RegExp(`\\b${ref}\\b`).test(expr)) continue;
            if (expr === 'Boolean(' + ref + ')') continue;
            if (expr === ref || expr === `${ref} !== null`) {
                const reset = new RegExp(`${ref}\\.value\\s*=\\s*null`).test(onSuccessText)
                    || /close\w*\s*\(/.test(onSuccessText);
                if (!reset) openNoReset.push({ ref, expr });
            }
        }
    }

    const deleteNoSuccess = routerCalls(text)
        .filter((c) => c.method === 'delete' && !/onSuccess\s*:/.test(c.call))
        .map((c) => c.call.replace(/\s+/g, ' ').slice(0, 90));

    const propCopies = [
        ...text.matchAll(/watch\(\(\)\s*=>\s*props\.(\w+)/g),
        ...text.matchAll(/computed\(\(\)\s*=>\s*props\.(\w+)/g),
    ].map((m) => m[1]);

    if (stale.length || openNoReset.length || deleteNoSuccess.length) {
        rows.push({ file, stale, openNoReset, deleteNoSuccess, propCopies });
    }
}

let total = 0;
for (const r of rows) {
    console.log('\n## ' + r.file);
    for (const s of r.stale) {
        console.log(`  OBJEK-DISIMPAN  ${s.ref} = ${s.value.trim().slice(0, 70)}`);
        total++;
    }
    for (const o of r.openNoReset) {
        console.log(`  PANEL-TANPA-RESET  :open="${o.expr}"  (${o.ref} tidak dikosongkan di onSuccess)`);
        total++;
    }
    for (const d of r.deleteNoSuccess) {
        console.log('  DELETE-TANPA-ONSUCCESS  ' + d);
        total++;
    }
    if (r.propCopies.length) console.log('  props->ref: ' + [...new Set(r.propCopies)].join(', '));
}
console.log(`\n${total} titik perlu diperiksa di ${rows.length} berkas.`);
