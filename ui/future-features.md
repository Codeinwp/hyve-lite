# Future features (planned issues, no PR yet)

These are further out, but the new information architecture should reserve an obvious home for each so we don't need another structural overhaul when they land. Each entry says what settings it will need and where the placeholder should live in the new UI.

Status snapshot: 2026-07-08.

## Hyve Agent — hosted AI provider — [hyve#164](https://github.com/Codeinwp/hyve/issues/164)

Zero-key hosted option on the Themeisle agents platform, covering both chat and embeddings.

Will need:
- A **provider selector** in the AI settings: Hyve Agent / OpenAI (and possibly WP connectors later). This changes the Assistant/API-key area from "OpenAI settings" to "AI provider settings" — design the section as provider-scoped from day one.
- **Quota / usage UI** in the dashboard (free tier + pro tiers with monthly quotas): usage meters for chat messages and embedding tokens. The Dashboard's stat-card row should be able to host a quota card.
- Account binding to the Themeisle account / license key (likely near the license field in pro).

Placeholder home: an "AI Provider" group inside Assistant settings (provider select + per-provider fields), plus a reserved usage card slot on the Dashboard.

## Lead capture — [hyve#168](https://github.com/Codeinwp/hyve/issues/168)

Chatbot asks for name/email; submissions saved to DB (and later exported via webhooks, see #192).

Will need:
- Enable toggle + field configuration (which fields to collect).
- Somewhere to VIEW captured leads — likely a new list next to Messages, or a tab on the Messages page.

Placeholder home: a "Leads" area — either a sub-view of Messages or its own top-level item. Reserve a nav slot.

## Proactive chat messages — [hyve#185](https://github.com/Codeinwp/hyve/issues/185)

Teaser bubble next to the closed launcher, one configurable message + one trigger.

Will need (all Pro):
- Enable toggle.
- Message text field.
- Trigger select (time on page / exit intent / scroll depth) showing only the selected trigger's setting (delay seconds / scroll percentage).

Placeholder home: Appearance/Behavior area of chat settings — it's about how the widget engages, sits naturally near welcome message + suggested questions. A "Behavior" or "Engagement" group.

## Webhooks & contact-support form — [hyve#192](https://github.com/Codeinwp/hyve/issues/192)

Builds on #168. Configurable Contact Support form in chat; submissions stored locally and pushed to a webhook URL.

Will need:
- Contact form enable + field selection (name, email, message, …).
- Webhook URL field (possibly multiple / per-event later).

Placeholder home: webhook config belongs under **Integrations** (alongside Qdrant / Hyve Connect); the form config next to lead capture settings.

## Tool / function calling (Abilities API) — [hyve#195](https://github.com/Codeinwp/hyve/issues/195)

Framework for the bot to call read-only WP abilities (WP ≥ 7.0) mid-conversation.

Will need (Pro):
- Global enable toggle.
- An **abilities allowlist screen**: rows grouped by plugin prefix, each with label, description (what the model reads), read-only/destructive risk badge, group-level toggle + per-ability overrides, everything default-off.
- A designed **empty/unsupported state** (no abilities registered, or WP < 7.0).

Placeholder home: this is a full screen, not a field — plan a "Tools" (or "Abilities") sub-page under Assistant or Integrations. It's the largest future surface; the nav must be able to absorb it without crowding.

## Sizing note

Counting nav-level surfaces the future adds: Leads (view + settings), Tools/Abilities (screen), AI provider + quota, webhooks, proactive messages. The redesign's IA should absorb roughly 3 new field-groups and 2 new screens without adding more top-level items than the design can carry.
