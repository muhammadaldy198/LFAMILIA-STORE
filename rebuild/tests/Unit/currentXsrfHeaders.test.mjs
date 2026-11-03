import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { currentXsrfHeaders } from '../../resources/js/lib/currentXsrfHeaders.js';

const originalDocument = globalThis.document;
afterEach(() => { globalThis.document = originalDocument; });

test('reads the new cookie immediately after login rotation, not the stale Inertia meta token', () => {
    const staleMetaToken = 'csrf-before-login';
    globalThis.document = {
        cookie: 'lfamilia_session=session-a; XSRF-TOKEN=csrf-before-login',
        querySelector: () => ({ content: staleMetaToken }),
    };
    assert.deepEqual(currentXsrfHeaders(), { 'X-XSRF-TOKEN': 'csrf-before-login' });
    document.cookie = 'lfamilia_session=session-b; XSRF-TOKEN=csrf-after-login%3D';
    assert.deepEqual(currentXsrfHeaders(), { 'X-XSRF-TOKEN': 'csrf-after-login=' });
    assert.equal(document.querySelector().content, staleMetaToken);
});

test('never sends a stale meta CSRF token if the cookie is missing or invalid', () => {
    globalThis.document = { cookie: 'lfamilia_session=only', querySelector: () => ({ content: 'stale' }) };
    assert.deepEqual(currentXsrfHeaders(), {});
    document.cookie = 'XSRF-TOKEN=%invalid';
    assert.deepEqual(currentXsrfHeaders(), {});
});
