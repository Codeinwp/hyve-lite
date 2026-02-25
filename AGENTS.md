# Agent workflow

## Project Overview

Hyve Lite is an AI-powered chatbot WordPress plugin that transforms website content into interactive conversations using OpenAI APIs and optional Qdrant vector database integration. It includes a React admin dashboard, a vanilla JS frontend chat widget, and a Gutenberg block.

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

# PHP unit tests in wp-env
npm run env:test:unit

# Standalone PHPUnit
composer run phpunit
```

## Architecture

### PHP (Backend)

All PHP classes live in `inc/` under the `ThemeIsle\HyveLite` namespace (PSR-4 autoloaded).

- **Main.php** — Main plugin entry point, registered on `plugins_loaded`. Instantiates all other classes, registers admin menu, manages plugin settings (`hyve_settings` option).
- **API.php** / **BaseAPI.php** — REST API endpoints for chat, settings, and knowledge base operations. BaseAPI is the abstract base class.
- **OpenAI.php** — Client for OpenAI embeddings, chat completions, and content moderation.
- **Qdrant_API.php** — Optional vector database integration for similarity search (alternative to local DB).
- **DB_Table.php** — Custom `{prefix}_hyve` table for storing vector embeddings locally.
- **Threads.php** — Custom post type (`hyve_threads`) for persisting chat sessions.
- **Block.php** — Registers the `hyve/chat` Gutenberg block and `[hyve]` shortcode.
- **Tokenizer.php** / **Cosine_Similarity.php** — Token counting and vector similarity utilities.

Plugin constants are defined in `hyve-lite.php`: `HYVE_LITE_BASEFILE`, `HYVE_LITE_URL`, `HYVE_LITE_PATH`, `HYVE_LITE_VERSION`, `HYVE_PRODUCT_SLUG`.

### JavaScript (Frontend)

Four separate webpack entry points built with `@wordpress/scripts`:

1. **src/backend/** — Admin dashboard React app using `@wordpress/element` and `@wordpress/data` for state management. Components in `components/`, page sections in `parts/`.
2. **src/frontend/** — Client-facing chat widget. Vanilla JS class (`App`) — no React. Manages chat state, threads, audio, and localStorage persistence.
3. **src/block/** — Gutenberg block with two variations: inline and floating. Server-rendered via `render.php`.
4. **src/addons/** — Post list table row actions for quick knowledge base add/remove.

### Data Flow

Posts are indexed into the knowledge base by generating OpenAI embeddings and storing them either in the custom `wp_hyve` DB table (with cosine similarity search) or in Qdrant (vector DB). Chat requests go through the REST API, which retrieves relevant context via similarity search and sends it to OpenAI for response generation.

## Coding Standards

- **PHP**: WordPress-VIP-Go + WordPress-Core standards. Text domain: `hyve-lite`. PHPStan level 10 compliance. Always add `// translators:` comments for `sprintf` contexts.
- **JavaScript**: WordPress ESLint preset. Use `@wordpress/element` (not React directly) for components. Use `@wordpress/data` for shared state. Use `@wordpress/i18n` with text domain `hyve-lite`.
- **Indentation**: Tabs (size 4). YAML uses spaces (size 2).

## Version Management

Versions are synced across `package.json`, `composer.json`, `hyve-lite.php`, and `readme.txt` via Grunt (`grunt version`). Semantic Release handles automated versioning using Conventional Commits.
