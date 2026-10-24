---
name: vue-best-practices
description: MUST be used for Vue.js tasks. Strongly recommends Composition API with `<script setup>` and TypeScript as the standard approach. Covers Vue 3, SSR, Volar, vue-tsc. Load for any Vue, .vue files, Vue Router, Pinia, or Vite with Vue work. ALWAYS use Composition API unless the project explicitly requires Options API.
license: MIT
metadata:
  author: github.com/vuejs-ai
  version: "18.0.0"
---

# Vue Best Practices Workflow

Use this skill as an instruction set. Follow the workflow in order unless the user explicitly asks for a different order.

## Core Principles
- **Keep state predictable:** one source of truth, derive everything else with `computed`.
- **Make data flow explicit:** Props down, Events up for most cases.
- **Favor small, focused components:** easier to test, reuse, and maintain.
- For a shared parent-level setting shown in a child/field editor, include persistence in that editor’s expected save action or provide an explicit adjacent save control. If field keys and the shared setting reference each other, submit and validate them together through one atomic server operation; separate callbacks can make normal edits impossible or leave partial state.
- **Avoid unnecessary re-renders:** use computed properties and watchers wisely.
- **Readability counts:** write clear, self-documenting code.

## Admin UX copy and local-repo workflow

- For LFAMILIA admin/UI work, first read both applicable user bundles (`/home/hatch/.hermes/skill-bundles/frontend.yaml` and `desainpakai.yaml`), deduplicate the listed skills, and load each relevant skill before reviewing or editing. Treat the bundle as a checklist, not a requirement to redesign: assign each skill a role (product/UX framing, visual direction, UI implementation, accessibility, framework practice, verification) and apply its useful constraints to the requested scope. Never claim a skill guided the work unless it was actually loaded and consulted.
- For admin usability work, inspect the actual Vue page and the controls/data it binds before proposing or changing labels; do not infer field meaning from a user paraphrase or search hit alone.
- Write for non-programmer staff in the user's language: describe what a control changes and use plain terms, while retaining provider-required terms only when paired with a short explanation.
- Distinguish a copy-only request from a full UI/UX redesign. When the user asks for UI improvement/redesign, do not stop at labels: inspect actual pages and improve coherent hierarchy, navigation, forms, tables, actions/dialogs, and mobile behavior while preserving business rules unless explicitly changed.
- For a multi-page redesign, inventory the real admin navigation and page families first, then establish one shared visual system and audit the shared shell/components before rolling improvements through page families. Keep a visible checklist of pages and interaction/responsive states; never claim full completion from one page or a successful build.
- Enforce a single-writer rule in a shared worktree: assign each file to at most one implementation agent, do not dispatch duplicate or broad concurrent writers, and prefer one serial implementer when ownership or state is unclear. Read-only audits may run in parallel only when they have no write side effects. Track delegated writer IDs and stop redundant workers directly; never spawn additional agents merely to relay a stop or status.
- If a user explicitly says STOP, cease edits, tests, delegation, and all other task actions immediately; do not spawn another agent to relay the stop. Resume only after a later clear instruction from the user.
- For structural template edits, first save/read the target file and identify exact block boundaries from unique semantic markers; patch one block at a time, inspect the full diff and opening/closing structure immediately, then run the Vue parser/linter and production build before another structural edit. Never reorder template blocks using guessed line ranges or broad/global tag replacement: nested boundaries can be silently removed while the file still appears superficially valid. If a structural edit becomes ambiguous, restore only that file to its verified pre-edit contents, then reapply a smaller patch; preserve unrelated worktree edits.
- Before changing or restoring UI that tests mention, search the relevant test tree for the exact UI symbol and read the assertion context; update a stale test expectation when the approved design requirement intentionally reverses it, rather than treating that guard as product truth or leaving the full test run red.
- If an edit causes a syntax or lint failure, pause feature work, inspect the exact file diff and reproduce the failure, then repair or restore only the attributable changes before proceeding. Do not stack speculative fixes or continue modifying dependent pages while the worktree is in a known-broken state.
- Keep user updates minimal during a frustrated or explicitly continuous task: continue quietly and send one evidence-backed final report. Do not emit an empty answer or ask the user to restart when there are already concrete tool results or an active task to continue.
- Before claiming a multi-page task complete, run the final checks against the final worktree and distinguish source-level checks from browser/interaction QA; never report a result from before the last edit as current verification. For a user-approved “continue to final” audit, keep a requirement checklist and continue through every page; test and reconcile each finding against the approved source and current behavior. Do not stop with partial work or imply finality while known mismatches remain; report explicit blockers only when the specification is genuinely ambiguous or a safe implementation is not established.
- For repository/UI tasks, treat session context and tool limits as real: use existing tracked agents rather than spawning replacements repeatedly, and if a delegation cap is reached, switch to a direct, bounded workflow instead of retrying the same dispatch.- When resuming an existing plan, reconcile current branch commits and local diff with each requirement before calling work incomplete: an unchecked plan box is stale bookkeeping, and an optional component/file absent from the plan is not itself a defect if behavior is delivered another way. Report evidence by requirement, mark uncertainty as unverified, and do not equate static checks/build success with visual or interaction QA. Use Graphify as orientation, then verify conclusions in current templates, handlers, conditional rendering, and commit diffs; broad BFS queries can return hundreds of loosely relevant nodes, so narrow query terms/budget and do not treat truncation as evidence. For relocated controls, compare the old rendered permission guard with the destination page's available permission context and the route middleware; preserve the old UI guard without weakening the server boundary. For section-specific exports, verify selected section and date range reach the exporter, test actual serialized rows and line endings, and retain finance-field gating; source-level name/visibility assertions alone are insufficient.
- Continue a user-approved audit serially through the remaining requested scope; do not stop after a preliminary sample or ask for renewed permission. When the owner says “continue to final” or is visibly frustrated, keep working quietly: avoid repeated apologies, verbose explanations, and progress chatter; report concrete findings and verification limits at the end. Never send a time estimate or “almost done” based on partial progress; continue through the final checks and send one evidence-backed final report.
- In any handoff/resumed audit, treat the latest worktree as the source of truth: inspect current files and diffs before relying on prior summaries or claiming a fix. If a page has repeated speculative edits or failures, restore only that file to its committed baseline, then reapply the smallest proven change one uniquely scoped block at a time; rerun that page's tests and production build after the final edit. Do not describe an export as complete when its server contract only supports a different report type; mark the missing capability explicitly and continue auditing other requirements.
- Treat an explicit “STOP” as an immediate halt: do not dispatch new agents, run checks, edit files, or continue the task. If agents are already running, issue stop/cancel instructions to those agents, then acknowledge briefly; do not create more agents to communicate the stop. A later explicit user instruction to resume supersedes the stop.
- Before dispatching implementation agents, reconcile ownership by file and allow at most one writer per file/worktree. Keep parallelism to independent read-only work; duplicate writer dispatches or broad fan-out in a shared worktree create conflicting edits and make recovery attribution unreliable. If ownership becomes ambiguous, stop the writers and inspect the resulting diff before making any further changes.
- Treat “continue” as approval to carry out the recommendation just given, not blanket approval for a separate irreversible or shared side effect. Finish the recommended review/verification first; require clear authorization before commit, push, merge, PR, or deploy, and do not ask about commit until the deliverable is otherwise ready.
- When the owner explicitly names workflow bundles such as `/frontend` and `/coding`, load and follow those bundles before implementation. If a named bundle is unavailable, use the closest available coding skills and explain the mismatch in one short sentence; do not let it become a blocker or long discussion.
- Avoid bulk keyword/count searches as the basis for feature claims: inspect the relevant template, bound state, handlers, and rendered conditions together, since dormant helper code can exist without visible UI and similarly named text may be unrelated.
- Treat build outputs as generated side effects: inspect status before and after build, distinguish generated artifact changes from source edits, and preserve existing local work; never claim the worktree was unchanged if a build modified it.
- Make audit claims only at the level supported by evidence: file existence and lint prove neither feature completeness nor UX quality; distinguish confirmed, partial, absent, and not browser-verified requirements.

## Admin Redesign Audit

For the Admin plan checklist and evidence mapping, use [admin-redesign-audit.md](references/admin-redesign-audit.md). The reference is a reusable audit method, not a substitute for reading the current plan and actual page behavior.

- Before editing, inspect git status and diffs to separate existing/parallel work from the requested UI scope. Preserve unrelated changes; avoid broad patches or commits that sweep them in. For structural template edits, patch the smallest uniquely identified block, then immediately inspect the complete changed file/diff and run the Vue parser/linter before making another edit; never apply global tag replacements because identical tags may belong to unrelated nested components. Inspect the actual Vue page, controls, and bound data before proposing or changing UI.
- Treat skill/tool use as a required workflow, not an optional reminder: before each work phase, load the relevant skills and use the appropriate available tools (e.g. Graphify for codebase questions, lint/typecheck for Vue changes). Never claim a skill/tool was used unless it actually was. If a user corrects the workflow, stop implementation, load the governing skill(s), and resume only from an evidence-based checkpoint; do not merely apologize or make another promise. For Laravel DB tests, inspect `phpunit.xml`, `.env.testing`, and test bootstrap to determine the effective database before running PHPUnit; never rewrite `.env`/`.env.testing` merely to make tests target a presumed DB, and never reset a persistent/shared DB without explicit safety evidence and approval.
- Apply only evidenced improvements, preserve accepted design decisions, bindings, values, validation, and behavior; do not restart from scratch merely because a skill was missed earlier. Keep edits cohesive across pages rather than adding inconsistent one-off patches. When the owner scopes work frontend-first, keep edits to UI files and do not remove or invent backend APIs; record backend gaps for a separate verified pass.
- Audit and edit sequentially in the local repository; avoid subagents writing in parallel to the same worktree because overlapping edits make accidental diffs hard to attribute. Inspect active processes before heavy verification and identify the actual resource consumer rather than attributing lag to the foreground check. Run Vue/type check, lint, diff check, and production build serially; avoid overlapping heavy processes. For structural template edits, patch the smallest uniquely identified block, then immediately inspect the complete changed file/diff and run the Vue parser/linter before making another edit; never apply global tag replacements because identical tags may belong to unrelated nested components. Treat an existing test or browser fixture as potentially stateful: inspect its setup and target before running it, because it may mutate the configured database, clear caches, create links, or affect a server process. When a full test suite runs against a persistent dedicated DB and fails with duplicate fixtures or accumulated rows, treat that run as contaminated, reset only the isolated test DB using the repo's documented workflow, then rerun the full suite before classifying failures; never trust the pass/fail result from dirty state.
- For localhost visual QA, opening the local login page is allowed; never infer credentials from the user, request passwords in chat, or type them directly. Use browser_vault on the actual login origin; if the session cannot securely collect a login, stop at that boundary and report the supported vault setup path without repeating secrets or asking the user to access the agent's VM. Clearly distinguish static/build verification from visual browser verification. Before using a browser fixture that logs in, writes database state, or submits actions, verify the server is an isolated local/testing instance; a localhost URL alone does not prove isolation.
- When the user says to continue without waiting between stages, continue through the planned, bounded audit autonomously; send progress only at meaningful milestones, and never mistake an out-of-band repeat of that request for a new task or a reason to stop the work.
- For multi-page copy-only admin work, inspect the bound markup and relevant controller semantics before changing labels; preserve bindings and business logic, then verify only the UI files touched. Do not imply the entire admin audit is complete if pages remain.

## 1) Confirm architecture before coding (required)

- Default stack: Vue 3 + Composition API + `<script setup lang="ts">`.
- If the project explicitly uses Options API, load `vue-options-api-best-practices` skill if available.
- If the project explicitly uses JSX, load `vue-jsx-best-practices` skill if available.

### 1.1 Must-read core references (required)

- Before implementing any Vue task, make sure to read and apply these core references:
  - `references/reactivity.md`
  - `references/sfc.md`
  - `references/component-data-flow.md`
  - `references/composables.md`
- Keep these references in active working context for the entire task, not only when a specific issue appears.

### 1.2 Plan component boundaries before coding (required)

Create a brief component map before implementation for any non-trivial feature.

- Define each component's single responsibility in one sentence.
- Keep entry/root and route-level view components as composition surfaces by default.
- Move feature UI and feature logic out of entry/root/view components unless the task is intentionally a tiny single-file demo.
- Define props/emits contracts for each child component in the map.
- Prefer a feature folder layout (`components/<feature>/...`, `composables/use<Feature>.ts`) when adding more than one component.

## 2) Apply essential Vue foundations (required)

These are essential, must-know foundations. Apply all of them in every Vue task using the core references already loaded in section `1.1`.

### Reactivity

- Must-read reference from `1.1`: [reactivity](references/reactivity.md)
- Keep source state minimal (`ref`/`reactive`), derive everything possible with `computed`.
- Use watchers for side effects if needed.
- Avoid recomputing expensive logic in templates.

### SFC structure and template safety

- Must-read reference from `1.1`: [sfc](references/sfc.md)
- Keep SFC sections in this order: `<script>` → `<template>` → `<style>`.
- Keep SFC responsibilities focused; split large components.
- Keep templates declarative; move branching/derivation to script.
- Apply Vue template safety rules (`v-html`, list rendering, conditional rendering choices).

### Keep components focused

Split a component when it has **more than one clear responsibility** (e.g. data orchestration + UI, or multiple independent UI sections).

- Prefer **smaller components + composables** over one “mega component”
- Move **UI sections** into child components (props in, events out).
- Move **state/side effects** into composables (`useXxx()`).

Apply objective split triggers. Split the component if **any** condition is true:

- It owns both orchestration/state and substantial presentational markup for multiple sections.
- It has 3+ distinct UI sections (for example: form, filters, list, footer/status).
- A template block is repeated or could become reusable (item rows, cards, list entries).

Entry/root and route view rule:

- Keep entry/root and route view components thin: app shell/layout, provider wiring, and feature composition.
- Do not place full feature implementations in entry/root/view components when those features contain independent parts.
- For CRUD/list features (todo, table, catalog, inbox), split at least into:
  - feature container component
  - input/form component
  - list (and/or item) component
  - footer/actions or filter/status component
- Allow a single-file implementation only for very small throwaway demos; if chosen, explicitly justify why splitting is unnecessary.

### Component data flow

- Must-read reference from `1.1`: [component-data-flow](references/component-data-flow.md)
- Use props down, events up as the primary model.
- Use `v-model` only for true two-way component contracts.
- Use provide/inject only for deep-tree dependencies or shared context.
- Keep contracts explicit and typed with `defineProps`, `defineEmits`, and `InjectionKey` as needed.

### Composables

- Must-read reference from `1.1`: [composables](references/composables.md)
- Extract logic into composables when it is reused, stateful, or side-effect heavy.
- Keep composable APIs small, typed, and predictable.
- Separate feature logic from presentational components.

## 3) Consider optional features only when requirements call for them

### 3.1 Standard optional features

Do not add these by default. Load the matching reference only when the requirement exists.

- Slots: parent needs to control child content/layout -> [component-slots](references/component-slots.md)
- Fallthrough attributes: wrapper/base components must forward attrs/events safely -> [component-fallthrough-attrs](references/component-fallthrough-attrs.md)
- Built-in component `<KeepAlive>` for stateful view caching -> [component-keep-alive](references/component-keep-alive.md)
- Built-in component `<Teleport>` for overlays/portals -> [component-teleport](references/component-teleport.md)
- Built-in component `<Suspense>` for async subtree fallback boundaries -> [component-suspense](references/component-suspense.md)
- Animation-related features: pick the simplest approach that matches the required motion behavior.
  - Built-in component `<Transition>` for enter/leave effects -> [transition](references/component-transition.md)
  - Built-in component `<TransitionGroup>` for animated list mutations -> [transition-group](references/component-transition-group.md)
  - Class-based animation for non-enter/leave effects -> [animation-class-based-technique](references/animation-class-based-technique.md)
  - State-driven animation for user-input-driven animation -> [animation-state-driven-technique](references/animation-state-driven-technique.md)

### 3.2 Less-common optional features

Use these only when there is explicit product or technical need.

- Directives: behavior is DOM-specific and not a good composable/component fit -> [directives](references/directives.md)
- Async components: heavy/rarely-used UI should be lazy loaded -> [component-async](references/component-async.md)
- Render functions only when templates cannot express the requirement -> [render-functions](references/render-functions.md)
- Plugins when behavior must be installed app-wide -> [plugins](references/plugins.md)
- State management patterns: app-wide shared state crosses feature boundaries -> [state-management](references/state-management.md)

## 4) Run performance optimization after behavior is correct

Performance work is a post-functionality pass. Do not optimize before core behavior is implemented and verified.

- Large list rendering bottlenecks -> [perf-virtualize-large-lists](references/perf-virtualize-large-lists.md)
- Static subtrees re-rendering unnecessarily -> [perf-v-once-v-memo-directives](references/perf-v-once-v-memo-directives.md)
- Over-abstraction in hot list paths -> [perf-avoid-component-abstraction-in-lists](references/perf-avoid-component-abstraction-in-lists.md)
- Expensive updates triggered too often -> [updated-hook-performance](references/updated-hook-performance.md)

## 5) Final self-check before finishing

- Core behavior works and matches requirements.
- All must-read references were read and applied.
- Reactivity model is minimal and predictable.
- SFC structure and template rules are followed.
- Components are focused and well-factored, splitting when needed.
- Entry/root and route view components remain composition surfaces unless there is an explicit small-demo exception.
- Component split decisions are explicit and defensible (responsibility boundaries are clear).
- Data flow contracts are explicit and typed.
- Composables are used where reuse/complexity justifies them.
- Moved state/side effects into composables if applicable
- Optional features are used only when requirements demand them.
- Performance changes were applied only after functionality was complete.
