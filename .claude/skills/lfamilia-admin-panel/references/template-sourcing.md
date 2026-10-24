# Evaluating a third-party admin template

When the owner says "cari templat buat panel admin bisa ga?", the job is a shortlist he can choose from — not a rewrite. Answer with candidates, a recommendation, and the reason the current panel is broken; then stop.

## What is already compatible

`rebuild/components.json` exists and declares `style: new-york`, `ui: @/Components/ui`, `css: resources/css/app.css`. The dependency set is the shadcn-vue stack: `reka-ui`, `class-variance-authority`, `clsx`, `tailwind-merge`, `tw-animate-css`, `@lucide/vue`, Tailwind 4, Vite 7, `@inertiajs/vue3`. Existing UI primitives live in `resources/js/Components/ui/{badge,button,card,dropdown-menu,input,sheet,table,textarea}`.

Consequence: **anything from the shadcn-vue ecosystem drops in**; anything built on vue-router + pinia + a separate API client does not — only its markup and class recipes transfer. Check `package.json` and the router before recommending a fork.

## Candidates, verified at source

Verify each one with the GitHub API rather than a listicle — stars, `license.spdx_id`, `pushed_at`, and the real tree (`/git/trees/<ref>?recursive=1`) tell you whether it is alive, MIT, and shaped like an admin panel.

| Repo | Notes |
|---|---|
| `Whbbit1999/shadcn-vue-admin` | MIT, ~460★, active. Same stack (shadcn-vue + TanStack table/query, pinia, vue-router). Sidebar, data-table, billing, auth, settings pages. Closest match; copy patterns, not the router. |
| `TailAdmin/vue-tailwind-admin-dashboard` | MIT, ~310★, Tailwind 4 + Vue 3, 50+ components, ecommerce dashboard. vue-router + pinia. |
| `TailAdmin/tailadmin-free-tailwind-dashboard-template` | MIT, ~2.3k★, HTML/Tailwind reference for layout density. |
| `tailwind-admin.com/vue` | Open source with a live preview; unverified contents — open the preview before quoting it. |

Paid and therefore not recommended for this repo: Haze Dashboard, shadcnadmin.com. `laravel/vue-starter-kit` is auth scaffolding, not an admin panel.

## When the owner rejects the shadcn look

"Shadcn itu kebanyakan kotak kotak doang kan" is correct and worth agreeing with plainly: shadcn is deliberately neutral (thin borders, uniform cards, one accent), which is why it is easy to theme and why it reads as a stack of grey boxes when nobody themes it. Answer the objection, not the shortlist:

- **Characterful, MIT, and alive** — `vbenjs/vue-vben-admin` (~33k★, dense enterprise: dark sidebar, tab bar, breadcrumb, heavy tables), `primefaces/sakai-vue` (~1.1k★, PrimeVue components), `vuestic/vuestic-admin` (accent colour instead of all-grey), `coreui/coreui-free-vue-admin-template` (~3.4k★).
- **State the cost in the same breath.** The first three mean swapping the stack (PrimeVue / vue-router / pinia), not copy-paste, across 26 working pages. Say that in one sentence and give the middle path: keep shadcn, add character — coloured sidebar, stronger accent, larger type, real table headers — instead of a uniform card grid.
- **Never sell a template as the fix for breakage.** The visible faults come from the repo's own cascade (see the rule below), so a swap reproduces them with new class names.

## Do not keep shortlisting

A template question is answered once. After the owner pushes back on the shortlist, or after he says the result is simply broken, stop producing candidate tables and fix the cascade — see the reporting rules in `SKILL.md` ("a quality complaint is a work order"). A second comparison table after a rejection is the failure this file exists to prevent.

## Verification commands

```bash
curl -sL -m 25 https://api.github.com/repos/<owner>/<repo> \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d["full_name"],d["stargazers_count"],(d.get("license") or {}).get("spdx_id"),d["pushed_at"])'

# tree: save to a file first — piping straight into json.loads trips on control chars in descriptions
curl -sL -m 40 'https://api.github.com/repos/<owner>/<repo>/git/trees/main?recursive=1' -o /tmp/tree.json
```

Use `raw.githubusercontent.com/<owner>/<repo>/main/package.json` for the dependency list, and the browser for the live demo (open it, screenshot, read it) — a demo that fails to load is not evidence of anything about the template.

## The rule that decides the answer

A template is a component source, not a fix. In this repo the visible breakage comes from `admin.css` carrying two theme systems where the legacy light block still wins, and from portals (sheet, popover, mobile nav) rendering outside the themed scope. Dropping a template onto that cascade reproduces the same failure with different class names. Say this in one plain sentence, then give the owner numbered options: fix the cascade first with the template as the visual reference, or build a side-by-side comparison of the candidates first.

Do not start either option until he picks — a proposal he did not ask for reads as ignoring him.

## Build the dummy

When he answers "buat template dummy dulu aja biar saya lihat hasilnya", stop evaluating and build a throwaway app **outside the repo** (`/home/hatch/workspace/admin-template-dummy`), using the official `unovue/shadcn-vue` registry. The repo stays untouched; the dummy is a visual reference he can judge on his phone.

`npx shadcn-vue@latest init` and `add` both fail against the live registry (the CLI requests `https://shadcn-vue.com/init?...&style=nova`, which 404s). Fetch the component JSON directly — without a browser UA the CDN answers 403:

```bash
npm create vite@latest admin-template-dummy -- --template vue-ts && cd admin-template-dummy
# package.json: add reka-ui class-variance-authority clsx tailwind-merge tw-animate-css
#                @lucide/vue @vueuse/core lucide-vue-next + tailwindcss @tailwindcss/vite (dev)
npm install --no-audit --no-fund

mkdir -p /tmp/reg && cd /tmp/reg
for n in utils button table input badge card dialog sheet select label tabs sidebar \
         dropdown-menu separator skeleton tooltip avatar checkbox switch textarea \
         breadcrumb scroll-area alert; do
  curl -s -m 20 -A "Mozilla/5.0" "https://shadcn-vue.com/r/styles/default/$n.json" -o "$n.json"
done
```

Then write each `files[].content` to `src/<files[].path>` (paths arrive as `ui/<name>/*.vue` and `lib/utils.ts`). Three fixes are always needed afterwards:

- `src/lib/utils.ts` imports `Updater` from `@tanstack/vue-table`, which is not installed — drop that import and loosen `valueUpdater`'s generic.
- The registry's `SheetContent`/`SelectTrigger` import from `lucide-vue-next`, not `@lucide/vue` — install `lucide-vue-next` (or rewrite the imports).
- Wire Tailwind 4 (`@tailwindcss/vite` plugin, `@import "tailwindcss"`, an `@theme inline` map) and the `@/*` alias in both `vite.config.ts` and `tsconfig.app.json`; copy the sidebar `cssVars` block out of `sidebar.json`.

Build and serve:

```bash
node node_modules/vite/bin/vite.js build    # `npx vite build` trips the long-lived-server heuristic
cd dist && python3 -m http.server 8099 --bind 127.0.0.1
```

Keep screenshots in the project (`shots/`), not `/tmp` — the temp tree can be cleared between calls and the images disappear before they are sent.

What makes the dummy read as designed rather than as slop: 14px base, `font-variant-numeric: tabular-nums` on every number and date, uppercase table headers on a muted band, nominal column right-aligned, semantic status badges (tint + border, not solid rainbow), a filter bar that is one aligned row, pagination with a real "menampilkan x–y dari z" line, and **the table replaced by a card list below 768px** (`hidden md:block` table + a `md:hidden` card list), with 40–44px tap targets on phones. Every edit opens the right-hand `Sheet`, never an inline row editor.

## Reviewing the owner's own prototype

The owner also hands over a **single-file HTML prototype he authored himself** — `~/workspace/your_files/template-panel-admin-lfamilia-store/template-panel-admin-lfamilia-store.html`, roughly 300 KB, one `<style>` block, ~23 `section.page` screens switched by JS, no build step. It is an explicit owner artifact, so it outranks older plans on look, wording and information density; it is also a **mockup only**, where every control fires a `data-dummy` toast and nothing implies a backend capability.

Workflow for "baca file itu / lihat file itu, diskusi dulu":

1. `md5sum` it against the other cached copies first — Telegram re-sends land at a new path with identical bytes, and the hash settles in one command whether it is a new version. Copy it into the workspace next to the others so later sessions find it without the cache path.
2. Read it by Python character slicing, not `sed`/`grep`: packed CSS plus long markup lines make line-oriented output collapse to an unusable single line. For structure, the browser is faster than the file — count `section.page`, read the nav groups, list `data-dummy` / `data-goto` handles.
3. Open it over `file://` in the real browser and screenshot 1440 and 390. Judge both widths; keep the images in the prototype folder (`shots/`) and send them.
4. Read `resources/css/admin.css` tokens before claiming a difference. The prototype may be light canvas + near-black sidebar + one lime accent while the shipped panel is dark navy with a blue accent — that is a **direction change**, and saying so plainly is the value of the review.
5. Report in this order: what the file contains, how it differs from what ships, your own measured observations (density, what misaligns at 390px, charts without an axis), then the one decision that unblocks the build ("ikut terang seperti template, atau tetap gelap tapi ambil tata letak + kerapatannya?"). No repo edits until he answers.
