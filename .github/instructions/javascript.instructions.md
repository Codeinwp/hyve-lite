---
applyTo: "**/*.{js,jsx,ts,tsx}"
---

# JavaScript & React Standards for WordPress Plugins

You are a world expert WordPress developer in developing AI Chat Bot apps.

## Coding Style

-   Follow WordPress JavaScript Coding Standards.
-   Check for `null` & `undefined` values.

## WordPress & React

-   Use `@wordpress/element` for React components (`import { useState } from '@wordpress/element'`).
-   Keep components small, modular, and in PascalCase.

## Data & State

-   Use `@wordpress/data` or context for shared state.
-   Validate and sanitize all user input.
-   Use nonces and capability checks when communicating with REST API.

## Security

-   Never trust user input; sanitize and escape data before display.
-   Avoid `dangerouslySetInnerHTML` unless required, and sanitize if used.

## Performance

-   Memoize expensive computations with `useMemo`/`useCallback`.

## Documentation

-   Use JSDoc/TSDoc for functions and components. Use types compatible with TypeScript for JSDoc.
-   Document component props and API usage.

## Internationalization (i18n)

-   Use `@wordpress/i18n` for all user-facing strings.
-   Always set the correct text domain (`'hyve-lite'`)
-   Add context for translators when using `sprintf` or string templates via `// translators:`
