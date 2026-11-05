import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

test('catalog select preserves code and offers a required placeholder', () => {
    const source = read('resources/js/Pages/Catalog/Show.vue');
    assert.match(source, /field\.type==='select'/);
    assert.match(source, /<option value="" disabled>/);
    assert.match(source, /:value="option\.value"/);
    assert.match(source, /field\.type === 'select' && !\(field\.options \|\| \[\]\)\.some/);
});

test('password is only masked at checkout and hidden from nickname requests', () => {
    const source = read('resources/js/Pages/Catalog/Show.vue');
    assert.match(source, /passwordVisible\[field\.field_key\]/);
    assert.match(source, /autocomplete="new-password"/);
    assert.match(source, /customer_input: publicInputPayload\(\)/);
    assert.match(source, /field\.type==='password'\?'••••••'/);
});

test('admin editor parses value label pairs and supports via-login passwords', () => {
    const source = read('resources/js/Pages/Admin/Catalog.vue');
    assert.match(source, /parseFieldOptions/);
    assert.match(source, /value="select"/);
    assert.match(source, /value="password"/);
    assert.match(source, /options: row\.type === 'select'/);
});
