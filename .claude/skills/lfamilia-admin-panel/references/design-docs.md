# Design document inventory — LFAMILIA admin panel

Read all of these before answering a "does it match the plan" question. Order matters: later wins.

## Design plans (`docs/superpowers/plans/`)

| Document | Governs | Notes |
|---|---|---|
| `catalog-admin-redesign` | Backend catalog: pricing rules, bulk import, sync, one SKU per nominal | Not a UI plan. Still authoritative for catalog/pricing behaviour. |
| `ui-visual-redesign` | First attempt at a design system (tokens + base components) | Superseded. Stops at task 1. |
| `global-admin-visual-redesign` | Extends the above to all admin + storefront | Superseded. |
| `enterprise-ui-redesign` | Data-dense Linear/Vercel look, dark, hairline borders, tabular numbers | Its *aesthetic* survived into the winning plan; its "17 pages" count did not. |
| `admin-panel-total-redesign` | **The page-by-page spec.** 22 sections, one per admin screen | Primary reference for layout and information order. |
| `admin-panel-implementation-plan` | **The how.** 21 tasks mapping spec sections to files | Declares the spec above as its source. |
| `qa-owner-findings-*` | Owner's own QA notes | Small, current, always applies. |

## Current-state documents (repo root and `docs/`)

- `ADMIN.md` — menu list, RBAC, pricing rules, Digiflazz sync, workspace behaviour. Describes what exists now.
- `ARCHITECTURE.md`, `CHECKOUT.md`, `PAYMENT.md`, `FULFILLMENT.md`, `PRODUCT.md`, `DATABASE.md`, `SECURITY.md`, `AUTH.md`, `CUSTOMER.md` — runtime contracts. Do not change them for UI work.
- `docs/admin-panel-restoration.md`, `docs/admin-orders-parity.md`, `docs/admin-dashboard-parity.md`, `docs/admin-legacy-feature-parity.md`, `ADMIN-UI-AUDIT.md` — historical snapshots, each headed with a HISTORICAL SNAPSHOT banner. Useful for *why* something exists; never for *what is missing now*.

## Conflicts already settled

| Topic | Older doc | Winner |
|---|---|---|
| Admin theme | light/white | **dark** (`#09090b` base in `admin.css`) |
| Page count | 17 | **22 sections** in the spec (26 live routes) |
| Category management | tab inside Catalog | **its own menu** (not yet implemented) |
| Popular products setting | Promo menu | **Product → Tampilan Toko** |
| Sidebar | flat 18-item list | **10 working-area groups** |

## Capability gaps no design plan closes

These come from the legacy feature-parity audit and are backend work, not UI:

- Creating and deleting payment channels from the panel (editing existing ones works).
- Reviewing customer wallet top-up proof uploads.
- Admin list views for top-ups and wallet ledger.

Report these separately. Do not invent a screen for them.

## Known unimplemented spec items

- Advanced markup hidden by default (currently open in two places on the Product page).
- Category as its own menu rather than a tab.
- A "Kolom Data Pelanggan" tab that the spec never asked for.
- Price/modal/source shown side by side in the nominal editor.
- Pop-up editors rolled out to every page (several still use inline forms).
- Best-selling products relocated to the Product page (currently removed from Dashboard and not yet added anywhere except Reports).
