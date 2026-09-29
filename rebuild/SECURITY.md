# Security Hardening (M10)

M10 hardens the application layer without changing the LFAMILIA business flow. Cloudflare/VPS deployment policy remains M12 and the broad abuse/browser campaign remains M11.

## Sessions and production startup

- Session lifetime remains 1440 minutes (24 hours) as defined by the PRD.
- Session payload encryption defaults to enabled.
- Cookies remain HttpOnly and SameSite=Lax.
- Production startup fails if APP_DEBUG is enabled, session encryption is disabled, the session cookie is not Secure, APP_URL is not HTTPS, or APP_TRUSTED_HOSTS is empty.
- APP_TRUSTED_HOSTS is environment configuration, not hardcoded source.
- Customer password change/reset rotates remember_token.

## Security headers

Global responses receive:
- X-Content-Type-Options: nosniff
- X-Frame-Options: DENY
- Referrer-Policy: strict-origin-when-cross-origin
- Permissions-Policy disabling camera, microphone and geolocation
- Cross-Origin-Opener-Policy
- Cross-Origin-Resource-Policy
- HSTS for HTTPS requests
- CSP outside the local development environment

Admin/account/auth responses are no-store/private.

## Correlation IDs

Every request receives a validated X-Request-ID or a generated UUID. The value is:
- attached to the request,
- added to logging context,
- returned in the response,
- used by sensitive Admin audit records,
- used as the root order correlation ID at checkout,
- reused by payment/order and fulfillment events for the same order.

Malformed inbound request IDs are never reflected.

## Turnstile

Turnstile configuration is read from the encrypted Integrasi record and only the site key is exposed to the browser.

Server-side Siteverify is enforced when Turnstile is active for:
- register
- forgot password
- guest order lookup
- guest checkout
- customer login after repeated failures
- Admin login after repeated failures

Validation checks success and the expected action. Optional allowed_hostnames can restrict accepted Turnstile hostnames. Verification transport failures fail closed with a customer-safe validation message. Widgets reset after submission because Turnstile tokens are single-use.

## Suspicious login

Failed customer/Admin logins are counted per source for 30 minutes. Starting after the third failure, the next login attempt requires Turnstile when Turnstile is configured. A successful login clears the risk counter.

The existing email+IP Fortify/Admin login rate limits remain active in addition to this challenge.

## Application rate limits

M10 retains existing endpoint-specific limits and adds/strengthens:
- checkout creation: 20/minute per user/IP
- voucher validation: 10/minute per IP+voucher in addition to quote limit
- register: 5 attempts / 10 minutes
- forgot password: 5 attempts / 10 minutes
- reset password: 5 attempts / 10 minutes
- authenticated account API: 60/minute
- sensitive customer account actions: 10/minute
- sensitive Admin configuration actions: 20/minute
- secret reveal: 5/minute

Nickname, order lookup, payment creation, wallet top-up, Google OAuth, support and webhook limits from earlier milestones remain in force.

## Secret handling

IntegrationCredential continues to use Laravel encrypted array casts.

Secret reveal now requires all of:
- authenticated active SUPER_ADMIN
- Super Admin route authorization
- current Super Admin password re-authentication
- dedicated reveal rate limit
- audit event
- no-store response

Normal Inertia props contain only whether a secret is configured, never the secret value. Audit payloads use the shared redaction service.

CI now runs Composer audit and repository secret-material guardrails and rejects tracked .env/private-key material.

## Payment/webhook hardening

### Midtrans
The notification signature is checked first. Before a callback is allowed to change local payment/order state, LFAMILIA performs a server-to-server Midtrans GET Status challenge using the encrypted server key. The authoritative status response must match the stored merchant reference and amount. Callback duplication remains idempotent.

### DOKU
Signed notifications additionally require a bounded Request-Id and a request timestamp inside the allowed replay window. Direct API responses also require a fresh Response-Timestamp before the response signature is accepted.

### Digiflazz
M8 HMAC-SHA1 webhook verification, Hookshot source marker, stored ref_id identity checks and callback deduplication remain active.

Final order/payment states remain monotonic; old callbacks cannot reopen or downgrade a final state.

## Logging and audit

Raw OAuth/provider exceptions are not dumped into application logs where they could carry request details. Security-relevant errors log safe metadata such as exception class while the global correlation ID remains available in log context.

Catalog, payment and fulfillment Admin audit writers now share AdminAuditService, so secret redaction and request correlation behavior are consistent.

## CI security gates

The rebuild workflow additionally:
- runs composer audit,
- rejects a tracked .env,
- rejects tracked private-key headers,
- scans application/config/routes/resources for likely hardcoded secret assignments,
- validates Laravel config cache/clear,
- then runs the existing syntax, MySQL migration rollback/reapply, frontend build, PHPUnit and Pint gates.

## Milestone boundary

M10 does not configure Cloudflare WAF/rate-limit rules or production server headers. Those runtime/edge settings are applied and validated in M12.

M11 remains responsible for the full cross-system security/abuse/browser regression campaign.
