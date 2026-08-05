# Agent workflow

## Project Overview

Hyve Lite is an AI-powered chatbot WordPress plugin that transforms website content into interactive conversations. It runs in one of two modes: self-hosted (the user's own OpenAI key, with optional Qdrant vector storage) or Hyve Connect (the hosted platform owns chunking, embeddings, and storage). It includes a React admin dashboard, a vanilla JS frontend chat widget, and a Gutenberg block.

## Build & Development Commands

```bash
# Install dependencies
npm ci
composer install

# Build all bundles (backend, frontend, block, addons)
npm run build

# Dev mode with hot reload
npm start

# Build individual bundles
npm run build:backend    # Admin dashboard
npm run build:frontend   # Chat widget
npm run build:block      # Gutenberg block
npm run build:addons     # Post row actions
```

## Linting & Formatting

```bash
# PHP
composer run lint         # PHPCS (WordPress-VIP-Go standard)
composer run format       # PHPCBF auto-fix
composer run phpstan      # Static analysis (2GB memory limit)

# JavaScript
npm run lint:js           # ESLint (WordPress preset)
npm run format            # wp-scripts format ./src
npm run lint:css          # Stylelint
```

## Testing

```bash
# E2E tests (requires wp-env)
npm run wp-env start
npm run test:playwright
npm run test:playwright:debug   # Debug mode
npm run test:playwright:ui      # Interactive UI mode

# PHP unit tests in wp-env. Warning: this reinstalls WordPress in the e2e
# site's database, so re-seed / restart wp-env before running Playwright after it.
npm run env:test:unit

# Standalone PHPUnit — requires MySQL + the WordPress test suite. Install it once:
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1
# On macOS the suite installs under $TMPDIR; export WP_TESTS_DIR so the bootstrap
# finds it (it otherwise defaults to /tmp/wordpress-tests-lib):
#   export WP_TESTS_DIR="${TMPDIR}wordpress-tests-lib"
composer run phpunit
```

## Architecture

### PHP (Backend)

All PHP classes live in `inc/` under the `ThemeIsle\HyveLite` namespace (PSR-4 autoloaded).

- **Main.php** — Main plugin entry point, registered on `plugins_loaded`. Instantiates all other classes, registers admin menu, manages plugin settings (`hyve_settings` option), flags edited posts for re-indexing.
- **API.php** / **BaseAPI.php** — REST API endpoints for chat, settings, and knowledge base operations. BaseAPI is the abstract base class.
- **OpenAI.php** — Client for OpenAI embeddings, chat completions, and content moderation.
- **Hyve_Connect.php** — Client for the hosted Hyve Connect platform (auth, KB upsert/delete, hosted chat).
- **Qdrant_API.php** — Optional vector database integration for similarity search (alternative to local DB).
- **DB_Table.php** — Custom `{prefix}_hyve` table for local vector storage, plus the shared ingest pipeline (`ingest_document()`), the update cron, and the Connect sync/reconcile machinery.
- **Threads.php** — Custom post type (`hyve_threads`) for persisting chat sessions.
- **Block.php** — Registers the `hyve/chat` Gutenberg block and `[hyve]` shortcode.
- **Page_Context.php** — Grounds the chat in the page the visitor is viewing.
- **Stream.php** — SSE streaming helpers for chat responses.
- **Encryption.php** — At-rest encryption for stored secrets (API keys).
- **Tokenizer.php** / **Cosine_Similarity.php** — Token counting and vector similarity utilities.

Plugin constants are defined in `hyve-lite.php`: `HYVE_LITE_BASEFILE`, `HYVE_LITE_URL`, `HYVE_LITE_PATH`, `HYVE_LITE_VERSION`, `HYVE_PRODUCT_SLUG`.

### JavaScript (Frontend)

Four separate webpack entry points built with `@wordpress/scripts`:

1. **src/backend/** — Admin dashboard React app using `@wordpress/element` and `@wordpress/data` for state management. Tab screens in `screens/` (Dashboard, KnowledgeBase, Messages, Leads, Skills, Connect, and the Settings screens: Settings, SettingsGeneral, AI, ChatAppearance, ChatBehavior, Integrations), shared layout kit in `components/`, hooks in `data/`, URL routing in `router.js` (`?page=hyve&nav=<screen>&sub=<panel>&item=<id>`), shared store in `store.js`. Pro extends it through the `hyve.routes` / `hyve.slot` / `hyve.setup-steps` filters and the layout kit bridged at `window.hyveComponents.ui`.
2. **src/frontend/** — Client-facing chat widget. Vanilla JS class (`App`) — no React. Manages chat state, threads, audio, and localStorage persistence.
3. **src/block/** — Gutenberg block with two variations: inline and floating. Server-rendered via `render.php`.
4. **src/addons/** — Post list table row actions for quick knowledge base add/remove.

### Data Flow

Every knowledge base source (posts, plus pro's custom text, links, sitemaps, and documents) funnels through `DB_Table::ingest_document()`. Validation that must apply to all sources belongs there; it already rejects content with no extractable text (`empty_content`) before anything is stored or queued.

- **Self-hosted (API mode)**: content is chunked (`Tokenizer`), moderated, embedded via OpenAI, and stored in the custom `wp_hyve` table (cosine similarity search) or Qdrant. Chat retrieves context via similarity search and sends it to OpenAI for response generation.
- **Hyve Connect**: the whole document ships to the platform, which chunks, moderates, embeds, and stores it; no local chunk rows or vectors. A cron (`connect_run_sync`) drains bulk-queued sources, and reconcile compares sha256 content fingerprints to decide what to re-push.

Invariants worth knowing before touching this code:

- Connect paths read raw `post_content`, never `apply_filters( 'the_content', ... )`. This is deliberate: reconcile depends on deterministic content hashes (`_hyve_connect_synced_hash`), and `the_content` output is dynamic (shortcodes, nonces) and unreliable in cron. Every Connect path (synchronous add, cron sync, `connect_source_hash()`) must build documents identically, or the same post hashes differently and re-uploads forever, burning churn quota.
- Indexing failures are recorded on the source post as `_hyve_processing_error` via `record_processing_error()`, with a suffix saying whether it will retry or the admin must act. The KB listing renders it under the title.
- `_hyve_needs_update` marks an edited post for re-indexing: the `hyve_update_posts` cron re-queues it every 60s and it counts toward the Needs Attention badge. Terminal failures must clear the flag (see `update_posts()`) or the cron loops forever; editing the post clears the error and re-flags it.

## Coding Standards

- **PHP**: WordPress-VIP-Go + WordPress-Core standards. Text domain: `hyve-lite`. PHPStan level 8 compliance. Always add `// translators:` comments for `sprintf` contexts.
- **JavaScript**: WordPress ESLint preset. Use `@wordpress/element` (not React directly) for components. Use `@wordpress/data` for shared state. Use `@wordpress/i18n` with text domain `hyve-lite`.
- **Indentation**: Tabs (size 4). YAML uses spaces (size 2).

## Version Management

Versions are synced across `package.json`, `composer.json`, `hyve-lite.php`, and `readme.txt` via Grunt (`grunt version`). Semantic Release handles automated versioning using Conventional Commits.
