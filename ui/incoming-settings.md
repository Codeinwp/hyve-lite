# Incoming settings (open PRs, not yet merged)

These issues are in the process of being merged. The new dashboard must include placeholders for every control listed here, in whatever section the new information architecture assigns them. Settings keys are exact (taken from the PR diffs), so the placeholder controls can already read/write the right keys once wired.

Status snapshot: 2026-07-09. Re-check PR state before building on this. Shipped items get REMOVED from this page (already landed and live in the new UI: privacy notice #250, follow-up questions #184, source links #152, the temperature/top_p move #248; streaming and the reply-data filter needed no admin UI).

## 1. Custom system prompt — [hyve#176](https://github.com/Codeinwp/hyve/issues/176) / [hyve-lite PR #193](https://github.com/Codeinwp/hyve-lite/pull/193), [hyve PR #252](https://github.com/Codeinwp/hyve/pull/252)

- New setting: `system_prompt` (string, `TextareaControl`, 6 rows) in **Settings → General**.
- Lite renders a non-functional upsell (`UpsellContainer`, campaign `system-prompt-settings`) exposed through a new `hyve.systemPrompt` filter; pro replaces it with the working control (`src/parts/SystemPrompt.js`).
- Placeholder text: "e.g. You are the friendly support assistant for Acme Co. …"
- Tier: **Pro** (lite shows upsell). Per the new UI's lite/pro split (plan decision #8), this is a settings-backed pro feature: build it in lite gated by license, no filter needed.
- The parent issue also floats per-block system prompts (Chat Inline / Chat Bubble block instances) as the longer-term direction — block editor scope, not dashboard, but worth remembering when naming the global one.

## 2. Role-based access to Messages — [hyve#232](https://github.com/Codeinwp/hyve/issues/232) / [hyve-lite PR #194](https://github.com/Codeinwp/hyve-lite/pull/194), [hyve PR #253](https://github.com/Codeinwp/hyve/pull/253)

- **No new settings UI.** Access is granted via WP capabilities (`hyve_read_messages`, `hyve_view_dashboard`, `hyve_manage_messages`), assigned to roles with a role-editor plugin or `WP_Role::add_cap()`. Admins get them implicitly via `user_has_cap`.
- **Major structural impact on the redesign:**
  - Registers real WP admin **submenu pages** under the Hyve menu, one per top-level route, each with its own capability (Dashboard/KB/Integrations/Settings = `manage_options`, Messages = `hyve_read_messages`). NOTE: the PR predates the 2026-07-09 IA revision (4 tabs, Settings sidebar), so its submenu list needs adapting when it merges (tracked as W2).
  - The React app boots on a route from `window.hyve.view` (store default: `window.hyve?.view || 'home'`), so deep links come from the WP submenu, not only `?nav=`.
  - New localized flag `window.hyve.canManageMessages` gates the delete-conversation and export actions in the Messages UI.
  - A non-admin support agent may see ONLY the Messages page — the new shell must render sensibly when other nav items are unavailable.
- Pro PR #253 gates the CSV export endpoint on `hyve_manage_messages`.

## 3. Refresh OpenAI model list — [hyve#221](https://github.com/Codeinwp/hyve/issues/221) / [hyve-lite PR #174](https://github.com/Codeinwp/hyve-lite/pull/174)

- **Settings → Assistant** (new UI: Settings > AI > Provider & model): `chat_model` control changes from `RadioControl` to `SelectControl` (the new UI already uses a SelectControl; only the list swap remains).
- New model list (each with a one-line description): `gpt-5.4`, `gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-4.1`, `gpt-4.1-mini`, `gpt-4.1-nano`, `gpt-4o`, `gpt-4o-mini`. GPT-3.5 removed (breaks with structured outputs); a saved-but-unlisted model is appended to the list so it isn't silently lost; a saved gpt-3.5* falls back to `gpt-4o-mini`.
- Tier: free (lite).
