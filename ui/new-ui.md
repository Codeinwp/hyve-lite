# New UI: mockup-to-real transfer checklist

The execution tracker for turning `ui/mockup-v2.html` into the real dashboard. We work through it one item at a time: Hardeep picks the next item by its ID (for example "do S2.3"), it gets built, checked off, and any deviation from the mockup gets noted inline.

Rules of engagement:

- One item per session/PR unless items are explicitly bundled.
- An item is done when it works at `admin.php?page=hyve&new=true`, is i18n-wrapped, has no em/en dashes in copy, and matches the mockup (or the mockup gets updated to match reality, noted here).
- Prototype phase: mutations may be no-ops where marked [mock-first]; the wire-up milestone (W) makes them real.
- Tags: [PRO] pro tier, [PR-dep] blocked on an open PR merging, [backend] needs new PHP/endpoint work, [decision] needs a call before building.

Legend: `[ ]` todo · `[x]` done · `[~]` in progress

## F. Foundations (shell and plumbing)

- [x] **F1. `?new=true` bootstrap**: in lite `src/backend/index.js`/`App.js`, when the URL has `new=true`, mount the new app from `src/backend/next/` instead of the current one. Old UI untouched otherwise. (Done 2026-07-08: `index.js` renders `next/App` when `new=true`.)
- [x] **F2. `next/` scaffold**: folder structure (`next/App.js`, `next/router.js`, `next/screens/*`, `next/components/*`, `next/data/*`), lint clean, builds with the existing wp-scripts setup. (Done 2026-07-08: `next/App.js` + `next/style.scss` land the scaffold; `router.js`/`screens/`/`components/`/`data/` get created by F3/F6 when their first files exist.)
- [x] **F3. Route registry**: data-driven tree for the 7 screens + sub-panels (Dashboard; KB: all/attention/faq + 5 source drills; Messages: conversations/leads/thread; Chat: behavior/appearance; AI: provider/advanced/tools; Integrations; Settings). Each entry: label, icon, component, capability, pro flag, api-required flag. Designed so Pro can register/override entries (decision 7 in `new-ui-plan.md`). (Done 2026-07-08: `next/router.js`; Pro extends via the `hyve.next.routes` filter; drill-ins are `hidden` subs, planned panels are `planned` subs. Components attach per screen in S items.)
- [x] **F4. Deep linking + browser history**: screen in the URL via `?nav=`, sub-panel via `&sub=`. `navigate()` writes the URL with `history.pushState` and back/forward restores views (popstate), so every view is linkable and history works both ways. Deliberately NO legacy route-key aliases and NO `window.hyve.view` handling: when PR lite#194 (capabilities/submenus) merges, the router gets adapted to what it actually ships (tracked in W2). (Done 2026-07-08: `parseLocation`/`navigate`/`useRoute` in `next/router.js`; App renders a temporary tab row + subnav until F7/F8.)
- [x] **F5. WP palette + Tailwind tokens**: map the mockup's CSS variables (wp-blue #2271b1, borders, surfaces, ok/warn/bad, 2px radii) into the Tailwind config/theme for `next/`. (Done 2026-07-08: tokens live as `--hyve-*` CSS custom properties scoped under `.hyve-next` in `next/style.scss`; Tailwind arbitrary values can reference them, and the layout kit (F6) consumes them directly. Includes temporary shell chrome that F7 replaces.)
- [ ] **F6. Layout kit components** (each small, reusable, mockup-faithful):
  - [x] F6.1 Card (header, actions, form footer; done 2026-07-08: `next/components/Card.js`)
  - [ ] F6.2 PlannedCard (dashed variant)
  - [x] F6.3 Chip (ok/warn/bad/muted with dot; done 2026-07-08: `next/components/Chip.js`; PRO/INCOMING variants when upsell design lands)
  - [x] F6.4 StatCard (label, number, foot, optional meter, planned variant; done 2026-07-08: `next/components/StatCard.js`, meter built in so F6.11 is covered too)
  - [ ] F6.5 DataTable (columns, row actions, empty state, load more) replacing PostsTable; also used where FAQ/Sitemap had bespoke tables
  - [ ] F6.6 Subnav (subsubsub style, badge support)
  - [x] F6.7 FieldRow (label + description left, control right; stacks on mobile; done 2026-07-08: `next/components/FieldRow.js`)
  - [ ] F6.8 BackRow + drill navigation helper
  - [ ] F6.9 EmptyState (icon, title, text)
  - [ ] F6.10 LockNote (pro lock note) + upsell link helper with UTM campaigns preserved (`appearance-settings`, `suggested-questions-settings`, `system-prompt-settings`, `messages-feature`, `custom-data-feature`, `website-crawling-feature`, `sitemap-crawling-feature`, `document-import-feature`, `faq-feature`, `api-search`, `sidebar-banner` equivalent)
  - [x] F6.11 Meter (usage bar; done 2026-07-08 as part of StatCard, `.hyve-next-stat__meter`)
  - [ ] F6.12 Steps (NUX checklist rows with done/locked states)
  - [ ] F6.13 Modal wrapper (use `@wordpress/components` Modal, styled to mockup)
  - [x] F6.14 Snackbar (`core/notices` snackbar; done 2026-07-08: `next/components/Notices.js` + `next/data/useSaveSettings.js` shared save hook with success/error notices)
- [x] **F7. Header bar**: logo mark (the official plugin icon at `assets/images/icon.png` via `window.hyve.assets.images`), plugin name, real version pill (`window.hyve.version`, newly localized from `HYVE_LITE_VERSION`), API status pill (API connected / API not connected; provider-agnostic so it covers Hyve Agent later), Docs link, Upgrade CTA hidden when `window.hyve.license` exists (UTM campaign `header-upgrade`). (Done 2026-07-08: `next/components/HeaderBar.js`; App now bootstraps settings into the `hyve` store on mount, which starts F9.)
- [x] **F8. Tab nav**: 7 tabs with icons, active state synced with router; when no API key, api-required tabs are muted but Dashboard and AI stay usable, with a replace-redirect to Dashboard if the current screen requires the key (Dashboard then shows only the NUX checklist). No count badge on Messages (dropped). No PRO chips anywhere in nav or subnav: users click through and meet the upsell inside the screen (upsells designed later). Capability filtering stays data-only until PR lite#194 (W2). (Done 2026-07-08: `next/components/TabNav.js`.)
- [ ] **F9. Store**: reuse the `hyve` data store (settings, hasAPI, totalChunks, serviceErrors); add route/subroute state for the new router. No second source of truth for settings.
- [ ] **F10. ErrorSection + notices**: port service-error rendering and the `apiFetch` serviceErrors middleware into the new shell.
- [ ] **F11. tsdk banner slot** [decision]: where the Themeisle promo banner div lives in the new layout (or whether `?new=true` skips it during the prototype).
- [ ] **F12. Tracking**: `hyveTrk` events on nav + key actions, matching current feature/featureComponent naming.

## S1. Dashboard screen

- [x] **S1.1 Status notice**: "Chat is live on all pages" wording driven by real `display_mode` (all/include/exclude/manual variants) + "Manage visibility" deep link to Chat > Visibility. (Done 2026-07-08: `VisibilityNotice` in `next/screens/Dashboard.js`; hidden while the knowledge base is empty since the chat is not live then.)
- [x] **S1.2 NUX setup checklist** (#202): steps from real state (hasAPI, totalChunks > 0, isQdrantActive), steps 2 and 3 locked until step 1, Qdrant framed optional, card disappears when required steps done; strictly state-driven, not dismissible. (Done 2026-07-08: `next/components/SetupChecklist.js` + `next/screens/Dashboard.js`; totalChunks read from `window.hyve.stats` at load, live-wired in W1.)
- [x] **S1.3 Stat cards**: Sessions (`stats.threads`), Messages (`stats.messages`), Knowledge base with meter + free-limit foot (`totalChunks`/`chunksLimit`, plain number when Qdrant active, "Need more storage?" link when > 400 and no Qdrant). (Done 2026-07-08: `StatsGrid` in `next/screens/Dashboard.js`; page-load snapshot from `window.hyve.stats`, live refresh stays W1.)
- [~] **S1.4 Hyve Agent quota card** [decision]: dashed placeholder in production or behind a flag until #164. (Built 2026-07-08 as the dashed planned StatCard; the production-visibility decision stays open for the W7 flag flip.)
- [x] **S1.5 Chat usage chart**: real `window.hyve.chart` data (messages + sessions series, 7/14/30/90-day selector like today). DECIDED: chart.js (already bundled). (Done 2026-07-08: `next/components/UsageChart.js`, one combined line chart with both series per the mockup, replacing the old UI's two stacked bar charts; empty state note when there is no data yet. Card title is "Usage"; Messages is WP blue with light fill, Sessions is solid amber #dba617, picked from `ui/variations-upsell-chart.html`.)
- [x] **S1.6 Recent conversations**: latest threads with title, message count, relative time; View all → Messages. DECIDED (2026-07-08): free shows 3 + an upsell block the height of the 2 missing rows (only when more conversations exist); Pro shows 5; empty state note when there are none. Uses `GET threads` first page as-is; a light "recent" endpoint stays optional [backend]. (Done 2026-07-08: `RecentConversations` in `next/screens/Dashboard.js`, upsell UTM campaign `messages-feature`. Upsell style: the "blue note" variant pinned to the card bottom, picked from `ui/variations-upsell-chart.html`; no avatars on rows.)
- [x] **S1.7 Get started cards**: three cards with router links + tracking (`get-started-shortcut`, same featureValue ids as the old UI). (Done 2026-07-08 in `next/screens/Dashboard.js`; also added the shared page heading style `.hyve-next-pagehead`, currently used by Dashboard only.)
- [x] **S1.8 No-API dashboard state**: Dashboard stays accessible and shows ONLY the setup checklist (no stats, chart, notices or get-started); api-required tabs muted (replaces the old blur overlay). (Done 2026-07-08 in `next/screens/Dashboard.js`.)

## S2. Knowledge Base screen

- [ ] **S2.1 Subnav**: All sources / Needs attention (live count badge) [backend: pending+moderation count] / FAQ [PRO].
- [ ] **S2.2 Add a source grid**: 5 cards from a source registry (Pro swaps components, clears PRO chips), drill navigation, tracking per source.
- [ ] **S2.3 Indexed content table**: unified list with Source column (Page/Post/URL/Document/Custom), chunk counts, status chips, Remove/Update actions. [backend: aggregate WP data + pro knowledge/links/documents into one listing, or client-side merge; today they are separate endpoints]
- [ ] **S2.4 Needs attention**: merged Requires Update (`GET data?status=pending`) + Failed Moderation (`GET data?status=moderation`) with Issue column, per-row Update / Review / Retry, "Update all" [backend: bulk update action or client loop].
- [ ] **S2.5 Moderation review modal**: flagged categories with score bars (12 category labels from `utils.js`), Override action (`onProcessData action=override`).
- [ ] **S2.6 FAQ panel** [PRO]: `GET {api}/faq` top questions with counts, Answer opens add-data modal prefilled, Delete row action; free tier shows chips + lock per upsell decision.
- [ ] **S2.7 WordPress drill-in**: content-type select (from `window.hyve.postTypes`), debounced search, add table (`GET data?type&search&offset`), Add action with chunk limit gating, restricted-content confirm modal for private/password posts, "Added" state for included rows.
- [ ] **S2.8 Custom data drill-in** [PRO]: search + list (`GET {api}/knowledge`), Add data + Edit via modal, delete inside modal, limit gating.
- [ ] **S2.9 Add/edit data modal**: title + content (4,000 char limit), Save/Delete, moderation-failure escalation to S2.5. Shared by S2.6/S2.8.
- [ ] **S2.10 Website URL drill-in** [PRO]: URL validation + Crawl (`POST {api}/links`), table with Indexed/Crawling status, Update/Delete per row.
- [ ] **S2.11 Sitemap drill-in** [PRO]: sitemap table (`GET {api}/sitemap`), Add sitemap modal (fetch `GET sitemap/fetch?url=`, link checkboxes, select/deselect all, import `POST sitemap`), Details modal (indexed links, delete sitemap).
- [ ] **S2.12 Documents drill-in** [PRO]: MediaUpload multi-select (PDF/DOCX/MD/TXT/CSV), import queue with Queued/Processing/Needs review/Failed/Done statuses, sensitive-data review modal (categories, Cancel/Import anyway with `confirm_sensitive`), Delete per row.
- [ ] **S2.13 Chunk-limit notice**: single shared component used across all add flows (replaces today's copy-pasted Notices).

## S3. Messages screen

- [ ] **S3.1 Subnav**: Conversations / Leads [PLANNED placeholder; decision: visible in production or dev-flag only].
- [ ] **S3.2 Conversations table**: `GET threads` with pagination (Load more; free tier shows first page + upsell per current gating), title + snippet + message count + date.
- [ ] **S3.3 Search** [backend]: thread search endpoint (does not exist today; mockup has the box). Decide: build endpoint or drop the box until then.
- [ ] **S3.4 Thread drill-in**: full conversation bubbles with timestamps, thread metadata, Delete conversation (needs `hyve_manage_messages` when PR lite#194 lands; admin-only until then).
- [ ] **S3.5 Export CSV** [PRO]: `window.hyve.exportMessagesURL` link, hidden without manage capability, free tier lock + upsell.
- [ ] **S3.6 Messages-only user rendering**: whole app boots into Messages with other tabs absent (depends on PR lite#194's `view`/capability plumbing).

## S4. Chat screen

- [ ] **S4.1 Behavior > Conversation card**: `welcome_message`, `default_message` (TextControls), `sound_enabled` toggle, Save. (Sits below the visibility card, S4.9.)
- [ ] **S4.2 Behavior > Suggestions card** [PRO]: 3 `predefined_questions` fields; free tier chips + lock.
- [ ] **S4.3 Follow-up questions toggle** [PRO][PR-dep hyve#259]: `follow_up_questions` placeholder control until merge, then wired.
- [ ] **S4.4 Source links toggle** [PR-dep lite#180]: `show_source_link` placeholder until merge.
- [ ] **S4.5 Privacy notice toggle** [PR-dep lite#192]: `privacy_notice_enabled` + "no privacy page" warning (`window.hyve.hasPrivacyPage`, `privacySettings` link).
- [ ] **S4.6 Proactive messages + Lead capture planned cards** [decision: production visibility].
- [ ] **S4.7 Appearance form**: `chat_position`, `show_timestamp` (free); `chat_name`, `chat_icon` (icon set + MediaUpload custom image), 4 color tiles with ColorPicker + reset [PRO]; free tier chips + lock note.
- [ ] **S4.8 Live preview**: embed the real widget preview beside the form, push changes via `window.hyveApp.applyPreviewAppearance` (position, timestamps, name, colors + dark-color mapping, icon, privacy notice line).
- [ ] **S4.9 Visibility card** (top of the Behavior panel, not its own sub-tab): `display_mode` radio cards, `display_rules` URL-rule editor (path + contains/matches, add/remove), saved by the Behavior Save; moved off the old Dashboard. The Dashboard "Manage visibility" shortcut deep-links here.

## S5. AI screen

- [ ] **S5.1 Provider selector**: OpenAI active, "Hyve Agent (coming soon)" disabled [decision: production visibility until #164].
- [x] **S5.2 API key field**: `api_key` password input, status chip (Connected/Not connected/Unsaved + "Get an API key" link when empty), Save posts real settings, updates `hasAPI` (unlocks tabs, ticks checklist step 1), surfaces endpoint warnings. (Done 2026-07-08: `next/screens/AI.js` ProviderPanel.)
- [x] **S5.3 Model select** [PR-dep lite#174]: shipped with the CURRENT model list as a SelectControl (gpt-4o-mini recommended first, 4.1 family, 4o, 3.5 legacy); swap in the refreshed list when PR lite#174 merges. (Done 2026-07-08 in `next/screens/AI.js`.)
- [ ] **S5.4 System prompt** [PRO][PR-dep lite#193 + hyve#252]: textarea via the `hyve.systemPrompt` slot equivalent; free tier lock.
- [x] **S5.5 Advanced panel**: `temperature`, `top_p` (moved per #248), `similarity_score_threshold` (moved from KB Options; same key), Reset to defaults, Save. (Done 2026-07-08: `next/screens/AI.js` AdvancedPanel.)
- [x] **S5.6 Tools panel** (#195): DECIDED, not present in the real dashboard for now (removed from the AI subnav/registry); stays in the mockup only. Revisit when #195 gets built.

## S6. Integrations screen

- [ ] **S6.1 Qdrant card, three states**: not connected (key + endpoint + Connect via settings POST), migrating (progress from `GET {api}/qdrant`, 10s polling), connected (cluster shown, Disconnect).
- [ ] **S6.2 Qdrant disconnect confirm modal**: `POST {api}/qdrant` deactivate, state returns to not connected.
- [ ] **S6.3 Hyve Connect card** [PRO]: semantic search description + curl sample (`rest_url` + `knowledge-base/search`), token table (masked, Show/Copy), Generate token, Delete with confirm modal (`GET/POST/DELETE {api}/access-tokens`); free tier lock + upsell.
- [ ] **S6.4 Webhooks planned card** (#192) [decision: production visibility].

## S7. Settings screen

- [ ] **S7.1 License card** [PRO]: masked key, valid/expired/invalid states, Activate/Deactivate (`POST {api}/license`), renew/purchase-history links; absent in free tier.
- [ ] **S7.2 Site integration card**: `post_row_addon_enabled` toggle, `telemetry_enabled` toggle (auto-save like today's OthersSection).

## P. Pro plugin lockstep (Codeinwp/hyve)

- [ ] **P1. Extension surface**: implement the section/route registry hooks in lite; document the contract (replaces `hyve.route`, `hyve.data`, `hyve.appearance.options`, `hyve.suggestedQuestions`, `hyve.systemPrompt`, `hyve.others`, `hyve.tokens-management`, `hyve.messages.load-more`, `hyve.messages.export-messages`).
- [ ] **P2. Port pro registrations**: FAQ, Custom/URL/Sitemap/Documents sources, Appearance options, Suggested questions (+follow-ups), System prompt, License/Advanced, Access tokens, Messages pagination + export. Old filters kept working until the old UI is removed.
- [ ] **P3. Shared components bridge**: replace `window.hyveComponents` (PostsTable/PostModal) with the new DataTable/modal exports; keep the old export until pro is migrated.

## W. Wire-up and ship

- [ ] **W1. Mock-first audit**: list every [mock-first] mutation still stubbed; wire each to its real endpoint.
- [ ] **W2. Capability/submenu integration**: align with PR lite#194 (WP submenus per tab, `window.hyve.view` boot, `canManageMessages`).
- [ ] **W3. Responsive pass**: tabs scroll, grids stack, tables scroll horizontally, per mockup breakpoints.
- [ ] **W4. Accessibility pass**: focus states, aria on tabs/subnavs/modals/toggles, Escape closes modals, reduced motion.
- [ ] **W5. Copy pass**: i18n coverage, no em/en dashes, human tone (README rule). Error/notice strings must be location-neutral: never name a screen/tab in copy served by shared PHP (fixed in `OpenAI::get_error_message_for_code()` 2026-07-08; sweep for others).
- [ ] **W6. Tests**: update e2e specs (dashboard.spec + new flows), unit tests where logic warrants (per test philosophy: our logic, not framework).
- [ ] **W7. Flag flip plan**: `?new=true` becomes default, old app behind `?legacy=true` for one release, then delete old `parts/` + `Sidebar` + dead styles.
- [ ] **W8. Docs**: update `ui/` docs + AGENTS.md/CLAUDE.md pointers to the new structure; close #223 with a summary.

## Backend inventory (referenced above)

| Item | Needed by | Status |
|------|-----------|--------|
| Unified indexed-content listing | S2.3 | new endpoint or client merge [decision] |
| Needs-attention count | S2.1 | new (cheap count query) |
| Bulk "Update all" | S2.4 | new or client loop [decision] |
| Recent conversations (latest 5) | S1.6 | likely covered by `GET threads`, verify |
| Thread search | S3.3 | new [decision] |
| Everything else | all screens | existing endpoints (see `current-ui-lite.md` §5, `current-ui-pro.md` §4) |

## Suggested order (Hardeep decides, this is just a sane default)

F1 → F2 → F5 → F6 → F3/F4 → F7/F8 → S1 → S4 → S5 → S2 → S3 → S6 → S7 → P1/P2 → W.
