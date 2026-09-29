# VPS network security baseline

LFAMILIA's public origin uses two layers:

1. UFW only exposes TCP 22, 80 and 443. Internal Node, MariaDB and Laravel listeners stay on loopback.
2. Nginx's production vhost additionally restricts HTTP/HTTPS origin access to Cloudflare edge ranges plus localhost.

Fail2Ban protects SSH because password login remains available during the migration. The checked-in jail template bans repeated SSH failures and uses UFW as its ban action.

Apply the rules only from an already-working privileged session so SSH cannot be locked out:

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment SSH
ufw allow 80/tcp comment HTTP
ufw allow 443/tcp comment HTTPS
ufw enable
```

Install `fail2ban/lfamilia.local` as `/etc/fail2ban/jail.d/lfamilia.local`, run `fail2ban-client -t`, then restart Fail2Ban.

Do not disable root/password SSH until a separate privileged account and its key login have been verified from another session. That change is intentionally outside the automatic migration steps to avoid locking out the operator.
