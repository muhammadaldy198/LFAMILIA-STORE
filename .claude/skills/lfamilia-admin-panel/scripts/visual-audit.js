// LFAMILIA admin visual probe. Inject with js(open('<path>').read()) after scrolling to the bottom.
// Returns {overlaps, lowContrast, smallControls, unnamed, scrollWidth, viewport}.
(() => {
  const lum = c => {
    const m = String(c).match(/[\d.]+/g); if (!m) return null;
    const [r, g, b, a] = m.map(Number); if (a === 0) return null;
    const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const bgOf = el => {
    let n = el;
    while (n && n !== document.documentElement) {
      const c = getComputedStyle(n).backgroundColor;
      if (c && !/rgba\(0, 0, 0, 0\)|transparent/.test(c)) return c;
      n = n.parentElement;
    }
    return 'rgb(255, 255, 255)';
  };
  const ratio = (a, b) => { const A = lum(a), B = lum(b); return A === null || B === null ? null : +(((Math.max(A, B) + 0.05) / (Math.min(A, B) + 0.05))).toFixed(2); };
  const visible = e => {
    const s = getComputedStyle(e);
    if (s.display === 'none' || s.visibility === 'hidden' || s.position === 'fixed') return false;
    const r = e.getBoundingClientRect();
    return r.width > 10 && r.height > 8;
  };

  // 1. Real occlusion only: confirmed at the intersection centre by elementFromPoint.
  const nodes = [...document.querySelectorAll('main *, [data-slot=sheet-content] *')].filter(visible);
  const overlaps = [];
  for (let i = 0; i < nodes.length; i++) {
    const A = nodes[i], a = A.getBoundingClientRect();
    for (let j = i + 1; j < nodes.length; j++) {
      const B = nodes[j];
      if (A.contains(B) || B.contains(A)) continue;
      const b = B.getBoundingClientRect();
      const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left);
      const oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (ox < 8 || oy < 8) continue;
      const t = document.elementFromPoint(Math.max(a.left, b.left) + ox / 2, Math.max(a.top, b.top) + oy / 2);
      if (!t) continue;
      const inA = A === t || A.contains(t), inB = B === t || B.contains(t);
      if (inA === inB) continue; // both or neither: not a real cover
      const [hidden, by] = inA ? [B, A] : [A, B];
      overlaps.push({
        hidden: hidden.tagName + '|' + (hidden.textContent || '').trim().slice(0, 30),
        by: by.tagName + '|' + (by.textContent || '').trim().slice(0, 30),
        w: Math.round(ox), h: Math.round(oy),
      });
    }
  }

  // 2. Contrast, measured against the real backdrop.
  const lowContrast = [];
  [...document.querySelectorAll('main *, [data-slot=sheet-content] *')].forEach(e => {
    if (e.children.length) return;
    const txt = (e.textContent || '').trim();
    if (!txt || txt.length > 80) return;
    const s = getComputedStyle(e);
    if (s.display === 'none' || s.visibility === 'hidden') return;
    const r = ratio(s.color, bgOf(e));
    if (r === null) return;
    const size = parseFloat(s.fontSize);
    const need = (size >= 18.66 || (size >= 14 && +s.fontWeight >= 700)) ? 3 : 4.5;
    if (r < need) lowContrast.push({ text: txt.slice(0, 40), ratio: r, need, size, color: s.color, bg: bgOf(e) });
  });

  // 3. Small / unnamed controls.
  const smallControls = [], unnamed = [];
  document.querySelectorAll('button, [role=switch], [data-slot=button], summary, a.inline-flex, select, input[type=checkbox]').forEach(e => {
    if (!visible(e)) return;
    const r = e.getBoundingClientRect(), s = getComputedStyle(e);
    const label = (e.textContent || '').trim().slice(0, 26);
    const aria = e.getAttribute('aria-label') || '', title = e.getAttribute('title') || '';
    const item = { label, w: Math.round(r.width), h: Math.round(r.height), size: s.fontSize, ratio: ratio(s.color, bgOf(e)) };
    if (r.height < 32 || r.width < 32) smallControls.push(item);
    if (!label && !aria && !title) unnamed.push(item);
  });

  const uniq = (arr, key) => { const seen = new Set(); return arr.filter(x => { const k = key(x); return seen.has(k) ? false : (seen.add(k), true); }); };

  return JSON.stringify({
    overlaps: uniq(overlaps, o => o.hidden + o.by).slice(0, 15),
    overlapCount: overlaps.length,
    lowContrast: uniq(lowContrast, o => o.text + o.color).slice(0, 15),
    lowCount: lowContrast.length,
    smallControls: uniq(smallControls, o => o.label + o.w + o.h).slice(0, 12),
    smallCount: smallControls.length,
    unnamed: uniq(unnamed, o => o.w + o.h).slice(0, 8),
    unnamedCount: unnamed.length,
    scrollWidth: document.documentElement.scrollWidth,
    viewport: innerWidth,
  });
})()
