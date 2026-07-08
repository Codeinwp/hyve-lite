# Incoming settings (open PRs, not yet merged)

These issues are in the process of being merged. The new dashboard must include placeholders for every control listed here, in whatever section the new information architecture assigns them. Settings keys are exact (taken from the PR diffs), so the placeholder controls can already read/write the right keys once wired.

Status snapshot: 2026-07-08. Re-check PR state before building on this.

## 1. Privacy policy notice in chat — [hyve#250](https://github.com/Codeinwp/hyve/issues/250) / [hyve-lite PR #192](https://github.com/Codeinwp/hyve-lite/pull/192)

- New setting: `privacy_notice_enabled` (boolean, default `false`). Currently added to **Settings → General** as an Enable/Disable ToggleGroupControl.
- Help text links to WP core Settings → Privacy (`window.hyve.privacySettings`, fallback `options-privacy.php`).
- Conditional warning `Notice` when enabled but no privacy page is set (`window.hyve.hasPrivacyPage` is new localized data).
- Frontend: shows "By chatting, you agree to our Privacy Policy" above the chat input.
- Tier: free (lite).

## 2. Follow-up questions after each answer — [hyve#184](https://github.com/Codeinwp/hyve/issues/184) / [hyve PR #259](https://github.com/Codeinwp/hyve/pull/259), [hyve-lite PR #198](https://github.com/Codeinwp/hyve-lite/pull/198)

- New setting: `follow_up_questions` (boolean toggle, Enable/Disable). Added in pro's `SuggestedQuestions.js`, i.e. renders inside **Settings → General** next to the Suggested Questions fields (via the `hyve.suggestedQuestions` filter slot).
- Lite PR #198 is frontend/plumbing only (reply-data filter + generic suggestion rendering) — no settings UI.
- Tier: **Pro-only**, mirrors `predefined_questions`.

## 3. Source page link in chat responses — [hyve#152](https://github.com/Codeinwp/hyve/issues/152) / [hyve-lite PR #180](https://github.com/Codeinwp/hyve-lite/pull/180)

- New setting: `show_source_link` (boolean, Enable/Disable ToggleGroupControl) in **Settings → General**.
- "When enabled, chat responses include a link to the source content they were based on. The link is only added for publicly accessible sources."
- Tier: free (lite).

## 4. Custom system prompt — [hyve#176](https://github.com/Codeinwp/hyve/issues/176) / [hyve-lite PR #193](https://github.com/Codeinwp/hyve-lite/pull/193), [hyve PR #252](https://github.com/Codeinwp/hyve/pull/252)

- New setting: `system_prompt` (string, `TextareaControl`, 6 rows) in **Settings → General**.
- Lite renders a non-functional upsell (`UpsellContainer`, campaign `system-prompt-settings`) exposed through a new `hyve.systemPrompt` filter; pro replaces it with the working control (`src/parts/SystemPrompt.js`).
- Placeholder text: "e.g. You are the friendly support assistant for Acme Co. …"
- Tier: **Pro** (lite shows upsell).
- The parent issue also floats per-block system prompts (Chat Inline / Chat Bubble block instances) as the longer-term direction — block editor scope, not dashboard, but worth remembering when naming the global one.

## 5. Role-based access to Messages — [hyve#232](https://github.com/Codeinwp/hyve/issues/232) / [hyve-lite PR #194](https://github.com/Codeinwp/hyve-lite/pull/194), [hyve PR #253](https://github.com/Codeinwp/hyve/pull/253)

- **No new settings UI.** Access is granted via WP capabilities (`hyve_read_messages`, `hyve_view_dashboard`, `hyve_manage_messages`), assigned to roles with a role-editor plugin or `WP_Role::add_cap()`. Admins get them implicitly via `user_has_cap`.
- **Major structural impact on the redesign:**
  - Registers real WP admin **submenu pages** under the Hyve menu, one per top-level route, each with its own capability (Dashboard/KB/Integrations/Settings = `manage_options`, Messages = `hyve_read_messages`).
  - The React app boots on a route from `window.hyve.view` (store default: `window.hyve?.view || 'home'`), so deep links come from the WP submenu, not only `?nav=`.
  - New localized flag `window.hyve.canManageMessages` gates the delete-conversation and export actions in the Messages UI.
  - A non-admin support agent may see ONLY the Messages page — the new shell must render sensibly when other nav items are unavailable.
- Pro PR #253 gates the CSV export endpoint on `hyve_manage_messages`.

## 6. Refresh OpenAI model list — [hyve#221](https://github.com/Codeinwp/hyve/issues/221) / [hyve-lite PR #174](https://github.com/Codeinwp/hyve-lite/pull/174)

- **Settings → Assistant**: `chat_model` control changes from `RadioControl` to `SelectControl`.
- New model list (each with a one-line description): `gpt-5.4`, `gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-4.1`, `gpt-4.1-mini`, `gpt-4.1-nano`, `gpt-4o`, `gpt-4o-mini`. GPT-3.5 removed (breaks with structured outputs); a saved-but-unlisted model is appended to the list so it isn't silently lost; a saved gpt-3.5* falls back to `gpt-4o-mini`.
- Tier: free (lite).

## 7. Move Temperature and Top P to Advanced — [hyve#248](https://github.com/Codeinwp/hyve/issues/248)

- No PR yet; the redesign should implement it directly: relocate `temperature` and `top_p` RangeControls out of the primary Assistant view into an Advanced area (sub-tab or collapsible "Advanced" section of Assistant).
