#!/usr/bin/env python3
"""Drop CSS rules whose selectors only reference classes with a --drop prefix.

    prune-css-selectors.py resources/css/admin.css \
        --drop lf-admin --keep lf-admin --keep lf-admin-auth --keep lf-admin-logo

Dry run by default; --write rewrites the file. A rule is removed when every
selector in it mentions a --drop class and no --keep class; a selector list is
reduced in place when only some of its selectors go. Rules with no class in
the selector (element selectors, `*`, `body:has(.x)`) are always kept.

Why this exists: splitting a selector list on commas breaks `:is(...)`,
`:has(...)` and `[data-slot=...]`, and the corrupted file then fails inside
Tailwind with "Missing opening (" pointing at the stylesheet. The splitter
below tracks bracket depth.

Exit code 1 means the output no longer balances its braces or parentheses; the
file was not written.
"""

import argparse
import re
import sys

CLASS_RE = re.compile(r"\.([A-Za-z0-9_-]+)")
GROUP_AT_RULES = ("@media", "@supports", "@layer", "@container")
OPAQUE_AT_RULES = ("@keyframes", "@font-face", "@property", "@page")


def split_selectors(prelude):
    out, depth, cur = [], 0, ""
    for ch in prelude:
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(cur)
            cur = ""
        else:
            cur += ch
    out.append(cur)
    return out


def keep_selector(selector, drop, keep):
    classes = set(CLASS_RE.findall(selector))
    if not any(c.startswith(drop) for c in classes):
        return True
    return any(c.startswith(keep) for c in classes)


def block_end(text, opening):
    depth = 0
    for i in range(opening, len(text)):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                return i
    raise ValueError("unbalanced braces: no closing brace for offset %d" % opening)


def prune(text, drop, keep, stats):
    out, i = [], 0
    while i < len(text):
        brace = text.find("{", i)
        close = text.find("}", i)
        if brace == -1:
            out.append(text[i:])
            break
        if close != -1 and close < brace:
            break  # closing brace of the enclosing block; parent handles it
        prelude = text[i:brace]
        end = block_end(text, brace)
        stripped = prelude.strip()
        if stripped.startswith(GROUP_AT_RULES):
            inner = prune(text[brace + 1:end], drop, keep, stats)
            out.append(prelude + "{" + inner + "}")
        elif stripped.startswith(OPAQUE_AT_RULES):
            out.append(text[i:end + 1])
        else:
            selectors = split_selectors(prelude)
            alive = [s for s in selectors if keep_selector(s, drop, keep)]
            if len(alive) == len(selectors):
                out.append(text[i:end + 1])
            else:
                stats["rules"] += 1
                if alive:
                    out.append(",".join(alive) + "{" + text[brace + 1:end] + "}")
        i = end + 1
    return "".join(out)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("css")
    ap.add_argument("--drop", required=True, help="class prefix to remove, e.g. lf-admin")
    ap.add_argument("--keep", action="append", default=[],
                    help="class (or prefix) to keep even when it matches --drop")
    ap.add_argument("--write", action="store_true")
    args = ap.parse_args()

    src = open(args.css, encoding="utf-8").read()
    stats = {"rules": 0}
    try:
        new = prune(src, args.drop, tuple(args.keep), stats)
    except ValueError as exc:
        sys.exit("refusing to write: %s" % exc)

    for opener, closer in (("{", "}"), ("(", ")")):
        if new.count(opener) != new.count(closer):
            sys.exit("refusing to write: %d '%s' vs %d '%s'"
                     % (new.count(opener), opener, new.count(closer), closer))

    before = sorted(set(re.findall(r"\.%s[-a-z0-9_]*" % re.escape(args.drop), src)))
    after = sorted(set(re.findall(r"\.%s[-a-z0-9_]*" % re.escape(args.drop), new)))
    print("rules touched: %d" % stats["rules"])
    print("classes before: %d, after: %d" % (len(before), len(after)))
    print("surviving: %s" % (", ".join(after) or "(none)"))
    if args.write:
        open(args.css, "w", encoding="utf-8").write(new)
        print("written: %s (%d -> %d lines)" % (args.css, len(src.splitlines()), len(new.splitlines())))
        print("now run: npx vite build")
    else:
        print("dry run; re-run with --write")


if __name__ == "__main__":
    main()
