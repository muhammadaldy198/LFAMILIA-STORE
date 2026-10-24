---
name: lfamilia-product-spec
description: "Use when rewriting the LFAMILIA PRD or panel spec."
license: MIT
version: 1.0.0
author: lfamilia
metadata:
  author: lfamilia
  source: LFAMILIA-STORE docs/, AGENTS.md, CLAUDE.md, migrations, public site
  hermes:
    tags: [lfamilia, prd, requirements, documentation]
    related_skills: [lfamilia-admin-panel, lfamilia-payment-guardian, lfamilia-digiflazz-specialist, technical-documentation]
---

# LFAMILIA Product Spec — writing the PRD and its companions

The owner periodically decides the product documents are stale and asks for a rewrite
("ulang PRD nya", "PRD keseluruhan websitenya"). This skill is how that rewrite is done:
what gets verified first, how the interview runs, what belongs in which file, and the
mistakes that cost trust.

## When to use

- Rewriting or splitting the PRD, writing an admin-panel spec, or trimming AGENTS.md / CLAUDE.md.
- Answering "aturan toko ini sebenarnya apa" / "apa lagi yang kurang" about product rules.
- Turning a pile of owner answers into binding rules.

Not for UI implementation (that is `lfamilia-admin-panel`), payment code
(`lfamilia-payment-guardian`), or provider behaviour (`lfamilia-digiflazz-specialist`) —
though all three read this document as the source of product rules.

## Mode: discussion first, work only on order

This owner runs long design conversations and gets angry — loudly, repeatedly — when the
agent starts touching the repo during one. Treat mode as a first-class state:

- **Discussion mode** is on by default for this class of work, and on whenever the owner
  says *diskusi dulu*, *bahas dulu*, *menurutmu*, *gimana*, or asks a question about the
  documents. In discussion mode: **zero tool calls.** Not a file read, not a `grep`, not a
  browser visit — "read-only" is still working, and a repo read during a discussion reads as
  ignoring the instruction. Answer from what is already known, say plainly what is unverified,
  and end with the decision needed.
- **Leave discussion mode only on an explicit order** (*gas*, *kerjakan*, *bikin*), and only
  for the scope named. "Gas 1 halaman dulu" licenses one page, not the redesign.
- When the owner asks a scoping question ("controller ini apa?"), answer the question. Do not
  attach a plan, a next step, or a status report to it.
- If you already broke this and got called out, stop completely, state in one line what was
  touched (or that nothing was), and wait. Do not apologise at length and do not resume.

## Precedence

1. Explicit owner instruction.
2. **Verified reality**: what the code, migrations, and the live site actually do.
3. The public legal/help pages (Kebijakan Pengembalian Dana, FAQ, Syarat & Ketentuan) —
   they are promises already made to buyers and outrank an internal note that contradicts them.
4. The current PRD.
5. Old plans, traceability matrices, archived specs.

When 2 and 4 disagree, the code is the truth and the PRD is the defect — except for the
"must never exist" list, where the document wins.

## Ground rules

**Verify before writing, from the source that owns the fact.** One read per fact beats a
paragraph of plausible prose:

| Fact | Where it actually lives |
|---|---|
| Which statuses exist | `AdminOrderPresentation::STATUSES` (18 internal), public copy on `/faq` |
| Which payment channels are live | `payment_gateways` / `payment_channels` seeders + `IntegrationRegistry` |
| Whether a feature is on | `system_settings` keys (`wallet.topup_enabled`, `digiflazz.low_stock_threshold`) — local copy only; see the local-vs-live pitfall |
| What is actually filled in for buyers | the live page, never the local `system_settings` row |
| Refund terms | live `/refund` page, not memory |
| Provider contract | provider docs (see `lfamilia-digiflazz-specialist`), not recollection |
| Roles and permissions | `AdminPermissionService::DEFINITIONS` |
| Routes and public pages | `routes/web.php` |

**Never invent a concrete example to make a question land.** A hypothetical written as a
fact ("provider confirmed failure on day 9", "say Rp 500.000") is read as a claim about the
system and gets "penyedia mana?" — after which the whole list is suspect. If an example is
needed, label it: "contoh karangan saya, bukan kasus nyata". Numbers you propose are
proposals; say so, or ask for the owner's number.

**Do not state a policy the owner has not stated and the site does not publish.** The
refund rule is the standing trap: the store HAS refunds, manual, governed by `/refund` —
never write "tidak ada refund", and never write an automatic refund path into the spec.

## Interview loop

1. Read the current document fully and the code paths it describes. Do not interview first
   and read later: half the questions dissolve once you look (a feature that is seeded
   `true`, a limit that already exists, a column already present).
2. Answer everything the code can answer yourself, and state the answer in one line rather
   than asking. Reserve questions for genuine business choices.
3. **At most three questions per message**, each with a recommended default and the
   consequence of the alternative. A wall of eight questions gets "apa lagi yang mau lu
tanyain".
4. When the owner answers, write the answers into the document immediately in the section
   they govern — do not hold them in chat for a later batch.
5. **Ask in chat prose, not through the `clarify` form, when the interview runs on
   Telegram.** A form the owner closes or ignores comes back `cancelled` with every answer
   empty, and the questions leave his screen — he then asks "katanya ada 6 pertanyaan tadi"
   and the round restarts. Number the questions in the message itself so they survive
   scrolling and can be answered one at a time ("1. sudah / 2. Rp 500rb …").
6. Close each round by naming what is still open (§ open decisions), not by re-asking.

## Document shape

The owner's rule, learned the hard way: a PRD holds **rules**, not progress.

- One PRD, readable in one sitting, ordered the way the store works (catalog → checkout →
  payment → fulfillment → status → settlement → account → content → admin → invariants),
  not the way features were built.
- **No checkboxes, no completion status, no "sudah/belum dikerjakan"** in the PRD. Progress
  belongs in `STATUS.md`; testing and branch rules belong in `AGENTS.md`; server ops
  (CloudPanel, backup, Cloudflare) belong in an ops doc. Mixed documents stop being read.
- A separate short section for **what must never exist** (the non-negotiable list). That is
  the one place the document outranks the code.
- A separate section for **open decisions**, so the document never pretends a guess is a rule.
- Panel structure (menus, per-page capability, roles, visual rules) goes in `ADMIN-PANEL.md`,
  not inside the PRD. Its page inventory is extracted from `routes/web.php`, controller
  methods, and `Inertia::render(...)` prop keys — **never** from the owner's HTML prototype,
  which is a visual reference only. Extraction passes live in the `lfamilia-admin-panel` skill,
  under its `references/panel-spec-from-code.md`.
- Mark unused features as "belum dipakai", never as a roadmap. An inactive gateway is not a
  plan.
- Delete superseded files rather than leaving two versions that disagree; if kept for
  history, move them under an archive path and say so in one line.

## Document landscape — retire before you rewrite

A rewrite changes nothing while the old documents still speak. Inventory every file that
claims authority, then decide each one's fate in the same pass; a new PRD sitting beside an
old PRD, four parity audits, and twelve subsystem notes leaves the next agent five places
to read and no reason to prefer the newest.

```bash
find . -name '*.md' -not -path './node_modules/*' -not -path './vendor/*' | sort
ls .claude/agents .claude/skills          # roles and skills carry rules too
```

Classify and act: superseded → `docs/archive/<reason>/` with a one-line "digantikan oleh
docs/PRD.md"; still true → keep, and make "Aturan produk ada di docs/PRD.md; dokumen ini
hanya menjelaskan cara kode bekerja" its first line; finished plan → archive. Report the
archive move explicitly; never delete a document without an explicit order.

Mechanics that keep the move honest:

```bash
git mv docs/OLD.md docs/archive/<reason>/OLD.md   # history survives; `cp` + `rm` does not
```

Then sweep every stale pointer in one pass with `search_files` (pattern = the old filename,
path = repo root, file_glob `*.md`, excluding `archive/`).

Moves leave dangling links. After archiving, re-resolve every relative Markdown link in the
files you touched — a README pointing at a document that no longer exists is worse than the
old document, because it reads as authoritative and dead at once. Then fix the pointers
(`README.md`, `rebuild/ADMIN.md`, …) in the same pass.

**Give the repo one visible precedence ladder, in `README.md`.** Numbered, four rungs:
PRD → AGENTS.md → ADMIN-PANEL.md → technical notes, each naming what it governs. Add the two
negative rules that stop the old conflicts from returning: `docs/archive/` is **not** a rule,
and `CLAUDE.md` is **not** a rule. Without a ladder, a dozen subsystem notes each look
sovereign.

**One constitution file, one pointer file.** Rewrite `AGENTS.md` as the single operating
manual — its first section is the precedence ladder itself, so an agent that reads only the
first screen already knows what outranks what — and reduce `CLAUDE.md` to ~9 lines that point
at AGENTS.md, PRD and ADMIN-PANEL and explicitly forbid adding rules back into it. Two files
that both hold rules always drift apart, and the one that gets read is whichever is shorter,
not whichever is right. Kill the fiction while rewriting: role assignments that no longer
match reality ("ChatGPT writes the code, QA reviews") teach an agent to invent authority, so
delete them rather than updating them.

**A banner does not neutralize a filename.** Files carrying `HISTORICAL SNAPSHOT: jangan
anggap masih berlaku` are still read as orders while they are named
`admin-legacy-feature-parity.md` — the name outranks the disclaimer, and 20 KB of "compare
every legacy endpoint" underneath reads as a to-do list. Rename on archive
(`2026-10-02-audit-panel-lama.md`) so the filename states what it is; annotating in place
leaves the command intact.

**Subsystem notes that never cite the PRD are a second constitution.** A dozen files under
`rebuild/` (`ADMIN.md`, `PAYMENT.md`, `FULFILLMENT.md`, …) each govern their own area and
none mentions the product rules. Adding the one-line pointer to each is cheaper and safer
than rewriting 325 lines, and it ends the conflict immediately.

## Pitfalls

**The local database is a copy, and an empty value is not an absence.** The local
`system_settings` can hold `store.discord_url` empty while the live footer links a real
Discord invite — the owner fills production, not the dev copy. For anything a buyer can see
(footer channels, legal pages, copy, prices) read the live page; read `system_settings` only
for "which switch exists", never for "what is filled in". Cheap probe after opening the
site: `[...document.querySelectorAll('footer a[href]')].map(a => [a.textContent.trim(), a.href])`.

**The public site is a source, and it usually wins.** The refund policy page carries five
eligible conditions, six exclusions, a 7-calendar-day filing window, 1–7 business-day
review, and up to 14 business days for funds. Write those numbers from the page, and put
the internal split next to them (member → Super Admin wallet adjustment with mandatory
reason; guest → manual transfer outside the system, because guests have no wallet).

**Two status vocabularies, one promise.** Customers see six statuses; the system carries
eighteen internal ones. The PRD fixes the customer vocabulary and says internal states are
implementation detail that never surfaces. Any screen that prints a raw internal code is a
bug, not a label problem.

**A "limit" the code already enforces belongs in the spec, with the code's number.**
Automatic re-checks stop after three attempts two minutes apart; the low-balance warning
defaults to five transactions; wallet top-up is enabled by default. Reading the migrations
first turns three owner questions into three statements.

**Day boundaries are WIB, storage is UTC.** Any "today", report total, or dashboard number
is 00:00–23:59 Asia/Jakarta, expressed in the storage zone — never the app clock (see the
WIB rule in `lfamilia-admin-panel`). Write the boundary into the spec so two screens cannot
disagree later.

**This site is a development preview.** No real buyers, no real orders, wallet never used.
Say so in the spec: the panel must not show sample numbers as if they were data, and every
unused feature is labelled. Do not build a spec that assumes production history exists.

**Archive the old text before you shrink a legacy mega-document.** Replacing a 900-line
`CLAUDE.md` with a pointer deletes rules the owner may still want; `cp` it to
`docs/archive/<reason>/CLAUDE-<state>.md` first, then write the short file and link the
archive from it. Expect the write itself to be refused until the old file has been read in
full during that session — read it, then write, rather than guessing at its contents.

## Reporting

- Plain Indonesian, short. Say which file was written, how many lines, and which sections
  changed because of the owner's answers.
- List what you verified and where (file, migration, page) — the owner checks.
- Name what is still open in one short list, then stop. Do not re-litigate settled rules.
- Discussion mode applies: "diskusi dulu / bahas dulu / menurutmu" means prose only, no
  tools, no repo reads (see `lfamilia-admin-panel` → Reporting style).

## Reference

`references/store-facts.md` — the verified facts a PRD rewrite starts from: statuses, refund
terms, payment channels, settings keys, membership tiers, public pages, provider contract
summary.
