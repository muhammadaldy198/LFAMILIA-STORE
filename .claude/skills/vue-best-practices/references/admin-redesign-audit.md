# Admin redesign audit

Use this for a planned multi-page admin redesign when code already exists or work is being resumed.

## Procedure

1. Read the plan's global constraints, task sequence, requirements, and review focus. Identify the exact requested user-visible outcomes; separate required behavior from optional file/component suggestions.
2. Query an existing Graphify graph for orientation before rebuilding it. Then inspect branch history, `git status --short`, staged/unstaged diffs, and relevant generated artifacts. Preserve all local edits; never reset, checkout, stash, clean, or overwrite unrelated work.
3. Build a requirement-to-evidence checklist. For every criterion, inspect the relevant current template, props/state, event handler, and conditional rendering. Use commit diffs to understand intent, but verify against the current working tree because uncommitted edits may supersede commits.
4. Classify each item as **confirmed**, **partial**, **absent**, or **not verified**. An unchecked Markdown checkbox is only stale progress metadata unless code evidence confirms the work is missing. A suggested component split is not a functional requirement unless explicitly mandatory; evaluate cohesion and maintainability in the actual component.
5. For interactions, inspect both trigger and outcome: e.g. dialog opening, confirmation gate, submitted action, success/error result. For responsive requirements, confirm scroll/container semantics and test actual viewport behavior if browser access is available. Do not infer visual correctness from class names alone.
6. Run only relevant checks, serially and with safe fixtures. Typical frontend gate: `git diff --check`, project type/check command, lint, then build. Inspect `git status --short` after build; generated build artifacts may change. Build success proves bundling, not UI completion. Run targeted UI guard tests against the final worktree too; a structural assertion can pass while template wiring, export semantics, or other plan criteria remain incomplete. Do not claim a section-specific export unless both the selected section/range reaches a compatible endpoint and the resulting file contains that section's data.
7. Report only specific findings with evidence and confidence. Separate code-confirmed findings from browser-unverified states; say what remains and continue the approved audit without asking permission again unless a consequential safety decision blocks progress.

## Evidence pitfalls

- Do not use substring/keyword counts alone to claim a feature is present or missing; dormant handlers and hidden controls can make the count misleading.
- Do not call a plan task incomplete solely because its checklist is unchecked or its optional component files are absent; reconcile behavior against requirements first.
- Do not call a multi-page UI visually verified without observing rendered pages, dialogs, and relevant responsive states.
- Do not say the working tree is unchanged after a build without checking status; generated manifests/assets may be rewritten.
- Keep unrelated backend edits out of conclusions about an admin UI task unless the plan explicitly includes them.
