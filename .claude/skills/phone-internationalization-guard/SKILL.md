---
name: phone-internationalization-guard
description: "Rules for phone normalization and country dial codes."
version: 1.0.0
metadata:
  hermes:
    category: software-development
    tags: [phone, e164, normalization, constraints, codex]
---

# Phone Internationalization & Canonical Uniqueness

## Core Rules

1. **Keep Full E.164 Prefixes in Canonical Keys**:
   - In global datasets (e.g. 248 countries), always retain dial codes in database keys (e.g. `62812...`, never strip to local `812...`). Stripping country prefixes introduces cross-country namespace collisions (e.g. Japan `+81 2...` vs Indonesia `0812...`).

2. **Accurate Trunk Prefix Handling**:
   - Only strip leading zeros for countries that drop domestic trunk zeros internationally (e.g. UK +44, Indonesia +62, Australia +61).
   - Never strip leading zeros unconditionally for countries whose numbering plans mandate retaining them (e.g. Italy +39 06...). Maintain an explicit set of zero-retaining dial codes — currently `LEADING_ZERO_PRESERVED_DIALS` = `{'39' (Italy), '378' (San Marino)}`. San Marino uses dial `378`, not `39` — its fixed lines carry a significant leading zero (`+378 0549...`), so it must be in the same set even though it is not Italy.

3. **Prevent Prefix-Stripping from Local Numbers**:
   - Do NOT strip dial code prefixes just because local digits happen to start with the same digits as the dial code (e.g. India +91 with local number `9123...`). Only strip the prefix when explicitly parsing international input starting with `+` or an explicit dial prefix.

4. **Isolate Tracking Search & Variant Expansion**:
   - When expanding phone number search variants (e.g. public order tracking or customer lookup), never expand bare digit-only numbers starting with `8` (e.g. Japanese `8122...`) into domestic Indonesian `628...` or `08...` variants.
   - Only expand to local variations when the input explicitly contains an Indonesian indicator (`08...`, `628...`, or `+628...`). Crossing country namespaces leaks orders and tokens across users.
   - **Isolate BOTH directions.** A foreign bare `8...` input is never expanded into Indo variants; AND an Indonesian input (`628...`/`08...`/`+628...`) must never emit the bare local `8...` variant — only `0<local>`, `62<local>`, `+62<local>`. The bare `8...` form is also the canonical value of foreign numbers (Japan `+81` → `812...`), so emitting it from an Indonesian number re-opens the cross-country collision.
   - Ensure backend registration normalization (`normalizePhone()`) strictly mirrors tracking search variant expansion: never prepend `62` to bare `8...` inputs without an explicit domestic `0` or `+` prefix.

7. **Smart Dial Auto-Detection on Pasted Input**:
   - When the user pastes an international number starting with `+` into a country-picker-enabled input, auto-detect the longest matching country dial code (sort by dial length descending) and automatically switch the active country selection.
   - Strip the dial prefix from the local input field to avoid duplicated country codes in the payload.

8. **Preserve Bare International Numbers on Picker Hydration**:
   - When a stored canonical value is a digit-only international number (e.g. `81223456789` for Japan `+81`), the country picker's hydrate logic must NOT default to Indonesia and rewrite it to `+62...` on mount — that silently corrupts saved WhatsApp numbers the moment any profile field is saved.
   - On hydration, try to infer the country from the dial code (same longest-dial match as paste detection); if the value cannot be identified, render it as-is and do NOT emit an update to the parent.
   - Apply the identical inference in every component that composes a stored value (shared picker AND checkout summary).

5. **Precise SQL Error 1062 Key Constraint Matching**:
   - When catching duplicate key exceptions (MySQL error 1062), never use broad substring matching like `str_contains($msg, 'email')` or `str_contains($msg, 'phone')`.
   - The raw SQL query inside the exception message almost always lists all columns (`INSERT INTO users (..., email, phone, ...)`).
   - Use regex on the violated index name (e.g. `preg_match('/key [\'`"]?[^\'`"]*users_email_unique/i', $msg)`) to avoid misattributing phone collisions to email or vice versa.

6. **Mask PII in Migration and Operational Logs**:
   - Never dump raw customer phone numbers or emails in exception messages during backfill migrations.
   - Mask numbers (e.g. `substr($num, 0, 2) . '*****' . substr($num, -2)`) or report row counts only. Non-interactive migration logs are captured in CI and deployment consoles.
