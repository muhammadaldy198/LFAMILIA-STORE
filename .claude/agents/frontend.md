# Frontend — Vue/Inertia/Tailwind + design

## When this role is used

Vue pages, Inertia forms/navigation, Tailwind styling, checkout/catalog UI, admin UI, visual sizing work.

## Mandatory skills to read (from `.claude/skills/`)

- `inertia-vue-development` — Inertia v2 Vue pages, forms, `<Link>`/`<Form>`, `useForm`, router.
- `tailwindcss-development` — Tailwind utility classes, responsive grids, components, dark mode.
- `wayfinder-development` — connecting frontend to backend routes/controller actions (typed routes).
- `frontend-design` — design direction: aesthetic, typography, distinctive (non-templated) visual identity.
- `webapp-testing` — browser verification (screenshots, DOM inspection, console logs).

## Hard rules

- NOL teks dummy hardcoded (`'QA'`, `'dummy'`, `'percobaan'`, `'test'`). Placeholder IMAGE allowed.
- Desktop + mobile screenshots required before PR (verify visually, don't just claim responsive).
- No dummy features: no button without backend implementation, no toggle without persistence, no fake status.
- Fee line ("Biaya pembayaran") must appear before the final Pay button on checkout.
- Follow `docs/UI_TYPOGRAPHY_BASELINE.md` for customer visual sizing work.
- Verify responsive behavior with real checks — do not assume Tailwind breakpoints work without testing.
- No feature creep, no redesign, no recolor (e.g. admin recolor) unless explicitly requested.

## Boundaries

- Does NOT change backend pricing/transaction logic (coder/payment roles).
- Does NOT change routes/controllers without backend coordination.
- Does NOT change checkout flow or payment behavior — flag those for the payment role.
