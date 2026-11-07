const CHECKOUT_ATTEMPT_PREFIX = 'lfamilia:checkout-attempt:';

/** Storage key for one product's persisted checkout attempt. */
export function checkoutAttemptKey(productId) {
    return CHECKOUT_ATTEMPT_PREFIX + productId;
}

/**
 * Remove every persisted checkout attempt from sessionStorage. Used on
 * logout so a later user of the same tab inherits no replay record.
 */
export function clearStoredCheckoutAttempts() {
    try {
        const keys = [];
        for (let index = 0; index < window.sessionStorage.length; index += 1) {
            const key = window.sessionStorage.key(index);
            if (key && key.startsWith(CHECKOUT_ATTEMPT_PREFIX)) keys.push(key);
        }
        keys.forEach((key) => window.sessionStorage.removeItem(key));
    } catch {
        // Storage can be unavailable in hardened/private browsers.
    }
}

/**
 * SHA-256 hex digest of the checkout state signature (Web Crypto). The
 * persisted attempt stores only this digest, never the raw signature,
 * which serializes the buyer's contact and game-account inputs.
 * Returns null when Web Crypto is unavailable.
 */
export async function checkoutSignatureHash(signature) {
    if (!globalThis.crypto?.subtle?.digest || typeof TextEncoder === 'undefined') return null;
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(signature));
    return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
}
