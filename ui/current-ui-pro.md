# Current UI map — hyve (Pro plugin)

How Pro extends the lite dashboard, as of 2026-07-08. Pro does **not** render its own admin app: lite mounts the React app at `#hyve-options`, and Pro's bundle is enqueued after it (`hyve-lite-scripts` is a declared dependency, `hyve/inc/Main.php:78`). Pro mutates lite's route tree and swaps upsell placeholders for working components via `@wordpress/hooks` filters.

Cross-bundle sharing: lite exposes `window.hyveComponents` (`PostsTable`, `PostModal`) and the `'hyve'` data store; Pro components consume both.

## 1. Filters Pro registers (`hyve/src/index.js`, inside `domReady`)

| Filter | What it does |
|--------|--------------|
| `hyve.route` | `data.children.faq` → Pro `FAQ`, `isPro:false`; `settings.children.advanced` → Pro `Advanced` (adds License panel) |
| `hyve.appearance.options` | Replaces Appearance upsell with working name/icon/color controls |
| `hyve.data` | Swaps KB source components (`custom`, `url`, `sitemap`, `documents`) + clears their `isPro` |
| `hyve.suggestedQuestions` | Replaces Suggested Questions upsell with working 3-field control |
| `hyve.messages.load-more` | Real paginating Load More button |
| `hyve.messages.export-messages` | Working CSV export link (`window.hyve.exportMessagesURL`) |
| `hyve.tokens-management` | Real Access Tokens management panel |

`hyve.others` and `hyve.appearance.chat-icons` are declared AND consumed by lite itself; Pro's replacement components re-apply them (so Pro's Advanced still renders lite's Telemetry section, Pro's Appearance still uses lite's icon set).

`integrations.children.search` (Hyve Connect) is not touched by any filter — it unlocks purely via localized `window.hyve.hasPro`.

## 2. Pro components (`hyve/src/parts/`)

- **Appearance.js** (fragment appended inside lite's Appearance panel; shares its Save):
  - Chat Name → `chat_name` (TextControl)
  - Chat Icons → `chat_icon` `{type:'svg'|''|'media', value}` (ToggleGroupControl with icon options from `hyve.appearance.chat-icons`)
  - Custom Icon Image → MediaUpload (image), Select/Replace/Remove, 64x64 preview
  - Colors → 4 ColorTile swatches (Dropdown + ColorPicker, no alpha) → `colors.chat_background` #ffffff, `colors.assistant_background` #ecf1fb, `colors.user_background` #1155cc, `colors.icon_background` #1155cc; "Reset to defaults"
  - Live preview via `window.hyveApp.applyPreviewAppearance({chatName, colors, colorsDark, chatIcon})` (mirrors PHP `is_dark_color()`)
- **Advanced.js** (replaces lite's Advanced page):
  - License panel: masked key TextControl, states valid ("Valid – Expires %s") / active_expired (renew link) / invalid (purchase-history link); Activate/Deactivate → `POST {api}/license`; reloads with `&nav=advanced`; seeded from `window.hyve.license`
  - API Key panel (relabeled version of lite's): password TextControl → `api_key`, status badges, Save
  - `hyve.others` slot → lite's Telemetry section
- **SuggestedQuestions.js**: 3 TextControls → `predefined_questions[]` array; inside lite's General panel, shares its Save. (PR #259 adds a `follow_up_questions` toggle here — see `incoming-settings.md`.)
- **Messages.js**: working Load More button.
- **ExportMessages.js**: tertiary button linking to nonce'd `hyve/v1/export-messages` CSV.
- **Custom.js** (KB > Custom Data): search + Add Data (PostModal) + PostsTable with Edit; `GET/POST {api}/knowledge`, limit Notice.
- **FAQ.js** (KB > FAQ): custom table Title/Count/Action; Delete (`POST {api}/faq/{hash}` w/ method override) + Add (PostModal prefilled with question); `GET {api}/faq`.
- **Documents.js** (KB > Documents): MediaUpload multi-select (PDF/DOCX/plain/CSV), import queue with per-file statuses (Queued/Processing/Needs review/Failed/Done) via PostsTable `renderStatus`; sensitive-data review Modal ("Review before importing", Cancel/Import anyway, re-POST with `confirm_sensitive:true`); `GET/POST {api}/documents`, `DELETE {api}/documents/{id}`.
- **URLCrawler.js** (KB > Website URL): URL TextControl + Crawl URL; PostsTable with Delete/Update actions; `GET/POST {api}/links`, `POST/DELETE {api}/links/{id}`.
- **SitemapCrawler.js** (KB > Sitemap): custom table Sitemap URL/Status/Action, Add Sitemap → `NewSitemap` modal, Details → `EditSitemap` modal; `GET {api}/sitemap`.
- **NewSitemap.js**: modal, URL field → Fetch (`GET {api}/sitemap/fetch?url=`) → checkbox link list (Select/Deselect All) → Proceed (`POST {api}/sitemap`).
- **EditSitemap.js**: modal, disabled URL field, indexed-links list when completed, Delete/Close.
- **AccessTokensManagement.js**: token table (masked value, Show/Hide, Copy, Delete-with-confirm), Generate New Token; `GET/POST/DELETE {api}/access-tokens`.
- **SystemPrompt.js** — incoming in PR #252 (see `incoming-settings.md`): TextareaControl → `system_prompt`, fills lite's new `hyve.systemPrompt` slot.

## 3. Data Pro adds

Via `hyve_options_data` filter (`hyve/inc/Main.php:146-163`):
- `window.hyve.license` = `{key, valid, expiration}` (hides lite's sidebar upgrade panel; drives License panel)
- `window.hyve.exportMessagesURL` (nonce'd CSV endpoint)

Via `hyve_default_settings`: adds `predefined_questions`, `chat_name`, `colors{}`, `chat_icon{}` defaults.
Via `hyve_frontend_data`: adds `predefinedQuestions`, `chatName`, `colors` (dark map), `chatIcon` to the widget.
Also: `hyve_threads_per_page`, `upload_mimes` (md/csv).

## 4. Pro REST endpoints (namespace `hyve/v1`, `manage_options` unless noted)

`license` (POST), `knowledge` (GET/POST) + `knowledge/{id}` (POST/DELETE), `links` (GET/POST) + `links/{id}` (POST/DELETE), `documents` (GET/POST) + `documents/{id}` (DELETE), `sitemap` (GET/POST/DELETE) + `sitemap/fetch` (GET), `faq` (GET) + `faq/{id}` (DELETE), `export-messages` (GET), `access-tokens` (GET/POST/DELETE), `knowledge-base/search` (POST, **Bearer-token** auth for external callers).

## 5. Everything lite gates that Pro unlocks

- FAQ tab (`isPro` cleared via route filter)
- KB sources: Custom Data, Website URL, Sitemap, Documents (`hyve.data`)
- Appearance branding (name/icon/colors), Suggested Questions, Messages pagination + export, Access Tokens (respective filters)
- Hyve Connect route (`hasPro` value), sidebar upgrade panel (`license` presence), top promo banner (`hasPro === 'valid'`)

## 6. Inconsistencies worth fixing in the redesign

- The `hasReachedLimit` Notice and Pro-badge logic are copy-pasted across many components.
- `PostsTable`/`PostModal` are shared via `window.hyveComponents`, but FAQ and Sitemap tables are bespoke inline markup, so column styling diverges.
- Lite's Sidebar reads `window.hyve.license` which lite never sets (works, but only by accident of the Pro contract).
