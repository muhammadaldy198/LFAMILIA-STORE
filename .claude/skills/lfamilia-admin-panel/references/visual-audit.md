# Visual audit (LFAMILIA admin)

How to answer "warna gajelas / tumpang tindih / UI UX nya ancur" with measurements instead of impressions.

## Scope

Every route under `/admin/*`, not the five that look suspicious. Two viewports: 390px and 1440px (`cdp('Emulation.setDeviceMetricsOverride', width=390, height=844, deviceScaleFactor=2, mobile=True)` and `cdp('Emulation.clearDeviceMetricsOverride')`). Scroll to the bottom of each page first so offscreen elements get real rects. Report numbers per page — a page with 0 findings is a result, not a skipped page.

Run the probes by injecting `scripts/visual-audit.js` into the page (`js(open(path).read())`) and parsing the JSON it returns.

## The three probes

1. **Contrast.** For each leaf text node and each control, walk up to the first non-transparent `backgroundColor` — that is the real backdrop — and compute the WCAG ratio against 4.5:1 (3:1 at ≥18.66px, or ≥14px bold). Measuring muted text against `document.body` instead of its actual card is how a 4.0:1 grey passes review.
2. **Occlusion.** Bounding-box intersection alone is worthless: parents contain children and transparent wrappers span everything, so a naive scan reports dozens of false overlaps. Every candidate must be confirmed at the intersection centre with `document.elementFromPoint(x, y)`; it is real only when that point resolves into one element and not the other.
3. **Small and unnamed controls.** Flag every `button, [role=switch], [data-slot=button], summary` with height < 32px, width < 32px, contrast < 4.5, or no accessible name. The owner checks these himself; a 40×25 submit button at 11px and a 36×22 switch with no label are defects, not nitpicks.

Typical real findings in this panel, for calibration: a white `#fff` search-submit pill on the dark top bar, 9px sidebar group labels at 3.4:1, a 9px notification badge at 3.8:1, muted text at 4.0:1, a 36×22 `role=switch` with no `aria-label`, an active tab in `#1769e8` on `#09090b` at exactly 4.0:1.

## Convert colours before computing a ratio

`getComputedStyle` returns `oklch(...)` for every themed token in the dummy and in newer panels. A luminance function that regexes `[\d.]+` and treats the first three numbers as RGB reads oklch components as 0–1 floats and reports the same nonsense ratio ("2.48:1") for every element. Round-trip each colour through a 1×1 canvas instead, and skip surfaces whose alpha is below 0.5 while walking up to the backdrop:

```js
const cv = document.createElement('canvas'); cv.width = cv.height = 1;
const cx = cv.getContext('2d', { willReadFrequently: true });
const rgb = (c) => { cx.clearRect(0,0,1,1); cx.fillStyle = '#000'; cx.fillStyle = c; cx.fillRect(0,0,1,1);
  const d = cx.getImageData(0,0,1,1).data; return [d[0], d[1], d[2], d[3] / 255]; };
const bgOf = (el) => { let n = el; while (n && n !== document.documentElement) {
  const c = getComputedStyle(n).backgroundColor; if (rgb(c)[3] > 0.5) return c; n = n.parentElement; }
  return getComputedStyle(document.body).backgroundColor; };
```

## Never report contrast from a screenshot description

Asking a vision model "is this text readable?" is not a measurement. On a dark-mode JPEG it confidently reported "customer name and nominal near-black on near-black, ~1.2:1" for a table whose real ratio was **16.5:1** — the same pass also invented a light header band that did not exist. Confirm with pixel sampling of the actual capture before writing a finding:

```python
from PIL import Image
im = Image.open('shots/dark.jpg').convert('RGB')
def lum(c):
    f = lambda v: (v/255)/12.92 if v/255 <= 0.03928 else (((v/255)+0.055)/1.055)**2.4
    return 0.2126*f(c[0]) + 0.7152*f(c[1]) + 0.0722*f(c[2])
crop = im.crop((x0, y0, x1, y1)); px = sorted(crop.getdata(), key=lum, reverse=True)
text = px[:max(1, len(px)//40)]           # brightest 2.5% = the glyphs, not the background
```

Take the crop box from the live DOM (`getBoundingClientRect` of the element), not from a guess at the image. Capture with `format='jpeg', quality=80` and store shots in the project — full-page PNG capture can exceed the CDP timeout on a busy page, and `/tmp` is not a safe home for files that must survive to the reply.

## A data table is not a mobile layout

`overflow-x-auto` around a table keeps the desktop columns and simply hides the money, status and action columns past the right edge — the page still reports `scrollWidth == innerWidth`, so the overflow probe does not catch it. Below 768px render a card list instead (`hidden md:block` on the table wrapper, `md:hidden` on the cards): id + date and a status badge on top, customer and product in the middle, phone and nominal on a footer row. Raise inputs, selects and icon buttons to 40–44px on phones; a bare text "Reset" button with a 20px hit area is a defect.

## Two theme systems, and the dead one still wins

`resources/css/admin.css` carries a legacy light block (`--background:#f4f7fb`, `--muted-foreground:#718198`, `#fff` surfaces) alongside the dark tokens. The later block silently overrides the dark values, so every subsequent "fix" keeps losing. Decide which block is winning with `getComputedStyle`, never by source order.

Before deleting legacy rules, prove they are dead by cross-checking every `.lf-*` class in the CSS against the whole source tree:

```python
import re, pathlib
css = pathlib.Path('resources/css/admin.css').read_text()
classes = sorted(set(re.findall(r'\.(lf-[a-z0-9-]+)', css)))
src = ''.join(f.read_text(errors='ignore') for d in ('resources/js','resources/views','tests')
              for f in pathlib.Path(d).rglob('*') if f.suffix in {'.vue','.ts','.js','.php','.mjs'})
print([c for c in classes if c not in src])
```

Classes that survive that check but are still used (`lf-admin-toggle`, `lf-admin-drawer-close`, `lf-admin-menu-group-label`) must be moved into the surviving block, not deleted with it.

## Portals: theme the surface, not the ancestor

`[data-slot=sheet-content]`, `.lf-admin-popover` and the mobile navigation render **outside** `.lf-admin-content`. Content-scoped rules never reach them, so a hard-coded `background:#fff` puts white text on a white card. Use `var(--popover)` / `var(--background)` / `var(--card)` / `var(--border)` on those surfaces. Verify by opening the panel and reading `getComputedStyle`, not by grepping the page.

## Sticky footers need reserved scroll padding

`.lf-admin-workspace` reserves `padding-bottom:80px` for `.lf-admin-workspace-actions`; a `sticky bottom-0` footer without that reserve hides the last field under the buttons. Grep each sheet for `sticky bottom-0` and confirm its scroll container carries the bottom padding.

## Slop is a named failure for this owner

Generic dark-SaaS defaults — muted-grey-on-grey, 8–10px labels, white pills on dark bars, a uniform card grid — read as "banyak yang slop" however correct the markup. Drive palette, spacing and density through `ui-ux-pro-max`'s CLI before hand-picking colours, and state the numbers you chose and why:

```bash
python3 ~/.hermes/skills/creative/ui-ux-pro-max/scripts/search.py "<domain> <product> admin dashboard" --design-system --density 7 --variance 4 -f markdown
python3 ~/.hermes/skills/creative/ui-ux-pro-max/scripts/search.py "dark mode contrast muted text" --domain ux -n 3
python3 ~/.hermes/skills/creative/ui-ux-pro-max/scripts/search.py "touch target icon button size" --domain ux -n 3
```

Its own checklist that matters here: no emoji as icons, `cursor-pointer` on clickable elements, visible focus, hover transitions 150–300ms, contrast ≥4.5:1, breakpoints 375/768/1024/1440.
