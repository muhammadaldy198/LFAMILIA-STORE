# M12 Production Deployment

This directory is the production deployment baseline for the Laravel 12 / PHP 8.4 / MySQL 8 / Redis rebuild.

## Server-side files that are never committed

- `rebuild/.env`: Laravel infrastructure configuration only. Provider/payment credentials remain managed through Super Admin -> Integrasi.
- `/etc/lfamilia/ops.env`: deployment paths, service users, health URLs, backup policy and paths.
- `/etc/lfamilia/mysql-backup.cnf`: least-privilege MySQL backup credentials.
- `/etc/lfamilia/mysql-admin.cnf`: MySQL credentials allowed to create/drop only the temporary restore-verification database.
- `/etc/lfamilia/backup.pass`: strong backup encryption passphrase.
- `/etc/lfamilia/rclone.conf`: external/object-storage credentials when rclone requires them.

All secret files should be owned by root (or the dedicated service user that needs them) and mode `0600`.

## Deployment order

1. Create the CloudPanel site and configure PHP 8.4.
2. Install MySQL 8.x (8.0 or 8.4; MariaDB is not accepted for this PRD), Redis, Composer, Node/npm, and PHP extensions including `pdo_mysql` and `redis`.
3. Clone this repository to the server and keep `main` as the production ref.
4. Copy `production.env.example` to `rebuild/.env`, fill infrastructure values, and generate `APP_KEY`.
5. Copy `ops.env.example` to `/etc/lfamilia/ops.env` and fill paths/user/backup storage settings.
6. Configure the CloudPanel vhost to point to `rebuild/public`; keep CloudPanel's generated PHP-FPM block.
7. Configure Cloudflare apex/`www` proxy and Full (strict) TLS so the public health URL can reach this VPS.
8. Run `bash deploy/install-systemd.sh` as root.
9. Run `bash deploy/preflight.sh`.
10. Run `bash deploy/deploy.sh`.
11. Re-run `bash deploy/healthcheck.sh` and verify backup/restore timers.

## Source rollback

Use `ROLLBACK_REF=<known-good-sha> CONFIRM_ROLLBACK=ROLLBACK_SOURCE_ONLY bash deploy/rollback-source.sh`.
This intentionally does not roll database migrations backward.

## Safety rules

- Production preflight rejects MariaDB/non-MySQL-8 databases and requires Laravel cache, queue, and session to use Redis.
- Deployment aborts on a dirty Git worktree.
- Git updates use fast-forward only.
- The application enters maintenance mode before dependency/build/schema changes.
- A failed deploy intentionally leaves maintenance mode enabled instead of serving a half-upgraded application.
- Database migrations run with `--force`; schema rollback is never automated during source rollback.
- Queue and scheduler are restarted only after dependencies, build and migrations succeed.
- Health is checked through both the Cloudflare/public path and the local origin path.
- Live provider/payment credentials must never be copied into this repository.
