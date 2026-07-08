# Current UI map — hyve-lite (free plugin)

Exhaustive inventory of the existing admin dashboard, as of 2026-07-08 (branch `development`). File references are relative to the hyve-lite plugin root. See `current-ui-pro.md` for what the Pro plugin adds/overrides.

Backend React app root: `src/backend/`. Mount point `<div id="hyve-options">` rendered by `menu_page()` (`inc/Main.php:163`), single top-level admin menu page `hyve` (capability `manage_options`, `inc/Main.php:142-151`). App bootstrapped in `src/backend/index.js` via `createRoot`.

## 1. Navigation structure

### 1.1 Layout shell (`src/backend/App.js`)
- Root grid: `.grid.grid-cols-6.gap-8` inside `max-w-270` container; Sidebar `xl:col-span-2`, page `xl:col-span-4` (stacks below `xl`).
- Full-screen loading spinner overlay until `hasLoaded`.
- `#tsdk_banner` promo slot — hidden when `window.hyve.hasPro === 'valid'`.
- `<ErrorSection />` (service errors), `<Sidebar />`, active `<Page />`, `<Notices />` snackbars.
- On mount: fetches `GET {api}/settings`, reads `?nav=` URL param to set the initial route, fires `themeisle:banner:init`.
- Route resolution: `applyFilters('hyve.route', ROUTE_TREE)` flattened into a components map keyed by route slug (children promoted to top level) — route keys are globally unique.

### 1.2 `ROUTE_TREE` (`src/backend/route.js:38-112`)

| Route key | Label | Icon | Component | Children |
|-----------|-------|------|-----------|----------|
| `home` | Dashboard | `home` | `Home` | — (`disabled:false`) |
| `data` | Knowledge Base | `archive` | — | `data`, `update`, `flagged`, `faq` |
| `messages` | Messages | `comment` | `Messages` | — |
| `integrations` | Integrations | `blockMeta` | — | `integrations`, `search` |
| `settings` | Settings | `settings` | — | `settings`, `appearance`, `assistant`, `advanced` |

`data` children: `data` → Knowledge Base (`KnowledgeBase`), `update` → Requires Update (`Updated`), `flagged` → Failed Moderation (`FailedModeration`), `faq` → FAQ (`FAQ`, `isPro:true`).

`integrations` children: `integrations` → Qdrant (`Qdrant`), `search` → Hyve Connect (`ExternalSearch`, `isPro: !window.hyve.hasPro`).

`settings` children: `settings` → General, `appearance` → Appearance, `assistant` → Assistant (`disabled:true`), `advanced` → Advanced (`disabled:false`).

`disabled` semantics: Sidebar disables an item when `!hasAPI && false !== item.disabled` — before an API key is set, only `home` and `advanced` stay clickable.

### 1.3 `KNOWLEDGE_BASE` sources (`route.js:114-161`)
Card grid inside the Knowledge Base page (not sidebar routes):

| Key | Label | Component | isPro |
|-----|-------|-----------|-------|
| `posts` | WordPress | `Posts` | no |
| `custom` | Custom Data | `Custom` | yes |
| `url` | Website URL | `URLCrawler` | yes |
| `sitemap` | Sitemap | `SitemapCrawler` | yes |
| `documents` | Documents | `Documents` | yes |

### 1.4 Sidebar (`src/backend/parts/Sidebar.js`)
- Panel titled "Menu"; items are h-16 Buttons with icon + label, chevron when the item has children; children expand when the parent or one of its children is active; child rows show a blue "Pro" pill when `isPro`.
- Bottom "Upgrade to Premium" promo Panel when `hasAPI && !window.hyve.license` — note `window.hyve.license` is never set in lite, so it always shows once an API key exists.

## 2. Pages, sections, controls

### 2.1 Home / Dashboard (`src/backend/parts/Home.js`)
- `Home` gates on `hasAPI`: without a key it renders the dashboard behind an onboarding overlay (welcome copy, "Setup API Key" → route `advanced`, "Documentation" link).
- **"Where should Hyve appear?" radio cards** → `display_mode` (`all` | `include` | `exclude` | `manual`, default `all`).
  - When `include`/`exclude`: **Content URL rules** editor → `display_rules` array of `{path, operator}` (operator `contains`|`matches`), Add/Remove rows.
  - "Save visibility" primary button (saves whole settings object).
- **Empty-KB yellow warning** when `stats.totalChunks === 0`, link → route `data`.
- **"Overview" stat cards** (3): Sessions (`stats.threads`), Messages (`stats.messages`), Knowledge Base (`totalChunks` alone when Qdrant active, else `totalChunks / chunksLimit`; "Need more storage?" link → `integrations` when `!isQdrantActive && totalChunks > 400`).
- **"Get Started" cards** (3): Knowledge Base → `data`, Personalize → `settings`, Need help? → docs. Clicks log to `window.hyveTrk`.
- **"Chat Usage" Panel**: `UsageCharts` (chart.js) when chart data exists.

### 2.2 Messages (`src/backend/parts/Messages.js`)
- Two-pane: left thread list (title + date), right `MessageThreadView` (thread id, destructive delete button, chat bubbles with timestamps; bot `#ecf1fb` left, user `#1155cc` right).
- "Load More" in lite opens the upsell modal (filter `hyve.messages.load-more`).
- Export Messages action shows a lock in lite (filter `hyve.messages.export-messages`), opens upsell.
- Upsell modal: "Message History is a Premium feature", campaign `messages-feature`.

### 2.3 Knowledge Base pages (`src/backend/parts/data/`)
- **KnowledgeBase.js**: source card grid via `applyFilters('hyve.data', KNOWLEDGE_BASE)`; below it `KnowledgeBaseOptions`.
- **KnowledgeBaseOptions** (`components/KnowledgeBaseOptions.js`): Panel "Options" with RangeControl **Cosine Similarity Threshold** → `similarity_score_threshold` (default 0.4, min -1, max 1, step 0.01, allowReset) + Save.
- **Posts.js** (WordPress): "Add Posts" → `AddData`; PostsTable of included posts (`GET data?status=included`) with "Remove" action.
- **AddData.js**: limit-reached Notice; Post Type SelectControl (from `window.hyve.postTypes`, minus attachments, plus All) + debounced SearchControl; PostsTable with "Add" action; restricted-content confirm modal (private/password-protected); `ModerationReview` on `content_failed_moderation`.
- **Updated.js** (Requires Update): PostsTable `status=pending`, action "Update".
- **FailedModeration.js**: PostsTable `status=moderation`, actions "More Info" (opens ModerationReview) and "Update".
- **Custom.js / URLCrawler.js / SitemapCrawler.js / Documents.js / FAQ.js**: PRO upsells (`UpsellContainer` with sample non-functional tables/controls; campaigns `custom-data-feature`, `website-crawling-feature`, `sitemap-crawling-feature`, `document-import-feature`, `faq-feature`).

### 2.4 Integrations
- **Qdrant.js**: three states — not connected (API Key password field → `qdrant_api_key`, API Endpoint url field → `qdrant_endpoint`, "Connect"); migration in progress (ProgressBar, 10s polling of `GET {api}/qdrant`); connected (green check + "Disconnect" with confirm modal → `POST {api}/qdrant`).
- **ExternalSearch.js** (Hyve Connect / Semantic Search): description + `curl` example against `{rest_url}/knowledge-base/search`; "Access Tokens" panel is an upsell in lite (filter `hyve.tokens-management`).

### 2.5 Settings

**General** (`parts/settings/General.js`) — all saved via `POST {api}/settings`:

| Control | Type | Key | Default |
|---------|------|-----|---------|
| Enable "Add to Hyve" Post Action | ToggleGroup Enable/Disable | `post_row_addon_enabled` | `true` |
| Chat Sound | ToggleGroup Enable/Disable | `sound_enabled` | `true` |
| Welcome Message | TextControl | `welcome_message` | `''` |
| Default Message | TextControl | `default_message` | `''` |
| Suggested Questions | PRO upsell (3 disabled TextControls) via `hyve.suggestedQuestions` filter | `predefined_questions` (pro) | — |

**Appearance** (`parts/settings/Appearance.js`):

| Control | Type | Key | Default |
|---------|------|-----|---------|
| Chat Position | ToggleGroup Left/Right | `chat_position` | `right` |
| Message Timestamp | ToggleGroup Show/Hide | `show_timestamp` | `true` |
| Chat Name / Chat Icons / Colors | PRO upsell via `hyve.appearance.options` | `chat_name`, `chat_icon`, `chat_background` #ffffff, `assistant_background` #ecf1fb, `user_background` #1155cc, `icon_background` #1155cc | — |

Live preview: pushes free options into `window.hyveApp.applyPreviewAppearance` (the real front-end widget renders beside the settings).

**Assistant** (`parts/settings/Assistant.js`) — route currently `disabled:true` in lite:

| Control | Type | Key | Default |
|---------|------|-----|---------|
| Model | RadioControl | `chat_model` | `gpt-4o-mini` (options gpt-4.1 / -mini / -nano, gpt-4o / -mini, gpt-3.5-turbo-0125 — being replaced by PR #174, see `incoming-settings.md`) |
| Temperature | RangeControl 0.1–2 | `temperature` | `1` |
| Top P | RangeControl 0.1–1 | `top_p` | `1` |

**Advanced** (`parts/settings/Advanced.js`):
- OpenAI API Key (password TextControl → `api_key`) with status indicator (connected / error / "Get an API key" link).
- `OthersSection` injected via `hyve.others` filter (`index.js`): Panel "Others" with **Telemetry** ToggleControl → `telemetry_enabled` (stored as `hyve_lite_logger_flag`), auto-saves.

## 3. Shared components

- **PostsTable** (`parts/PostsTable.js`): columns ID / Title (+ indexing-error subtext) / Action; custom `renderStatus` or action buttons; empty state; Load More.
- **PostModal** (`parts/PostModal.js`): Title TextControl + Content TextareaControl (8 rows, max 4000); Delete (edit mode) / Add / Save; escalates to ModerationReview. Exposed globally as `window.hyveComponents.PostModal`.
- **ModerationReview** (`parts/ModerationReview.js`): modal listing flagged OpenAI moderation categories (12 labels in `utils.js`) with tooltips + score bars; "Override Moderation" action.
- **UpsellContainer** (`parts/UpsellContainer.js`): dims children, title + description + "Get Hyve Pro!" button with UTM campaign.
- **Notices** (SnackbarList of `core/notices`), **ErrorSection** (non-dismissible service-error Notices from store `serviceErrors`), **ProgressBar**, **UsageChart/UsageCharts** (chart.js bar charts, 7/14/30/90-day select, messages + sessions).

## 4. Data available to the UI

### 4.1 `window.hyve` (localized in `inc/Main.php`, filter `hyve_options_data`)
`api`, `rest_url`, `postTypes` `{label,value}[]`, `hasAPIKey`, `isApiKeyConnected`, `chunksLimit` (filter `hyve_chunks_limit`, default 500), `isQdrantActive`, `assets.images`, `stats` `{threads, messages, totalChunks}`, `docs`, `qdrant_docs`, `pro` (upgrade URL), `chart` `{legend, data:{messages[],sessions[]}, labels[]}`, `hasPro` (license status filter; App checks `=== 'valid'`). `license` is read by Sidebar but never set in lite. `serviceErrors` arrives on the settings payload.

Pro-supplied globals referenced: `window.hyveApp.applyPreviewAppearance`, `window.hyveTrk`, `window.tiTrk`, `window.hyveComponents`.

### 4.2 `hyve` Redux store (`src/backend/store.js`)
State: `route` (`'home'`), `hasLoaded`, `settings`, `processed`, `hasAPI`, `isQdrantActive`, `totalChunks`, `serviceErrors`. Selectors include `hasReachedLimit` (`chunksLimit <= totalChunks && !isQdrantActive`).

### 4.3 Settings keys and defaults (`inc/Main.php:263-284`)
`api_key` `''`, `qdrant_api_key` `''`, `qdrant_endpoint` `''`, `chat_model` `gpt-4o-mini`, `temperature` `1`, `top_p` `1`, `welcome_message` `''`, `default_message` `''`, `similarity_score_threshold` `0.4`, `post_row_addon_enabled` `true`, `sound_enabled` `true`, `show_timestamp` `true`, `chat_position` `right`, `display_mode` `all`, `display_rules` `[]`, plus derived `telemetry_enabled`.

## 5. REST API surface used by the admin app
Base `${window.hyve.api}` (lite routes: `settings`, `data`, `threads`, `qdrant`, `chat`; permission `manage_options`):

| Call | Used by |
|------|---------|
| `GET/POST {api}/settings` | initial load; every settings save |
| `GET {api}/data?offset&type&search&status` | AddData, Posts (`included`), Updated (`pending`), FailedModeration (`moderation`) |
| `POST {api}/data`, `DELETE {api}/data?id=` | add/remove content |
| `GET/DELETE {api}/threads` | Messages list/delete |
| `GET/POST {api}/qdrant` | Qdrant status poll / deactivate |
| `POST {api}/knowledge[/{ID}]`, `POST {api}/link` | Pro-only endpoints called by pro components |

An `apiFetch` middleware dispatches `setServiceErrors` after settings saves that return `serviceErrors[]`.

## 6. Styling approach
- **Tailwind v4** (theme/preflight/utilities imported individually in `src/backend/style.scss` to beat WP CSS specificity) + **`@wordpress/components`** (Panel, PanelRow, Button, Modal, Text/Select/Range/Radio/Toggle/ToggleGroup/Search controls, Notice, Snackbar, Spinner) — a hybrid.
- Custom SCSS: `#wpcontent{padding:0}`, snackbar positioning, ToggleGroup max-width, chat-message typography, appearance color tiles. Accent `#3858e9` (selected/hover), `#1155cc` chat user bubbles.
- Style handle `hyve-styles` from `build/backend/style-index.css`, depends on `wp-components`.

## 7. Conditional-rendering gates (redesign-relevant)
- `hasAPI` — onboarding overlay, sidebar item disabling.
- `hasReachedLimit` — AddData notice + disabled Add.
- `isQdrantActive` — Qdrant screen states; KB stat format; storage upsell link.
- `window.hyve.hasPro === 'valid'` — hides promo banner; `search` route pro flag.
- `isPro` flags — Pro pills in sidebar and KB source cards.
- Filter hooks Pro swaps in (all default to upsell/empty in lite): `hyve.route`, `hyve.data`, `hyve.suggestedQuestions`, `hyve.appearance.options`, `hyve.appearance.chat-icons`, `hyve.others`, `hyve.tokens-management`, `hyve.messages.load-more`, `hyve.messages.export-messages`, plus `hyve.systemPrompt` incoming in PR #193.
