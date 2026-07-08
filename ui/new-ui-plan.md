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
- **Connections**: Integrations (Qdrant, Hyve Connect, Webhooks slot)
- **Plugin**: General (license, site integration)

Consequences: the Settings tab is reachable WITHOUT an API key (the key lives there now); with no key, sidebar items other than the AI pair are muted, and gated panels redirect to Provider & model. Deep links use `?nav=settings&sub=chat-behavior` style keys. Everything below this line describes v1 (7 tabs) and remains for the per-section content details, which are unchanged; only the placement moved.

Top-level navigation (v1, superseded — 7 items):

1. **Dashboard** — stats cards (Sessions, Messages, KB usage meter; slot reserved for a Hyve Agent quota card and an "answer rate"-style metric if we add it), usage chart, recent conversations, get-started cards, and the **NUX setup checklist** (see "New user experience" below). The "Where should Hyve appear?" block MOVES OUT of here (→ Chat > Visibility), but the Dashboard keeps a status notice with a "Manage visibility" shortcut that deep-links there (like the reference mockup's notice).
2. **Knowledge Base** — sub-views: All sources (add-source grid + indexed content), **Needs Attention** (Requires Update + Failed Moderation merged into one review inbox, with an "Issue" column distinguishing "Edited since indexing" from "Failed moderation"), FAQ (Pro). The Cosine Similarity Threshold moves out (→ AI > Advanced).
3. **Messages** — conversation list/thread view, search, export (Pro). Reserved slot: a **Leads** sub-view (#168/#192). Must render standalone for `hyve_read_messages`-only users (PR #194).
4. **Chat** — everything about the visitor-facing widget, two groups:
   - **Behavior**: visibility first (display mode + URL rules card at the top, moved from Dashboard), then welcome message, default message, chat sound, suggested questions (Pro), follow-up questions (Pro, PR hyve#259), show source link (PR lite#180), privacy notice (PR lite#192); reserved: proactive messages (Pro, #185), lead capture form (#168/#192).
   - **Appearance**: position, timestamps, name/icon/colors (Pro) with the live preview beside the form.
5. **AI** (assistant/brain) —
   - **Provider & model**: reserved provider selector (Hyve Agent #164 / OpenAI), API key + connection status, model select (new list from PR lite#174), system prompt (Pro, PRs lite#193 + hyve#252).
   - **Advanced**: temperature + top_p (moved here per #248), similarity threshold (moved from KB Options).
   - Reserved sub-page: **Tools/Abilities** allowlist screen (Pro, #195) incl. designed empty/unsupported state.
6. **Integrations** — Qdrant, Hyve Connect (semantic search + access tokens, Pro); reserved: Webhooks (#192).
7. **Settings** — plugin housekeeping only: license (Pro), "Add to Hyve" post row action, telemetry. (Role-based Messages access from PR lite#194 is capability-only by design — no settings UI for it anywhere.)

Notes:
- This departs from the mockup's top tabs (which promoted Appearance and Assistant to top level but left welcome/default message in Settings > General). The categorization principle here: **Chat = what visitors experience, AI = how answers are produced, Settings = plugin administration.** Alternative groupings are fair game for the discussion.
- PR lite#194 registers real WP admin submenu items per top-level route, each with a capability, and boots the app from `window.hyve.view`. The new nav must map 1:1 to those submenus, and any top-level rename here means updating that submenu list.

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

1. **IA**: the Chat / AI split above is approved.
2. **Nav pattern**: top tab bar + sticky plugin header bar (logo, version, status, docs, upgrade). Sub-sections render as a subnav inside the page. Maps 1:1 to PR #194's WP submenu entries.
3. **Visual language**: **WP-native** — WP admin blues (#2271b1), flat 2px-radius cards, subsubsub-style subnavs. Explicitly considered and rejected Chakra UI (third styling paradigm, CSS-in-JS runtime, fights WP admin CSS/wp-components); Hyve brand appears only in the logo mark and the chat-widget preview.
4. **Component strategy**: keep `@wordpress/components` for controls (a11y, native look), build our own small layout kit (cards, stat tiles, chips, subnav, tables) styled with Tailwind to the WP palette. Unify the bespoke FAQ/Sitemap tables onto the shared table component.
5. **Dashboard metrics**: **UI-first, slots reserved** — render existing `stats`/`chart` data; recent-conversations feed mocked in the prototype; deltas/sparklines/answer-rate only if trivially derivable, otherwise follow-up backend issues. A dashed placeholder card reserves the Hyve Agent quota slot.
6. **Upsell presentation** (recommendation, validate in mockup review): small PRO chips + inline lock notes instead of dimmed fake controls; per-section gating where a whole feature is Pro (e.g. Tools), per-control where a single field is (e.g. system prompt).
7. **New extension surface for Pro** (recommendation, finalize in Phase 5): replace the one-off filter-per-upsell pattern with a declarative section registry — screens and settings sections declared as data (like `ROUTE_TREE`), Pro registers/overrides entries rather than adding new filters.
8. **Lite/Pro code split — DECIDED 2026-07-08**: hybrid, with one rule: *whoever owns the endpoint owns the UI code.*
   - **Settings-backed pro features live in lite, gated by license** (suggested questions, appearance name/icon/colors, system prompt when it lands, the export button). The UI just writes settings keys; enforcement stays server-side in pro (`hyve_frontend_data`, `hyve_threads_per_page`, the export endpoint), so unlocking the flag client-side yields nothing. One implementation, no lite/pro markup drift (the old UI's duplicated fake color tiles are the anti-pattern this kills), and free users see the real UI disabled next to the upsell.
   - **Pro-only surfaces live in pro, registered via filters** (KB sources: custom/URL/sitemap/documents, FAQ, access tokens, license card): their REST endpoints exist only in pro, so lite ships only a locked placeholder/upsell in the slot.
   - **One registration surface, not nine ad-hoc filters**: pro extends through `hyve.next.routes` (screens/subs) plus at most one component-slot filter. That is the whole P1 contract.
   - **Gate semantics**: `window.hyve.license` (pro plugin installed) unlocks the UI, matching the old UI's behavior; `window.hyve.hasPro` (license validity) stays a server-side/update concern.

## Delivery approach: `?new=true` parallel app

- Lite's `App.js` (or `index.js` bootstrap) checks `new=true` in the URL and mounts `NewApp` instead of the current app. Suggested code home: `src/backend/next/` in hyve-lite (docs stay in `ui/`).
- The new app is presentational: reads `window.hyve` + `GET settings` for realistic data where convenient, but all mutations are no-ops/mocked; every screen exists, incoming settings render as placeholder controls, future features as clearly-labeled placeholder sections.
- Pro compatibility during the prototype phase: the new app does NOT need to honor the existing pro filters initially; it fakes the "pro" state behind a toggle so both tiers can be previewed. Real extension points get designed in decision #4 and wired when functionality lands.

## Roadmap / TODO

- [x] **Phase 0 — Research** (this folder): current UI maps (lite + pro), incoming settings, future features. DONE 2026-07-08.
- [x] **Phase 1 — IA & design decisions**: IA + decisions agreed (see above); v2 mockup built at `ui/mockup-v2.html` (open it directly in a browser). Iterate on review feedback before starting Phase 2.
- [ ] **Phases 2-5 — Build**: tracked item by item in [`new-ui.md`](new-ui.md) (F = foundations, S1-S7 = screens, P = pro lockstep, W = wire-up and ship). Hardeep picks the order.

## Review feedback incorporated (2026-07-08)

- Requires Update + Failed Moderation merged into a single **Needs Attention** sub-page under Knowledge Base.
- Dashboard keeps a status notice with a **Manage visibility** shortcut deep-linking to Chat > Visibility.
- **No role/capability UI anywhere** — PRs lite#194 / hyve#253 are capability-only; the Access card was removed from Settings.
- **No em/en dashes in any UI copy** (see README project rules).
- Dashboard "Recent conversations" shows only data we actually store (message counts, no invented answered/unanswered status). Aggregate unanswered questions ARE tracked (`hyve_unanswered_questions` powers the Pro FAQ page), but per-thread status is not.
- **NUX from #202** folded in: setup checklist on Dashboard, no wizard (see "New user experience" section).
- The mockup has a floating **preview-state switcher** (bottom-right eye button) to flip between Free/Pro, Admin/Messages-only, and API connected/not set.
- **Visibility is not its own Chat sub-tab**: the "Where should Hyve appear?" card sits at the top of Chat > Behavior; the Dashboard "Manage visibility" shortcut deep-links there.
- **Header status pill is just the API connection indicator** (API connected / not connected), provider-agnostic for Hyve Agent later; no chat-visibility wording in the header.
- **No count badge on the Messages tab**, and **no PRO chips in nav or subnav**: pro features are discovered by clicking through to an in-screen upsell (upsell design happens later). With no API key, api-required tabs are muted but Dashboard stays open showing ONLY the NUX checklist (all steps locked except "Add API key"); AI stays open to enter the key.
- **Full flow coverage** added after review ("not all UI parts are made"): the 5 Knowledge Base source drill-in views (WordPress import with a restricted-content case, Custom data, Website URL, Sitemap, Documents with import-queue statuses), the Messages conversation thread view (bubbles + delete), Qdrant's three connection states (not connected / migrating with progress / connected), 8 modals (add-edit data, restricted content, moderation review with score bars, sensitive-data review, sitemap add with link picker, sitemap details, Qdrant disconnect confirm, token delete confirm), and a snackbar so every Save/action gives feedback.

## How to continue in a fresh AI session

Read `ui/README.md` first, then this file. The docs are the source of truth; verify PR states on GitHub before trusting `incoming-settings.md` (things merge). The current-UI maps include file:line references — spot-check them if the codebase has moved on.
