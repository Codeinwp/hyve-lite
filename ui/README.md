# Hyve Dashboard UI refresh — knowledge base

Working folder for [hyve#223 — Refresh Dashboard UI](https://github.com/Codeinwp/hyve/issues/223). These docs are the persistent source of truth for the project so any session (human or AI) can pick up where the last one left off.

## Contents

| File | What it holds |
|------|---------------|
| [`new-ui-plan.md`](new-ui-plan.md) | **Start here.** Approved information architecture, design decisions, the `?new=true` delivery approach, and the phase-by-phase roadmap with current status. |
| [`new-ui.md`](new-ui.md) | **Execution tracker.** The itemized mockup-to-real transfer checklist (F/S/P/W items). Hardeep picks items by ID; check them off as they land. |
| [`current-ui-lite.md`](current-ui-lite.md) | Exhaustive map of the existing lite dashboard: routes, every page/section/control with settings keys, store, `window.hyve` payload, REST surface, styling, conditional gates. |
| [`current-ui-pro.md`](current-ui-pro.md) | How the Pro plugin extends the lite app: every filter, every Pro component and its controls, Pro REST endpoints and localized data, lite↔pro slot wiring. |
| [`incoming-settings.md`](incoming-settings.md) | Settings arriving in open PRs (issues #250, #184, #152, #176, #232, #221, plus #248) with exact keys and control types — each needs a placeholder in the new UI. |
| [`future-features.md`](future-features.md) | Planned issues with no PR yet (#164, #168, #185, #192, #195) and where the new IA reserves room for them. |
| [`reference-mockup.html`](reference-mockup.html) | Local copy of the baseline mockup artifact from the issue (treat as direction, not spec). |
| [`mockup-v2.html`](mockup-v2.html) | **Current mockup**: the approved Chat/AI IA with all incoming/planned placeholders, all drill-in flows and modals, and a floating preview-state switcher (Free/Pro, Admin/Messages-only, API set/unset). Open directly in a browser. |
| [`QA.md`](QA.md) | Manual test checklist for everything built so far behind `?new=true`, including every state each card/screen can be in and how to force it. Update it whenever an item lands. |
| [`variations-upsell-chart.html`](variations-upsell-chart.html) | Side-by-side design variations (conversations upsell, chart color pairings) used to pick the shipped treatments. Open directly in a browser. |

## Project rules

- **No em/en dashes ("—", "–") anywhere in UI copy.** Use commas, colons, parentheses or separate sentences. Hardeep: "We want our plugin to look human, not AI."

- New UI ships first as a **non-functional working UI** at `admin.php?page=hyve&new=true`, parallel to the live dashboard. Prototype code home: `src/backend/next/` (docs stay here in `ui/`).
- The mockup is a baseline; we're free to design something better.
- Every incoming/future setting gets a placeholder or reserved slot so the structure survives upcoming features.
- Snapshot date on the research docs: **2026-07-08** — re-verify PR/issue state on GitHub before building on them.
