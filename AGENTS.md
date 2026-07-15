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

### Standalone PHPUnit (fastest — no Docker needed)

The `copilot-setup-steps.yml` pre-installs MySQL and the WordPress test suite, so PHPUnit works immediately in the sandbox:

```bash
# Run the full suite
composer run phpunit

# Run a single file or a class filter
./vendor/bin/phpunit tests/php/unit/tests/test-db.php
./vendor/bin/phpunit --filter 'DB_TableTest|PrivacyNoticeTest'
```

> **Known pre-existing failures (sandbox only):** `Tokenizer` tests download a vocabulary file from the internet, which is blocked. Those 2 failures are unrelated to code changes and can be ignored.

If the WordPress test suite is missing (only happens if copilot-setup-steps didn't run), install it once:

```bash
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1
composer run phpunit
```

### E2E tests (Playwright + wp-env)

> **Sandbox networking caveat:** The sandbox kernel blocks host→container TCP (port 8889 / 8888) even though Docker bridge and iptables look clean. `localhost:8889` will time out from the host. The workaround is to run Playwright inside the test-container's network namespace using `nsenter`.

**Step 1 — Patch `@wordpress/env` Docker templates** (needed once per fresh `node_modules`; the generated Dockerfiles fail to build when the sandbox has no outbound internet):

```bash
sed -i 's/^RUN apk update$/RUN apk update || true/' \
  node_modules/@wordpress/env/lib/runtime/docker/docker-config.js
sed -i 's/^RUN apt-get -qy update$/RUN apt-get -qy update || true/' \
  node_modules/@wordpress/env/lib/runtime/docker/docker-config.js
sed -i 's/^RUN apt-get clean$/RUN apt-get clean || true/' \
  node_modules/@wordpress/env/lib/runtime/docker/docker-config.js
```

**Step 2 — Start wp-env** (uses `.wp-env.override.json` which enables `E2E_TESTING` and runs `bin/e2e-tests.sh` after start):

```bash
npm run wp-env start 2>&1 | tail -20
```

If wp-env was already started from a previous run the Dockerfiles will be pre-built and the patch is no longer necessary; check with `docker images | grep wp-env`.

**Step 3 — After wp-env is running, patch generated Dockerfiles too** (if they were already written to disk before the node_modules patch):

```bash
for f in /home/runner/wp-env/wp-env-hyve-lite-*/\*.Dockerfile; do
  sed -i 's/^RUN apk update$/RUN apk update || true/' "$f"
  sed -i 's/^RUN apt-get -qy update$/RUN apt-get -qy update || true/' "$f"
  sed -i 's/^RUN apt-get clean$/RUN apt-get clean || true/' "$f"
done
```

**Step 4 — Run Playwright via nsenter** (routes Node.js into the test-WordPress container network namespace so `http://localhost` resolves correctly):

```bash
TESTSWPNS=$(docker inspect wp-env-hyve-lite-$(ls /home/runner/wp-env/ | grep hyve | head -1 | sed 's/wp-env-hyve-lite-//')-tests-wordpress-1 -f '{{.State.Pid}}' 2>/dev/null \
  || docker ps --format '{{.Names}}' | grep 'tests-wordpress' | head -1 | xargs -I{} docker inspect {} -f '{{.State.Pid}}')

sudo --preserve-env=HOME,PATH \
  nsenter --net=/proc/$TESTSWPNS/ns/net \
  --setuid $(id -u) --setgid $(id -g) -- \
  bash -c "
    cd /home/runner/work/hyve-lite/hyve-lite
    export WP_BASE_URL=http://localhost
    export HOME=/home/runner
    export PATH=$PATH
    npx playwright test --config=tests/e2e/playwright.config.ts \
      --project=chromium --reporter=list --timeout=30000
  " 2>&1 | tail -60
```

To run a single spec file, append the path before `--project`:

```bash
# ... (nsenter preamble) ...
    npx playwright test --config=tests/e2e/playwright.config.ts \
      tests/e2e/specs/dashboard.spec.js \
      --project=chromium --reporter=list --timeout=30000
```

> **Known pre-existing E2E failures (sandbox only):** Tests in `chat.spec.js` and `privacy-notice.spec.js` that require real OpenAI API calls or a successful `publishPost()` round-trip will time out because no real API key is present. These are ~10 tests and fail identically on an unmodified baseline.

### PHPUnit inside wp-env (alternative)

```bash
npm run env:test:unit
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

- **PHP**: WordPress-VIP-Go + WordPress-Core standards. Text domain: `hyve-lite`. PHPStan level 8 compliance. Always add `// translators:` comments for `sprintf` contexts.
- **JavaScript**: WordPress ESLint preset. Use `@wordpress/element` (not React directly) for components. Use `@wordpress/data` for shared state. Use `@wordpress/i18n` with text domain `hyve-lite`.
- **Indentation**: Tabs (size 4). YAML uses spaces (size 2).

## Version Management

Versions are synced across `package.json`, `composer.json`, `hyve-lite.php`, and `readme.txt` via Grunt (`grunt version`). Semantic Release handles automated versioning using Conventional Commits.

## ThemeIsle SDK

The plugin uses the `codeinwp/themeisle-sdk` package (in `vendor/`). Several modules are wired up in `inc/Main.php` via filters.

### Product key derivation

The SDK converts the plugin slug to a *product key* by replacing hyphens with underscores and lowercasing:
- Plugin slug: `hyve-lite` → Product key: `hyve_lite`

This key is the prefix for all SDK filter names.

### Module: About Us (`About_Us`)

Registers an About page under the plugin's admin menu. To enable it, return metadata from the filter:

```php
add_filter( 'hyve_lite_about_us_metadata', [ $this, 'about_us_metadata' ] );
```

The `location` field must match the **admin menu page slug** (e.g. `'hyve'` for `admin.php?page=hyve`).

The About Us page is registered at the slug `ti-about-{product_key}` → `ti-about-hyve_lite`, so its URL is `admin.php?page=ti-about-hyve_lite`.

Required/optional metadata keys: `location`, `logo`, `page_menu` (optional), `has_upgrade_menu`, `upgrade_link`, `upgrade_text`, `review_link`.

### Helpers

- `tsdk_utmify( $url, $campaign )` — appends UTM parameters to a URL.
- `tsdk_translate_link( $url )` — localises a ThemeIsle docs URL.
- License status: `apply_filters( 'product_hyve_license_status', false )` returns `'valid'` when a Pro licence is active.

### Other active SDK modules (registered in `Main.php`)

| Filter / action | Module |
|---|---|
| `themeisle_sdk_blackfriday_data` | Black Friday promotions |
| `themeisle_sdk_enable_telemetry` | Usage telemetry |
