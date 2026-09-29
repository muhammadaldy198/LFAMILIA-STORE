# Security Hardening (M10)

M10 applies the application-level security baseline from the LFAMILIA STORE master PRD. It does not configure Cloudflare WAF, trusted proxies, Nginx, TLS, VPS firewall, or production credentials; those environment-specific controls remain M12.

## Request hardening

Every web/API request receives a correlation ID. A safe incoming X-Correlation-ID is preserved; malformed/untrusted values are replaced with a UUID. The ID is returned in X-Correlation-ID and is available to audit/event code.

Global response headers include:

- X-Content-Type-Options: nosniff
- X-Frame-Options: DENY
- Referrer-Policy: strict-origin-when-cross-origin
- Permissions-Policy disabling camera, microphone, geolocation and browser payment API
- Content-Security-Policy restricting origin, frames, forms, scripts and connections
- HSTS on HTTPS requests
- no-store/private caching for Admin, customer account and guest order detail pages

Laravel web CSRF protection remains enabled. Authentication and authorization remain server-side.

## Rate limiting

Existing named limits remain for nickname, quote/voucher validation, checkout creation, guest order lookup, Google OAuth, support tickets, payment creation, wallet top-up, payment callbacks and fulfillment callbacks.

M10 adds:

- register: 5 attempts/hour per normalized email + IP
- forgot password: 5 attempts/hour per normalized email + IP
- reset password: 8 attempts/hour per normalized email + IP
- sensitive Super Admin operations: 30/minute per Admin
- secret reveal: 6/minute per Admin

Customer/Admin login remains 5/minute per email + IP through the existing Fortify/Admin limiter.

Production proxy/IP trust must be configured against the actual Cloudflare/Nginx topology in M12. The application intentionally does not trust arbitrary forwarded IP headers.

## Turnstile

Cloudflare Turnstile credentials are read only from the encrypted Super Admin -> Integrasi record with code turnstile.

Only the public site_key is shared with the browser. secret_key remains server-side.

When the integration is active and both keys exist:

- registration requires a valid Turnstile token
- forgot-password requires a valid token
- login requires a valid token after three attempts for the same email + IP

Verification uses Cloudflare siteverify server-side with the requester IP. Each widget is tagged with a form action and the backend requires the returned action to match register, forgot_password, or login, preventing cross-form token reuse. Missing/invalid/wrong-action tokens are rejected. Upstream verification outages fail closed for challenged requests.

When Turnstile is disabled/not configured, these flows continue without a challenge so pre-production is not locked before credentials are entered.

## Secrets and audit

integration_credentials continues to use Laravel encrypted array casts. M10 regression coverage verifies secret plaintext is absent from the raw database column while the model can decrypt the configured value.

Normal Inertia integration data never includes secret values. Controlled reveal remains SUPER_ADMIN-only, rate-limited and audit-logged.

Audit redaction covers nested values whose keys contain password, credential, secret, token or signature, plus authorization/cookie fields. Sensitive values are replaced with [REDACTED].

## Callback/webhook review

M10 re-audited the M7/M8 callback paths rather than replacing them:

- Midtrans: SHA-512 signature, amount match, event idempotency and monotonic payment state.
- DOKU: Client-Id match, request signature verification, amount match, request-id event idempotency and monotonic payment state.
- Digiflazz: event/user-agent validation, HMAC signature and callback idempotency.
- Callback endpoints retain dedicated rate limits.

## Session

SESSION_LIFETIME remains 1440 minutes (24 hours), satisfying the v1 Admin/Super Admin session requirement. Session IDs are regenerated after login and invalidated/regenerated on logout.

## Milestone boundary

M11 owns the broad cross-system abuse/browser/security test campaign. M10 includes focused regression tests for the controls introduced here.

M12 owns Cloudflare WAF/rate-limit rules, trusted proxy configuration, Nginx/TLS/security at the server edge, queue/scheduler process supervision, production secrets and deployment validation.
