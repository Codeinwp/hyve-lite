# Hyve Dashboard UI refresh — knowledge base

Working folder for [hyve#223 — Refresh Dashboard UI](https://github.com/Codeinwp/hyve/issues/223). These docs are the persistent source of truth for the project so any session (human or AI) can pick up where the last one left off.

## Contents

| File | What it holds |
|------|---------------|
| [`new-ui-plan.md`](new-ui-plan.md) | Approved information architecture, design decisions, the (now retired) `?new=true` delivery approach, and the phase-by-phase roadmap. Historical: the plan shipped. |
| [`new-ui.md`](new-ui.md) | **Execution tracker.** The itemized mockup-to-real transfer checklist (F/S/P/W items). Hardeep picks items by ID; check them off as they land. |
| [`current-ui-lite.md`](current-ui-lite.md) | Historical (pre-refresh): exhaustive map of the old lite dashboard: routes, every page/section/control with settings keys, store, `window.hyve` payload, REST surface, styling, conditional gates. |
| [`current-ui-pro.md`](current-ui-pro.md) | Historical (pre-refresh): how the Pro plugin extended the old lite app: every filter, every Pro component and its controls, Pro REST endpoints and localized data, lite↔pro slot wiring. |
| [`incoming-settings.md`](incoming-settings.md) | Settings arriving in still-open PRs (issues #176 system prompt, #232 capabilities, #221 model list) with exact keys and control types. Shipped items get removed from the page. |
| [`future-features.md`](future-features.md) | Planned issues with no PR yet (#164, #168, #185, #192, #195) and where the new IA reserves room for them. |
| [`mockup-v2.html`](mockup-v2.html) | **Current mockup**: the 4-tab IA with the Settings sidebar, all incoming/planned placeholders, all drill-in flows and modals, and a floating preview-state switcher (Free/Pro, Admin/Messages-only, API set/unset). Open directly in a browser. |
| [`QA.md`](QA.md) | Manual test checklist for the dashboard, including every state each card/screen can be in and how to force it. Update it whenever an item lands. |

## Project rules

- **No em/en dashes ("—", "–") anywhere in UI copy.** Use commas, colons, parentheses or separate sentences. Hardeep: "We want our plugin to look human, not AI."

- The refreshed UI **is the dashboard** since 2026-07-10: the `?new=true` flag and the old app are deleted. Code home: `src/backend/` in lite (screens/, components/, data/, router.js, store.js) and `src/` in pro (one file per panel, registered from `index.js`).
- The mockup is a baseline; we're free to design something better.
- Every incoming/future setting gets a placeholder or reserved slot so the structure survives upcoming features.
- Snapshot date on the research docs: **2026-07-08** — re-verify PR/issue state on GitHub before building on them.
