// Laravel rotates the session CSRF token after login. Inertia SPA navigation keeps
// the initial <head>, so the meta csrf-token can become stale while the
// XSRF-TOKEN cookie is refreshed on the login response.
export function currentXsrfHeaders() {
    const cookie = document.cookie.split(';')
        .map((value) => value.trim())
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!cookie) return {};

    try {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    } catch {
        // A malformed cookie must never fall back to a potentially stale meta token.
        return {};
    }
}
