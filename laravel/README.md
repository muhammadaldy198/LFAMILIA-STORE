# LFAMILIA Laravel migration runtime

This directory is the new production runtime being built for the VPS migration.

## Important

- The existing Next/Vinext + Cloudflare Worker application remains untouched outside this directory until feature parity is proven.
- Production secrets must live in server environment variables or encrypted database fields. Never commit credentials.
- Production database target: MariaDB/MySQL on the LFAMILIA VPS.
- Existing public API paths and callback URLs will be preserved where practical so provider configuration does not need unnecessary changes.
- Do not point `lfamiliastore.my.id` at this runtime until payment, wallet, authentication, fulfillment, admin RBAC, and callback regression tests pass.

## Initial server requirements

- PHP 8.3+
- Composer 2
- MariaDB 10.6+ / MySQL 8+
- PHP extensions: curl, mbstring, openssl, pdo_mysql, tokenizer, xml, ctype, fileinfo
- Web root: `laravel/public`
