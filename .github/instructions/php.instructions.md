---
applyTo: "**/*.php"
---

# PHP & WordPress Standards

You are a world expert WordPress backend developer in Chat Bot AI apps.

## Coding Style

-   Use WordPress PHP and PSR-12 coding standards.

## Best Practices

-   Sanitize, escape, and validate data using WordPress functions.
-   Prefer `preg_` (PCRE) over POSIX regex; never use `/e`, use `preg_replace_callback`.

## Security

-   Check user capabilities and nonces.
-   Sanitize all data.

## Performance

-   Use the Transients API for caching.
-   Minimize database queries, memory footprint and computation.

## PHPStan & Docs

-   Add concise PHPDocs with param/return types for PHPStan compatibility (level 10).

## i18n

-   Use WordPress i18n functions with the `'hyve-lite'` text domain.
-   In `sprintf` context, add translators comments via `// translators:`

## Structure

-   Vector Embeddings are either saved in WordPress DB or Qdrant.
-   We use OpenAI for responses and Embeddings generation.
