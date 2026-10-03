# LFAMILIA STORE — AI Coding Rules & Project Guardrails

This is the canonical repository-level instruction file for AI coding agents and developers working on LFAMILIA STORE.

It defines project invariants, ownership boundaries, safety rules, and required working methods. It does not replace the PRD or the detailed runtime/operations documentation. When a task conflicts with this file, stop and verify the current repository, documentation, and explicit task requirement before changing high-risk behavior.

Last verified against the current Laravel runtime and Tahap 4 documentation on 2026-10-04.

## 1. Project identity

Project: LFAMILIA STORE.

Active application runtime:

- Laravel 12
- PHP 8.4
- Vue 3
- Inertia.js v2
- Tailwind CSS v4
- MySQL 8
- Redis for cache, queue, and session
- Nginx on VPS managed with CloudPanel
- Laravel Fortify + Sanctum
- Spatie Media Library
- Cloudflare at the edge for DNS/proxy, Access, and perimeter security

The active application is under rebuild/.

The production architecture is VPS + CloudPanel + Nginx + Laravel. Cloudflare Workers, D1, Pages, old relay infrastructure, old Next/Vinext/Drizzle runtime, and MariaDB are not the active production runtime.

Do not reintroduce legacy runtime dependencies without a new explicit requirement and a verified migration plan.

## 2. Tool ownership is mandatory

Use the correct operational surface:

- REPO / CODE / BRANCH / PR / CI → @GitHub
- CLOUDFLARE / DNS / ACCESS / PROXY / SSL EDGE → @Cloudflare
- VPS / DEPLOYMENT / SERVER / SERVICES → @Remote Desktop Commander

Never use the VPS as a substitute for normal GitHub repository work.

Never use GitHub to pretend a server configuration has changed.

Never use an alternate Cloudflare path when direct @Cloudflare access is available for the task.

Never claim that a change succeeded without tool output or repository/runtime evidence confirming it.

A failed, unavailable, timed-out, or ambiguous tool call is not success.

## 3. Source of truth hierarchy

For actual implementation behavior, prefer evidence in this order:

1. Current main source code, migrations, tests, and verified runtime configuration.
2. Current operational documentation in rebuild/ produced and corrected during Tahap 4.
3. Product intent in docs/PRD-LFAMILIA-STORE-v1.md.
4. Historical planning documents only for historical context.

docs/REBUILD-PLAN.md is explicitly historical and must not be treated as current implementation status.

If PRD and code differ, do not arbitrarily choose one. Determine whether the difference is:

- a newer product decision;
- an implementation gap;
- stale documentation;
- a bug;
- an intentionally deferred capability.

For payment, wallet, fulfillment, auth, permissions, pricing, security, database, or production behavior, report a material conflict before making a broad behavioral change.

## 4. Must-read project references

Before substantial work, read the references relevant to the task:

- README.md
- rebuild/README.md
- rebuild/ARCHITECTURE.md
- rebuild/DATABASE.md
- rebuild/AUTH.md
- rebuild/PRODUCT.md
- rebuild/CHECKOUT.md
- rebuild/PAYMENT.md
- rebuild/FULFILLMENT.md
- rebuild/ADMIN.md
- rebuild/SECURITY.md
- rebuild/TESTING.md
- rebuild/deploy/README.md
- docs/PRD-LFAMILIA-STORE-v1.md
- docs/UI_TYPOGRAPHY_BASELINE.md for customer visual sizing work
- .github/workflows/validate-rebuild.yml for CI behavior

Read actual code as well as documentation when changing transaction or security behavior.

## 5. Secret and credential rules

Hardcoded secrets are forbidden.

Never hardcode or commit:

- API keys;
- provider secrets;
- OAuth secrets;
- webhook secrets;
- payment secrets;
- encryption keys;
- SMTP/API credentials;
- passwords;
- tokens;
- private SSH keys;
- backup encryption material;
- production database credentials.

Keep the boundaries clear:

Repo/code:
- protocol definitions;
- schema and migrations;
- validation;
- service implementations;
- adapter logic;
- business logic;
- non-secret source-controlled defaults.

Server ENV / server-only operational files:
- Laravel/infrastructure bootstrap values;
- DB/Redis connectivity;
- service names;
- deploy paths;
- health URLs;
- backup paths/policy;
- other infrastructure settings that belong on the server.

Super Admin → Integrasi:
- provider/application credentials managed by the application.

Never commit .env.

Never put real secrets in Markdown, screenshots, fixtures, logs, exception text, test snapshots, PR comments, issues, or GitHub Actions output.

Tests may use disposable non-production dummy credentials only.

Do not expose encrypted credential ciphertext as if it were safe public data.

## 6. Business logic versus Admin settings

Not every behavior belongs in the Admin panel.

Keep these in code unless architecture explicitly says otherwise:

- protocol rules;
- state machines;
- transaction safety;
- validation semantics;
- callback verification;
- retry/reconciliation algorithms;
- security behavior;
- provider adapter behavior;
- concurrency controls;
- authorization rules.

Admin-configurable areas may include:

- credentials;
- enable/disable flags;
- route/provider priority;
- margin and commercial settings;
- content;
- storefront presentation settings;
- supported routing/business configuration designed to be editable.

Do not create raw-code editors or arbitrary JSON editors in Admin merely to make behavior “configurable”.

Do not duplicate the same configuration in multiple Admin menus.

## 7. Payment code is high risk

PAYMENT CODE IS HIGH-RISK.

Do not simplify, broadly refactor, or rename away payment states without understanding the current state machine and tests.

Preserve these invariants unless an explicit reviewed requirement changes them:

- payment idempotency key and request fingerprint protections;
- a single active/non-rejected payment path for the same order/top-up where the service enforces it;
- single-send claim before creating an external payment;
- internal creation states such as CREATING and SENDING;
- UNKNOWN for ambiguous external results;
- callback/provider verification before state transition;
- callback/event deduplication and replay protection;
- terminal-state and stale-callback protection;
- amount/reference consistency checks;
- browser redirect is never proof of payment;
- concurrent callbacks/requests must remain safe;
- payment retry must not create double charge;
- database transactions, row locks, and conditional updates used as concurrency controls must not be replaced with naive read-then-write code.

Do not convert ambiguous timeout/network failure into “failed” merely to make recovery easier.

Any payment change requires targeted regression coverage and the relevant full test gates.

## 8. Payment routing boundary

Customer chooses a public payment channel/method.

Backend chooses the eligible gateway/route.

Current routing semantics include:

- active channel;
- active route;
- active gateway;
- gateway not in maintenance;
- support for the requested purpose;
- required credential readiness for external gateways;
- smaller payment_routes.priority first, then ID.

Example: priority 10 is preferred before 20 when both are eligible.

Pre-request route selection is not the same as post-request failover.

Once an external request has been claimed/sent and the result is ambiguous, do not blindly create a payment with a different gateway.

Reconcile the existing transaction first.

## 9. Order safety

Preserve:

- checkout idempotency key + fingerprint;
- prevention of duplicate order creation from repeated submission;
- server-side price calculation;
- frontend totals/prices are not authoritative;
- backend voucher, membership/tier, fee, package, and route validation;
- immutable order financial/catalog/provider snapshot after order creation;
- guest and registered-customer access separation;
- supported order state machine.

Do not trust customer-supplied provider mapping, external SKU, buyer_sku_code, payment route, cost, or total.

Do not mutate an old order snapshot because current catalog/provider pricing changed later.

## 10. Wallet safety

Wallet/saldo is a financial ledger.

Preserve:

- MySQL transactions;
- lockForUpdate or equivalent concurrency protection already used;
- ledger records;
- idempotent credit/debit where the flow requires it;
- non-negative balance invariant;
- auditable before/after balances and references;
- safe refund/review behavior.

Never use frontend balance as source of truth.

Do not change balance with a concurrent-unsafe read → calculate → save pattern.

Guest customers do not use wallet.

Do not “simplify” locking or ledger behavior.

## 11. Fulfillment safety

One LFAMILIA package/nominal may have multiple provider_mappings.

Current automatic selection semantics include:

- lower priority first;
- then lower cost;
- then mapping ID;
- unusable mappings may be skipped before an external request.

For Digiflazz, mappings may have distinct external SKU / buyer_sku_code values.

After an external request:

- PENDING must not trigger immediate next-source fulfillment;
- SENDING must not trigger immediate next-source fulfillment;
- UNKNOWN must not trigger immediate next-source fulfillment;
- timeout/network ambiguity is not proof that the provider did not process the request.

Reconcile using the same stored attempt/reference.

A next source may be used only after the previous path is definitively safe for failover, such as FAILED_CONFIRMED with the required safe-to-failover condition, or when a candidate was safely skipped before any external request.

Primary objective: NO DOUBLE FULFILLMENT.

Do not retry the same attempted mapping blindly.

Do not rewrite the order snapshot cost/total merely because a later mapping is selected.

Do not treat multi-source Digiflazz routing as universal failover for every possible provider.

## 12. Provider and external-request rules

All external requests must account for:

- timeout;
- retry semantics;
- idempotency/reference identity;
- signatures;
- response verification;
- ambiguous network outcomes;
- safe logging;
- reconciliation.

A network timeout does not prove the external provider failed to process a request.

Never create a second financial/fulfillment action merely because the first request timed out.

## 13. Integration rules

The current IntegrationRegistry contains code/panel profiles for:

- Digiflazz;
- KokinPay / account validation;
- Midtrans;
- DOKU;
- Resend;
- Google OAuth;
- Telegram;
- Discord;
- Cloudflare Turnstile.

Code support does not mean production credentials are configured.

A connection-test UI does not prove a live transaction has passed end-to-end acceptance.

Do not run a real provider transaction without explicit authorization for that exact test.

Do not use live credentials casually for development or CI.

Integration secrets belong in Super Admin → Integrasi and the encrypted credential flow, not hardcoded application config.

## 14. Customer nickname/account validation

Not every game/product supports nickname checking.

Nickname checking is controlled by actual product/game-code configuration.

If nickname checking is disabled or the game code does not support it, checkout must not invent a mandatory upstream nickname requirement.

For a supported enabled checker:

- validate required local user/server fields;
- an invalid ID/server response may block with validation error;
- a successful upstream response with no usable nickname is treated as invalid by the current implementation;
- if the upstream checker itself is unavailable, current NicknameService returns a warning and allows the flow to continue instead of treating service outage as invalid customer data.

Do not expose the nickname provider name or secret to the customer response.

Do not change these semantics without updating tests and product documentation.

## 15. Admin RBAC and customer tier are different systems

Never mix Admin authorization with customer membership.

Admin roles currently supported:

- SUPER_ADMIN
- ADMIN

ADMIN uses granular server-side permissions.

SUPER_ADMIN receives full access and super-only operations.

Customer membership tiers:

BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA

MAFIA is not an Admin role.

Never authorize an Admin route based on customer tier.

Never grant customer commercial benefits through Admin RBAC.

Vue menu visibility is presentation only; server-side authorization remains mandatory.

## 16. Auth rules

Customer auth currently uses:

- email/password;
- Fortify web session;
- register/login/logout;
- email verification;
- forgot/reset password;
- password change;
- Google OAuth through Socialite when configured;
- profile/account flow;
- Sanctum for protected account API endpoints where implemented.

Admin auth is separate from customer auth.

Phone number is required where the current application flow requires it.

WhatsApp OTP is not used.

Do not reintroduce paid WhatsApp OTP from historical requirements.

Do not use unofficial WhatsApp Web automation as an OTP substitute.

## 17. Frontend rules

Customer frontend work is mobile-first for review and regression safety. Preserve correct existing behavior before aesthetic cleanup.

Desktop has its own responsive sizing/typography and must not be treated as a scaled copy of mobile.

Use docs/UI_TYPOGRAPHY_BASELINE.md and current CSS/components as the visual baseline when relevant.

Do not change a UI that is already correct without a requirement.

Avoid visual regressions, especially checkout, payment method cards, banner/media, account navigation, footer, and responsive controls.

Before a large visual refactor, inspect the current implementation and available reference screenshots/design requirements.

## 18. Admin UI rules

Admin uses its current shadcn-style Vue/Inertia workspace conventions. Do not apply customer visual styling globally to Admin.

Use natural operator-facing Indonesian labels where possible.

Do not surface raw implementation jargon when the operator does not need it.

Do not create:

- dummy buttons;
- toggles with no backend behavior;
- fake connection status;
- fake success response;
- controls that are visually interactive but do nothing.

Checkboxes/toggles must reflect and persist a real value through the implemented backend flow.

## 19. CSS rules

Prefer fixing the correct shared/global rule when a real global rule is the cause.

Avoid:

- duplicate selectors without need;
- specificity wars;
- excessive !important;
- arbitrary inline styles;
- random breakpoints;
- copy-pasted one-off sizing that conflicts with established tokens/baselines.

Use existing design/global tokens and responsive conventions.

Do not refactor the entire stylesheet merely for source-code aesthetics.

A visual regression is worse than a few additional lines of necessary CSS.

## 20. Database rules

Production database target: MySQL 8.

Use Laravel migrations.

Do not:

- edit production schema manually as normal development practice;
- delete old migrations;
- reset production database;
- run destructive migrations without backup/review;
- commit production SQL dumps;
- assume MariaDB behavior is equivalent to the current target.

Before creating a migration:

- inspect current migrations/schema;
- check for existing columns/indexes/constraints;
- consider existing data;
- consider lock/downtime characteristics;
- define a safe rollback where practical;
- preserve unique, foreign-key, and check constraints that encode invariants.

Concurrency safety is more important than making a query look simpler.

## 21. Legacy rules

LFAMILIA STORE is now a Laravel/VPS application.

Do not revive old runtime paths such as:

- Cloudflare Workers as the application runtime;
- D1 as the production business database;
- Cloudflare Pages as the primary application runtime;
- old relay infrastructure;
- old Next/Vinext/Drizzle runtime;
- old compatibility layers;

unless a new requirement explicitly requests it and the architecture is reviewed.

Search first before adding a dependency that looks legacy.

## 22. Security baseline

The Tahap 4 security baseline records the production perimeter as:

- SSH public-key authentication enabled;
- SSH password authentication disabled;
- keyboard-interactive SSH disabled;
- root login key-only;
- Fail2ban active/enabled;
- UFW active;
- public HTTP/HTTPS origin access restricted to Cloudflare networks;
- CloudPanel 8443 restricted to Cloudflare networks;
- MySQL bound to localhost;
- Redis bound to localhost;
- unattended security updates active/enabled;
- Cloudflare proxy/Access used at the perimeter;
- strict TLS configuration at the Cloudflare edge/origin path described in rebuild/SECURITY.md.

Do not:

- open ports casually;
- disable UFW;
- disable Fail2ban;
- enable password SSH;
- expose MySQL or Redis to the Internet;
- remove Cloudflare Access simply to make testing easier;
- weaken SSL mode merely to suppress an error.

For any security change that could cause lockout, verify an alternate access path and rollback procedure before applying it.

Do not put current origin IPs, passwords, private keys, or access tokens in this file.

## 23. Code quality and test gates

Use the actual commands from the repository.

Do not:

- disable lint rules merely to get CI green;
- add broad eslint-disable directives without a narrowly justified reason;
- use @ts-ignore casually;
- skip failing tests;
- delete tests to make a PR merge;
- replace a meaningful check with a fake success.

Fix the root cause.

High-risk changes require targeted regression tests and the applicable full gates.

## 24. Dead-code rule

Do not delete code based on grep alone.

Check for:

- Laravel container resolution;
- routes;
- Inertia page resolution;
- events/listeners;
- jobs/queues;
- scheduler;
- configuration-driven classes;
- dynamic provider/adapter lookup;
- database-driven content/media;
- reflection/dynamic invocation where present.

If you cannot establish that code is unused, do not delete it.

## 25. Test-first for high-risk changes

For bugs or behavior changes in these areas, reproduce with a test first whenever practical:

- payment;
- wallet;
- checkout;
- callbacks/webhooks;
- fulfillment;
- provider routing;
- auth;
- permissions;
- vouchers;
- financial state transitions.

After the fix, the test must demonstrate that the regression is closed.

## 26. Git workflow

Do not develop directly on main.

Normal workflow:

main
→ focused branch
→ structured commit(s)
→ pull request
→ CI
→ review/fix
→ merge only when required checks are green.

Do not:

- force-push main;
- merge with failing required checks;
- “fix” CI by disabling tests/checks;
- mix unrelated features into one PR.

Commit messages must describe the real change.

## 27. Production deployment

Normal deployment is not manual file-by-file editing on production.

Follow rebuild/deploy/README.md.

Safe sequence:

1. PR merged to main.
2. Required CI green.
3. Encrypted DB backup completed for runtime/database-impacting deployment.
4. Run deploy/preflight.sh.
5. Run deploy/deploy.sh.
6. Run/confirm deploy/healthcheck.sh.
7. Verify queue/scheduler and relevant logs/services.

Use @Remote Desktop Commander for VPS/server work.

Do not deploy an unmerged PR.

Do not deploy a failing commit.

Do not assume deploy.sh automatically performs the backup; current documentation explicitly says backup-before-deploy remains an operator/runbook responsibility.

After deployment verify the deployed source ref/worktree and the applicable Nginx, PHP-FPM, MySQL, Redis, queue, scheduler, and healthcheck state.

Documentation-only changes do not require a production deployment solely for formality.

## 28. Cloudflare changes

Use @Cloudflare.

Do not modify DNS, Access, SSL, proxy, firewall, or other perimeter settings merely because ordinary application code was deployed.

Touch Cloudflare only when the task actually requires Cloudflare configuration.

Preserve the principle that the origin is not intentionally exposed directly when Cloudflare is the expected perimeter.

## 29. Production data

Never use real customer data as:

- fixture data;
- screenshots;
- public issue content;
- documentation examples;
- unit-test data.

Never print or publish:

- payment tokens;
- password hashes;
- secrets;
- callback signatures;
- raw sensitive provider payloads;
- private saved-game credentials;
- integration ciphertext dumps.

Use sanitized/dummy data.

## 30. No dummy features

Do not create production-looking UI for a backend capability that does not exist.

Forbidden examples:

- button without implementation;
- toggle without persistence/behavior;
- fake status;
- mock production metric;
- fake success response;
- placeholder capability that appears active.

If the backend is not implemented, do not make the UI pretend that it is.

## 31. No feature creep

Work only on the requested scope.

Do not casually combine a task with:

- broad redesign;
- major stack upgrade;
- architecture replacement;
- unrelated new service;
- additional provider;
- global refactor.

If another issue is discovered, record it as follow-up unless it is necessary to make the requested change safe/correct.

## 32. Error handling

Do not hide errors.

Never turn an exception path into a success response just to keep a flow moving.

Errors should be:

- correctly classified;
- logged safely;
- represented by the correct state;
- free of secret leakage;
- reconciled when an external operation has an ambiguous outcome.

Preserve explicit UNKNOWN/pending/review states where they protect transaction safety.

## 33. Observability

Logs should help operations correlate requests and state transitions without leaking secrets.

Prefer safe identifiers such as:

- internal record ID;
- order number where appropriate;
- correlation ID;
- provider reference when it is non-secret;
- state transition;
- sanitized error class/message.

Never log passwords, API secrets, access tokens, full credentials, private keys, or raw payment secrets.

## 34. AI working method

Before changing code or configuration:

READ → VERIFY → PLAN → CHANGE → TEST → REVIEW DIFF → PR → CI → MERGE → DEPLOY → VERIFY

Not every task reaches DEPLOY. Documentation-only work may correctly stop after merge verification.

Never use:

ASSUME → EDIT → CLAIM DONE

If a tool fails, do not claim success.

If behavior is uncertain, inspect repository code/tests/configuration first.

If runtime behavior is the question, verify runtime with the correct server/Cloudflare tool when the task scope allows it.

## 35. Documentation maintenance

When a change modifies any of the following, update relevant documentation in the same PR:

- commands;
- architecture;
- deployment;
- security;
- transaction state machine;
- payment/fulfillment behavior;
- Admin behavior;
- operational procedure.

Do not let Tahap 4 documentation become stale again.

Keep this CLAUDE.md focused on invariants and working rules; put deep implementation detail in the existing domain documents.

## 36. Verified command reference

Run repository application commands from rebuild/ unless stated otherwise.

Install dependencies:

    composer install
    npm ci

Frontend lint/static/build:

    npm run lint
    npm run check
    npm run build

PHP syntax:

    find app bootstrap config public routes tests -name '*.php' -print0 | xargs -0 -n1 php -l

Laravel/PHP tests:

    vendor/bin/phpunit

Composer script equivalent currently available:

    composer test

PHP style:

    vendor/bin/pint --test

Dependency security audit:

    composer audit --no-interaction

Browser smoke on a prepared test environment:

    bash tests/Browser/smoke.sh

Production/server readiness, only in the documented server environment:

    bash deploy/healthcheck.sh

Deployment preflight, only when deployment is in scope:

    bash deploy/preflight.sh

Do not invent replacement commands. Check rebuild/package.json, rebuild/composer.json, rebuild/TESTING.md, rebuild/deploy/README.md, and .github/workflows/validate-rebuild.yml when command behavior changes.

CI currently validates MySQL 8 + Redis behavior, migrations including rollback/reapply checks, frontend build/static checks, PHPUnit, browser smoke, Pint, Composer audit, secret guards, configuration cache, and deployment assets.

## 37. Migration rules

Before adding a migration:

- inspect existing migrations and schema assumptions;
- avoid duplicate columns/indexes;
- account for existing production data;
- consider table locks and downtime;
- preserve transaction/state invariants;
- plan rollback/forward compatibility.

A production migration is not merely “change a table”.

Do not combine destructive migration behavior with an unrelated feature PR.

## 38. Review diff before PR

Before opening a PR:

- inspect the complete diff;
- confirm only intended files changed;
- check that no generated secret/config file was added;
- check that no production dump or credential material was added;
- confirm no unrelated formatting churn;
- confirm tests/documentation match the behavioral change.

For documentation-only work, verify that runtime source files were not changed.

## 39. Local/pre-commit tooling boundary

Pre-commit hooks and local guardrail tooling are separate work.

Do not install Husky, lint-staged, Git hooks, or new local hook frameworks unless a task explicitly scopes that work.

Do not alter CI merely because this CLAUDE.md exists.

## 40. Completion standard

A task is not complete merely because code was edited.

For applicable work, completion requires evidence for:

- correct scope;
- tests/checks run;
- reviewed diff;
- PR created;
- CI result;
- merge result;
- deployment verification when deployment was actually required.

Never report a PR as merged when it is only open.

Never report production as deployed when only repository changes were merged.
