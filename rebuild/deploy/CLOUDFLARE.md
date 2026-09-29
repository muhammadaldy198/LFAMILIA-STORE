# M12 Cloudflare Baseline

Cloudflare stays in front of the VPS. The origin must not be treated as the public security boundary.

## DNS

- Apex `lfamiliastore.my.id`: A record to the VPS public IPv4, proxied.
- `www.lfamiliastore.my.id`: CNAME to the apex (or A record to the same VPS), proxied.
- Do not recreate retired relay hostnames unless a future PRD explicitly requires them.
- Mail/DKIM/SPF/DMARC records are preserved and are not proxied.

## SSL/TLS

- Encryption mode: Full (strict).
- Minimum TLS: 1.2.
- TLS 1.3: enabled.
- Always Use HTTPS: enabled.
- Origin must have a valid CloudPanel/Let's Encrypt or Cloudflare Origin certificate.
- `APP_URL` must remain HTTPS and `SESSION_SECURE_COOKIE=true`.

## Edge security

- Keep Cloudflare proxy enabled for customer and Admin traffic.
- Turnstile keys remain configured through Super Admin -> Integrasi; never commit the secret.
- Application rate limits remain authoritative. Edge rate limiting/WAF should additionally protect authentication, checkout, payment creation, order lookup, wallet top-up, and Admin login routes.
- Do not cache authenticated, checkout, payment, webhook, Admin, or account responses.
- Preserve request headers needed for Laravel to determine the original HTTPS scheme and client IP.

## Verification after DNS cutover

1. `https://lfamiliastore.my.id/health/ready` returns `{"status":"healthy"}`.
2. The certificate presented publicly is valid and HTTPS redirect is active.
3. Apex and `www` both reach the same Laravel rebuild.
4. Direct origin access is not used by customers.
5. Customer login/register, Admin login, checkout and callback endpoints are not cached.
6. `deploy/healthcheck.sh` succeeds through both public edge and local origin paths.
