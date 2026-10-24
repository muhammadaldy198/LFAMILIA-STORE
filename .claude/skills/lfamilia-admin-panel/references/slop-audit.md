# AI-slop audit against the template

Slop here means *invented* UI: text, boxes and labels the template never has, added because they felt helpful. The owner asks "sudah di cek ai slop?" and expects findings with counts, not reassurance. Every check below is a count you print, never an impression from a screenshot.

## The method

Diff our pages against the template **quantitatively**, page by page. The template file is a single HTML; every probe is `t.count(...)` on it or a regex over one `.vue` file. A pattern is slop when the template has ~zero of it and our pages have dozens.

```python
import re, glob
t = open(TEMPLATE, encoding='utf-8', errors='ignore').read()
pages = sorted(glob.glob('resources/js/Pages/Admin/*.vue'))
for p in pages:
    s = open(p).read()
    tpl = s[s.index('</script>'):]            # markup only; the script has no copy
    print(p, len(re.findall(r'<p>', tpl)))
```

Cut the file at `</script>` before counting. Counting the whole file inflates every number with identifiers and comments and produces findings that do not exist.

## Signals, in the order they pay off

1. **Explanatory `<p>` under a card/panel heading.** The template's `panel-head` is a title plus, at most, a button. A paragraph under every `<h2>` is the biggest single tell (ours had ~30 across 16 pages; the template has almost none). Keep a paragraph only when it carries a constraint the operator cannot infer ("kosong = pertahankan", a format rule, a permission note); delete the ones that restate the title.

   ```python
   heads = re.findall(r'<div class="adm-card-head">.*?</div>', tpl, re.S)
   bare  = [h for h in heads if '<h2>' in h and '<p>' not in h]
   ```

   The **page-head subtitle** obeys the same limit. The template's `<h1>`+`<p>` pairs are one sentence (`Pelanggan`: "Cari pelanggan, pantau saldo, dan atur tingkat member toko."), at most two; a second sentence explaining clock zone or a rule the screen already shows is invented copy. Extract ours with `re.search(r'<h1>(.*?)</h1>\s*<p>(.*?)</p>', s, re.S)` and count the sentences per page.

2. **Helper sentences that do not exist in the template at all.** Grep the exact phrase against `t` before keeping it. `"Geser tabel ke samping"` was printed above 16 tables; `t.count('Geser')` and `t.count('ke samping')` are both 0 — the template just lets `.tbl-wrap` scroll. Rule: **if the template does not say it, we invented it**; delete the sentence and its CSS, not just the class.

3. **Stat cards must match the template's card count per page.** Count `class="mcard` inside that page's `<section id="page-…">` and compare with `class="adm-stat` in our file. A page with 11 template cards rendered as 5 boxes (or the reverse) is a redesign nobody asked for. Cards are label + value; a static `nt` note under the number is slop (48 of 73 template cards have exactly two children). Keep the note only when it is a live value (`{{ … }}`).

4. **A summary the template renders as one sentence must stay one sentence.** Support's `1 menunggu · 1 diproses · … · 1 tanpa akun` is `<p class="small mut">` in the template; converting it into six stat boxes invents a hierarchy. Same test for any inline count strip: find the literal line in the section, copy its shape.

5. **Hardcoded colour and inline layout.** `#[0-9a-f]{3,6}`, `:style="` and `style="` per page. A static inline style is a class waiting to be extracted (`.adm-thumb`, `.adm-color`, `.adm-subhead`, `.adm-list-row.unread`); a dynamic `:style` is fine. Colours belong in `:root` tokens — `--a-accent-soft` etc. — never `#fafbf9` in markup.

6. **Heading level.** A sub-heading *inside* a panel ("Warna & gambar", "Teks kepala") is `<h3 class="adm-subhead">`, not `<h2 style="font-size:12px…">`. The inline style is the tell that the level is wrong.

7. **English leftovers.** `>Edit<`, `>Save<`, `>Cancel<`, `>Delete<`, `>Search<`, `>Submit<`, `>Loading<`. The panel speaks Indonesian; `Edit` survived on the Produk rows long after every other page was converted.

8. **Two chips saying the same thing.** A customer row showing both the tier and "Otomatis" repeats one fact twice. Read the controller that feeds the row and render the second chip only when it adds information (`membership_mode === 'MANUAL'` → "Ditetapkan admin").

9. **Orphan rows.** A 6-card strip that wraps 5+1, an 8-card strip that wraps 6+2. Measure, do not look: bucket `Math.round(rect.top)` per card and print cards-per-row for every page at 1280 and 375.

## What is NOT slop

Exclude what the template itself dictates, and say so in one line instead of listing it:

- `★`/`☆` rating glyphs — the template prints them (`t.count('★')` > 0).
- Two buttons of equal weight in a filter row (`Terapkan` + `Hapus filter`) — the template has the same pair, and filters do not auto-apply.
- Two row buttons with different jobs (`Buka` / `Atur`).
- Raw enum codes that the backend sends without a label — that is a missing label (see the label sweep), not slop.

## Reporting

Group into what was removed (with the count), what was verified (gates + a re-measured number), and what was deliberately kept with the reason. Never answer "sudah bersih" without the counts; the owner checks.

A pure-deletion finding the template justifies — a sentence the template never had, a static sub-label, an inline colour — can be fixed in the same pass and reported with the number, because it restores the template instead of designing something new. Anything that changes layout or adds/removes a control is still a report first: the owner decides those.

## The fixing pass

Removals run as one Python pass over the page files (`re.sub`, one pattern at a time), then the same counts are printed again to show the drop. Four traps, all of them cheap to avoid:

- **Anchor the pattern on the whole element, including its indentation and newline.** `<p>…</p>` without the leading `[ \t]*` and trailing `\n` leaves an indented orphan line; a heading-wrapper pattern that assumes a newline misses every head written on one physical line and silently reports "not found". Count how many replacements actually happened and print the misses — a patch that matched nothing looks identical to a patch that worked.
- **Never do a bare tag rewrite (`s.replace('</h2>', '</h3>')`).** It also rewrites every unrelated closing tag on the page; the fix is to assert the pairing afterwards (`<hN>` count == `</hN>` count per file) and repair what it broke. Restrict tag edits to the exact elements you are re-levelling.
- **ESLint runs last and catches the fallout.** Deleting the block that used a helper leaves `import { num } from …` unused and fails `no-unused-vars`; the page still builds and renders. Clear the import in the same pass.
- **Re-measure, do not re-screenshot.** After the pass, print the same number the finding quoted (cards per row, counts of `nt`/`>Edit<`/inline `style=`) at 1280 and 375. "Sudah dibuang" without the after-count is the reassurance the owner is checking against.
