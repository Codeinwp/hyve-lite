# New dashboard UI — plan and roadmap

Parent issue: [hyve#223 — Refresh Dashboard UI](https://github.com/Codeinwp/hyve/issues/223).

## Ground rules agreed so far

- The [baseline mockup artifact](https://claude.ai/code/artifact/a835ff8a-8f27-497c-94dd-101d2d1dd34c) ("Hyve — Dashboard (WP-native)", a copy lives at `ui/reference-mockup.html`) is a **direction, not a spec** — we can and should go beyond it.
- The new dashboard is built as a **working UI without real functionality** first (mock data, no writes), reachable at the current dashboard URL + `?new=true` (i.e. `admin.php?page=hyve&new=true`), so day-to-day work on the current UI continues in parallel.
- Every in-flight setting (see `incoming-settings.md`) gets a **placeholder control** in its final position; every future feature (see `future-features.md`) gets a **reserved slot** so the IA won't need another overhaul.
- kushh23's requirement on #223: reorganize and properly categorize settings.

## Information architecture

**REVISED 2026-07-09 (v2, approved and shipped)**: the top navigation flattened to **4 tabs**: Dashboard, Knowledge Base, Messages, Settings. The former Chat, AI, and Integrations tabs became grouped panels inside **Settings**, which renders a left sidebar (WP-style: sticky, uppercase group labels, blue active bar):

- **Chat**: Behavior (default panel), Appearance
- **AI**: Provider & model, Advanced (Tools stays mockup-only until #195)
- **Integrations**: Qdrant, API Access (Webhooks joins the group when #192 is real)
- **Plugin**: General (license, site integration)

Consequences: the Settings tab is reachable WITHOUT an API key (the key lives there now); with no key, sidebar items other than the AI pair are muted, and gated panels redirect to Provider & model. Deep links use `?nav=settings&sub=chat-behavior` style keys. Per-section content is specified item by item in [`new-ui.md`](new-ui.md); the superseded 7-tab v1 layout is not documented here (see git history if ever needed).

## New user experience (issue [hyve#202](https://github.com/Codeinwp/hyve/issues/202))

Hardeep's direction: **NUX, not onboarding** — no wizard or modal flow; the default experience itself guides new users. Concretely:

- A **setup checklist card** on the Dashboard (WooCommerce/Yoast style), persistent until the required steps are done:
  1. Connect OpenAI (required) → deep-links to AI > Provider & model.
  2. Add content to the Knowledge Base (required) → locked until step 1, then deep-links to Knowledge Base.
  3. Connect Qdrant (clearly marked optional, framed as "for large sites") → Integrations.
- The header status pill flips to "Setup required" and non-usable tabs are visually muted until the key is connected (Dashboard and AI stay accessible, mirroring today's `home` + `advanced` behavior).
- Ship **great defaults** for everything else (kushh23/ineagu on #202): no configuration questions up front, straight to the API key.
- Ideas from the issue thread parked for later phases: use-case presets that tune the system prompt (WooCommerce store / support / docs assistant, from harshitarora's comment; pairs with the Pro `system_prompt` setting), and ineagu's "auto-import posts right after connect" default. Both are functionality, not mockup scope; revisit in Phase 5.

## Design decisions — RESOLVED 2026-07-08

1. **IA**: REVISED 2026-07-09, see the information architecture section above (4 tabs, Settings sidebar). The original Chat / AI top-level split shipped first and was then folded into Settings.
2. **Nav pattern**: top tab bar + sticky plugin header bar (logo, version, status, docs, upgrade). Sub-sections render as a subnav inside the page. Maps 1:1 to PR #194's WP submenu entries.
3. **Visual language**: **WP-native** — WP admin blues (#2271b1), flat 2px-radius cards, subsubsub-style subnavs. Explicitly considered and rejected Chakra UI (third styling paradigm, CSS-in-JS runtime, fights WP admin CSS/wp-components); Hyve brand appears only in the logo mark and the chat-widget preview.
4. **Component strategy**: keep `@wordpress/components` for controls (a11y, native look), build our own small layout kit (cards, stat tiles, chips, subnav, tables). REVISED during build: the kit is styled with plain scoped CSS (BEM classes + `--hyve-*` custom-property tokens in `next/style.scss`), deliberately NOT Tailwind, so deleting the old UI at W7 also drops Tailwind from the plugin. Unify the bespoke FAQ/Sitemap tables onto the shared table component.
5. **Dashboard metrics**: **UI-first, slots reserved** — render existing `stats`/`chart` data; recent-conversations feed mocked in the prototype; deltas/sparklines/answer-rate only if trivially derivable, otherwise follow-up backend issues. A dashed placeholder card reserves the Hyve Connect quota slot.
6. **Upsell presentation** (as shipped): the REAL controls render disabled (greyed) on free with small PRO chips on the locked rows; the pitch is a blue note block when the whole card is pro (Suggestions), or a muted line + secondary "Unlock with Pro" button inside the card footer when the card mixes free and pro fields (Appearance). List-style upsells (Recent conversations, Messages) use the blue note. Existing UTM campaign names are preserved.
7. **New extension surface for Pro** (recommendation, finalize in Phase 5): replace the one-off filter-per-upsell pattern with a declarative section registry — screens and settings sections declared as data (like `ROUTE_TREE`), Pro registers/overrides entries rather than adding new filters.
8. **Lite/Pro code split — DECIDED 2026-07-08**: hybrid, with one rule: *whoever owns the endpoint owns the UI code.*
   - **Settings-backed pro features live in lite, gated by license** (suggested questions, appearance name/icon/colors, system prompt when it lands, the export button). The UI just writes settings keys; enforcement stays server-side in pro (`hyve_frontend_data`, `hyve_threads_per_page`, the export endpoint), so unlocking the flag client-side yields nothing. One implementation, no lite/pro markup drift (the old UI's duplicated fake color tiles are the anti-pattern this kills), and free users see the real UI disabled next to the upsell.
   - **Pro-only surfaces live in pro, registered via filters** (KB sources: custom/URL/sitemap/documents, FAQ, access tokens, license card): their REST endpoints exist only in pro, so lite ships only a locked placeholder/upsell in the slot.
   - **One registration surface, not nine ad-hoc filters**: pro extends through `hyve.next.routes` (screens/subs) plus at most one component-slot filter. That is the whole P1 contract.
   - **Gate semantics**: `window.hyve.license` (pro plugin installed) unlocks the UI, matching the old UI's behavior; `window.hyve.hasPro` (license validity) stays a server-side/update concern.
9. **Naming — DECIDED 2026-07-09**: **"Hyve Connect" is reserved for the hosted AI provider** (#164, still titled "Hyve Agent" on GitHub): "Connect" fits a service you connect your site to, and "agent" will collide with tool calling (#195). The external-search/access-token feature (old UI's "Hyve Connect") is **"API Access"** in the new UI (`?nav=settings&sub=api-access`, Integrations group), a descriptive name for its developer audience; copy refers to "the Hyve API". Its panel shows the old UI's curl request preview on both tiers to make the feature concrete.

## Delivery approach: `?new=true` parallel app

- Lite's `App.js` (or `index.js` bootstrap) checks `new=true` in the URL and mounts `NewApp` instead of the current app. Suggested code home: `src/backend/next/` in hyve-lite (docs stay in `ui/`).
- The new app is fully functional, not presentational: settings load from and save to the real endpoints, and screens ship with working mutations (the original "mock-first" plan was dropped; nothing is stubbed). Unbuilt panels render placeholder cards.
- Pro state in the real app comes from `window.hyve.license` (pro plugin installed); only the mockup uses a preview toggle. The extension contract for pro-only surfaces is decision #8 / tracker item P1.

## Roadmap / TODO

- [x] **Phase 0 — Research** (this folder): current UI maps (lite + pro), incoming settings, future features. DONE 2026-07-08.
- [x] **Phase 1 — IA & design decisions**: IA + decisions agreed (see above); v2 mockup built at `ui/mockup-v2.html` (open it directly in a browser). DONE, including the 2026-07-09 IA revision.
- [ ] **Phases 2-5 — Build**: tracked item by item in [`new-ui.md`](new-ui.md) (F = foundations, S1-S7 = screens, P = pro lockstep, W = wire-up and ship). Hardeep picks the order.

## Review feedback incorporated (2026-07-08)

- Requires Update + Failed Moderation merged into a single **Needs Attention** sub-page under Knowledge Base.
- Dashboard keeps a status notice with a **Manage visibility** shortcut deep-linking to the visibility card (now Settings > Behavior).
- **No role/capability UI anywhere** — PRs lite#194 / hyve#253 are capability-only; the Access card was removed from Settings.
- **No em/en dashes in any UI copy** (see README project rules).
- Dashboard "Recent conversations" shows only data we actually store (message counts, no invented answered/unanswered status). Aggregate unanswered questions ARE tracked (`hyve_unanswered_questions` powers the Pro FAQ page), but per-thread status is not.
- **NUX from #202** folded in: setup checklist on Dashboard, no wizard (see "New user experience" section).
- The mockup has a floating **preview-state switcher** (bottom-right eye button) to flip between Free/Pro, Admin/Messages-only, and API connected/not set.
- **Visibility is not its own sub-tab**: the "Where should Hyve appear?" card sits at the top of the Behavior panel (now Settings > Behavior); the Dashboard "Manage visibility" shortcut deep-links there.
- **Header status pill is just the API connection indicator** (API connected / not connected), provider-agnostic for Hyve Connect later; no chat-visibility wording in the header.
- **No count badge on the Messages tab**, and **no PRO chips in nav or subnav**: pro features are discovered by clicking through to an in-screen upsell (upsell design happens later). With no API key, api-required tabs are muted but Dashboard stays open showing ONLY the NUX checklist (all steps locked except "Add API key"); Settings stays open with only the AI panels enabled, so the key can be entered.
- **Full flow coverage** added after review ("not all UI parts are made"): the 5 Knowledge Base source drill-in views (WordPress import with a restricted-content case, Custom data, Website URL, Sitemap, Documents with import-queue statuses), the Messages conversation thread view (bubbles + delete), Qdrant's three connection states (not connected / migrating with progress / connected), 8 modals (add-edit data, restricted content, moderation review with score bars, sensitive-data review, sitemap add with link picker, sitemap details, Qdrant disconnect confirm, token delete confirm), and a snackbar so every Save/action gives feedback.

## How to continue in a fresh AI session

Read `ui/README.md` first, then this file. The docs are the source of truth; verify PR states on GitHub before trusting `incoming-settings.md` (things merge). The current-UI maps include file:line references — spot-check them if the codebase has moved on.
