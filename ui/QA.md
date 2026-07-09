# QA checklist for the new dashboard (`?new=true`)

Everything testable in what has been built so far. Work through it top to bottom; each line is a check. Where a card or screen has multiple states, the setup for each state is spelled out.

How to force the common states:

- **No API key**: clear the key in Settings > Provider & model (or the old UI) and save.
- **Invalid API key**: save a made-up key like `sk-wrong`.
- **Empty knowledge base**: remove all sources in the old UI's Knowledge Base.
- **Qdrant on/off**: connect or disconnect Qdrant in the old UI's Integrations tab.
- **Free vs Pro**: deactivate/activate the Hyve Pro plugin.
- **Display modes**: change "Where should Hyve appear?" on the old Dashboard (all, include, exclude, manual).

## 1. Bootstrapping and the old UI

- [ ] `admin.php?page=hyve` (no `new=true`) still loads the old dashboard, completely unaffected.
- [ ] `admin.php?page=hyve&new=true` loads the new shell.
- [ ] No JavaScript errors in the console on load, on any screen.

## 2. Routing and URLs

- [ ] Clicking each tab updates the URL (`?nav=...`) without a page reload.
- [ ] Settings sidebar links update `&sub=...` (chat-behavior, chat-appearance, ai-provider, ai-advanced, qdrant, api-access, general).
- [ ] Opening a deep URL directly (for example `&nav=settings&sub=ai-advanced`) lands on that exact panel.
- [ ] An unknown `nav` or `sub` value falls back gracefully (defaults to Dashboard / the screen's first panel) instead of a blank page.
- [ ] Browser Back and Forward walk through previously visited views correctly.
- [ ] Navigating via tab, sidebar link, or any in-screen shortcut scrolls the page to the top.
- [ ] Back/Forward restores the scroll position (browser behavior, should not be forced to top).

## 3. Sticky chrome

- [ ] Header bar sticks below the WP admin bar when scrolling, never hides behind it.
- [ ] Tab row sticks directly below the header bar.
- [ ] Check both on a normal desktop window and a narrow window where the admin bar becomes taller (mobile width).

## 4. Header bar

- [ ] Plugin icon renders (the real Hyve icon, not a placeholder).
- [ ] Version pill matches the installed plugin version.
- [ ] API status pill: green "API connected" with a key, amber "API not connected" without.
- [ ] The pill flips to green immediately after saving a valid key in Settings > Provider & model, without a reload.
- [ ] Docs link opens the documentation.
- [ ] Free: "Upgrade" CTA visible, links to the Pro page with the `header-upgrade` UTM campaign.
- [ ] Pro: "Upgrade" CTA hidden.

## 5. Gating (no API key)

- [ ] Without a key: Knowledge Base and Messages tabs are muted and unclickable; Dashboard and Settings stay usable.
- [ ] In the Settings sidebar without a key: only Provider & model and Advanced are clickable; Behavior, Appearance, Qdrant, API Access and General are muted.
- [ ] Opening Settings without a key lands on Provider & model (the default Behavior panel is gated, so it redirects).
- [ ] Opening a gated screen's URL directly (for example `&nav=messages`) redirects to Dashboard, and a gated panel URL (for example `&nav=settings&sub=chat-behavior`) redirects to Provider & model; neither creates a Back loop.
- [ ] After saving a valid key, all tabs and sidebar items unlock without a reload.

## 6. Dashboard: setup checklist states

- [ ] **No API key**: page shows the heading and the checklist ONLY. No notice, stats, chart, conversations, or Get started.
- [ ] Step 1 shows "Add API key" button leading to Settings > Provider & model.
- [ ] Steps 2 and 3 are locked with disabled "Waiting for step 1" buttons (including the optional Qdrant step).
- [ ] Progress chip reads "0 of 2 steps done" (Qdrant does not count toward the 2).
- [ ] **Key saved, knowledge base empty**: checklist still visible (step 1 done, step 2 unlocked), AND the full dashboard renders below it.
- [ ] **Key saved, knowledge base has content**: checklist gone entirely.
- [ ] Qdrant step shows as done when Qdrant is connected.

## 7. Dashboard: visibility notice

- [ ] Hidden while the knowledge base is empty (chat is not live then).
- [ ] `display_mode = all`: "Chat is live on all pages" wording.
- [ ] `display_mode = include`: "Chat is live on selected pages" wording.
- [ ] `display_mode = exclude`: "Chat is live on most pages" wording.
- [ ] `display_mode = manual`: "Chat appears only where you place it" wording.
- [ ] "Manage visibility" navigates to Settings > Behavior (the visibility card).

## 8. Dashboard: stat cards

- [ ] Sessions and Messages numbers match the old dashboard's Overview numbers.
- [ ] Large numbers get thousands separators.
- [ ] **Knowledge base, Qdrant OFF**: value reads "X / 500 chunks", a meter bar shows the fill, foot reads "N% of the free limit used."
- [ ] **Knowledge base, Qdrant OFF, more than 400 chunks**: "Need more storage?" link appears in the foot and navigates to Settings > Qdrant.
- [ ] **Knowledge base, Qdrant ON**: plain number with a "chunks" suffix, no meter, foot reads "Stored in your Qdrant cluster."
- [ ] Hyve Connect card: dashed border, PLANNED chip, faint "N/A" value.

## 9. Dashboard: Usage card

- [ ] With data: one chart, Messages in blue with a light fill, Sessions in solid amber; legend shows both.
- [ ] Range selector offers Last 7 / 14 / 30 / 90 days and re-slices the chart on change.
- [ ] Default range is 30 days.
- [ ] With no usage data at all: the selector is hidden and the card shows "Usage data will appear here once visitors start chatting."
- [ ] Chart resizes sensibly when the window resizes.

## 10. Dashboard: Recent conversations card

- [ ] While loading: spinner.
- [ ] No conversations: "Conversations will appear here once visitors start chatting with Hyve."
- [ ] **Free, more than 3 conversations exist**: exactly 3 rows plus the blue "Read every conversation" note pinned to the card bottom, with a primary "Unlock with Pro" button (UTM campaign `messages-feature`, opens in a new tab).
- [ ] **Free, 3 or fewer conversations total**: rows only, NO upsell.
- [ ] **Pro**: up to 5 rows, never an upsell.
- [ ] Each row: conversation title, message count ("1 message" vs "N messages" pluralization), relative time on the right ("5 min. ago", "18 hr. ago", "2 days ago").
- [ ] A very long conversation title truncates with an ellipsis instead of wrapping or pushing the time off.
- [ ] "View all" navigates to Messages (placeholder for now).

## 11. Dashboard: Get started

- [ ] Clear gap above the "Get started" heading and below the cards (nothing cramped).
- [ ] "Grow the knowledge base" navigates to Knowledge Base.
- [ ] "Personalize the chat" navigates to Settings > Behavior.
- [ ] "Need help?" opens the docs in a new tab.
- [ ] Cards get a blue border on hover.

## 12. Settings > AI: Provider & model

- [ ] The sidebar highlights "Provider & model" (blue bar + tinted background) when open.
- [ ] **No key saved**: no status chip, "Get an API key" link below the field.
- [ ] **Valid key saved**: green "Connected" chip.
- [ ] **Key saved but invalid**: amber "Not connected" chip.
- [ ] Typing in the field switches the chip to muted "Unsaved".
- [ ] The field keeps the same width no matter which chip (or no chip) is shown.
- [ ] Save with a valid key: success snackbar, header pill flips, tabs unlock, checklist step 1 done.
- [ ] Save with a bad key: warning snackbar and "Not connected" chip.
- [ ] Model select lists the models, defaults to GPT-4o mini, and the choice survives a save and reload.

## 13. Settings > AI: Advanced

- [ ] Temperature, Top P, and Similarity threshold sliders show the saved values.
- [ ] "Reset to defaults" sets 1 / 1 / 0.4 (and needs a Save to persist).
- [ ] Save persists all three (verify by reloading).
- [ ] Controls disable while a save is in flight.

## 14. Snackbars

- [ ] Success and error notices appear bottom-left, stack, and can be dismissed.
- [ ] A failed save (for example, cut network in devtools) shows an error snackbar rather than failing silently.

## 15. Responsive

- [ ] Below roughly 980px: stat grid drops to 2 columns, the Usage + Conversations grid stacks, Get started drops to 2 columns, field rows stack label-over-control.
- [ ] Below roughly 640px: stats and Get started go single column.
- [ ] Tab row scrolls horizontally instead of wrapping or overflowing the page.

## 16. Messages screen

- [ ] No subnav row (Conversations is the only panel, single-link subnavs are hidden).
- [ ] While loading: spinner in the card.
- [ ] No conversations: "Conversations will appear here once visitors start chatting with Hyve."
- [ ] Table rows: title, snippet of the last message (no HTML tags visible), message count, short date, View button.
- [ ] A very long title or snippet truncates with an ellipsis.
- [ ] **Free, more conversations exist**: 3 rows + the blue "Read every conversation" note; no Load more.
- [ ] **Free, 3 or fewer total**: rows only, no upsell.
- [ ] **Pro**: Previous/Next pagination in the card footer with "Page X of Y"; Previous disabled on the first page, Next disabled on the last, both disabled while a page loads.
- [ ] Pager math holds up: Y matches the real conversation count divided by 10, and the last page shows the remainder.
- [ ] Pagination footer is absent entirely when everything fits on one page.
- [ ] Export CSV: disabled with a lock icon on free (clicking does nothing); on pro it downloads the CSV.
- [ ] View opens the thread; the URL gains `&item=<id>` and is shareable (open it in a new tab: same thread loads).
- [ ] A deep link to a deleted/unknown thread shows the "Conversation not found" card, and "All conversations" goes back to the list.
- [ ] Thread view: meta line (date, message count, thread id), visitor bubbles right in blue, Hyve bubbles left in light blue, each with a time.
- [ ] A long conversation scrolls inside the messages area (capped height), not the whole page.
- [ ] Links inside bot replies render as links.
- [ ] Delete conversation opens a confirmation dialog; Cancel (or Escape, or clicking outside) closes it with nothing deleted.
- [ ] Confirming the delete: success snackbar, returns to the list, the row is gone (reload to confirm it stayed gone).
- [ ] Browser Back from a thread returns to the list; Back again leaves Messages.
- [ ] Dashboard "View all" and a conversation row's flow both land correctly scrolled to the top.

## 17. Settings sidebar and Chat > Behavior

- [ ] Settings shows the sidebar left, panels right; groups labeled Chat, AI, Integrations, Plugin (uppercase); the Integrations group holds Qdrant and API Access as separate items.
- [ ] The active item has a blue left bar and tinted background; the sidebar sticks below the tabs while the panel scrolls.
- [ ] Qdrant panel with nothing configured: muted "Not connected" chip, API key + endpoint fields, Connect button, "Learn more about Qdrant" link.
- [ ] Connecting with valid Qdrant credentials: success snackbar, card flips to "Migrating" (amber chip, progress meter, "X of Y chunks moved"), progress advances roughly every 10 seconds without a reload, then the card flips to "Connected".
- [ ] Connected state: green chip, cluster host shown (no protocol), Disconnect button.
- [ ] Disconnect opens the confirm modal; Cancel/Escape close it harmlessly; confirming disconnects (snackbar), returns the card to "Not connected", and the Dashboard knowledge base stat regains the "/ 500 chunks" meter after reload.
- [ ] Leaving the panel mid-migration does not spam errors (polling stops on unmount).
- [ ] API Access panel: description and the curl request preview render on both tiers; the preview URL uses this site's real REST URL and scrolls horizontally instead of overflowing the card.
- [ ] API Access panel, free: PRO chip in the card head, blue upsell below the preview (UTM `api-search`). Pro: no chip, no upsell, a hint that the token manager arrives here (P2).
- [ ] General panel: the "Add to Hyve" row action and Telemetry toggles save immediately on change (success snackbar, no Save button) and persist across reload.
- [ ] Turning the row action off actually removes the "Add to Hyve" link from the Posts list table (and on brings it back).
- [ ] Below roughly 980px the sidebar collapses above the content and wraps horizontally.
- [ ] Every screen shows its heading and description above the content (Settings: "Configure the chat, the AI engine, integrations and the plugin.").
- [ ] Visibility card: the saved `display_mode` is preselected; the selected radio card is highlighted in blue.
- [ ] Picking "Only on selected content" or "Everywhere except selected content" reveals the Content URLs editor; "Show on all pages" and "Don't show automatically" hide it.
- [ ] URL rules: add a rule, edit path and operator, remove a rule; Save persists them (reload to confirm).
- [ ] After changing the mode and saving, the Dashboard visibility notice wording matches the new mode.
- [ ] Dashboard "Manage visibility" lands on this panel, scrolled to the top.
- [ ] Conversation card: welcome message, default message, and the sound toggle persist across save + reload; the toggle label flips between Enabled and Disabled.
- [ ] **Suggestions, free**: PRO chip in the card head, three disabled inputs with example placeholders, blue upsell with "Unlock with Pro" (UTM `suggested-questions-settings`), no Save button.
- [ ] **Suggestions, pro**: no chip, no upsell, three editable fields that persist, Save button present.
- [ ] **Follow-up questions, pro**: the toggle in the Suggestions card persists across save + reload, and turning it off stops follow-up suggestions appearing after chat answers on the frontend.
- [ ] **Follow-up questions, free**: the toggle shows as on but disabled.
- [ ] Trust & sources card: the Source links toggle persists across save + reload.
- [ ] Privacy notice toggle persists; its description links to WP's Settings > Privacy page in a new tab.
- [ ] **Privacy notice ON, no privacy page set**: a compact yellow warning appears under the toggle with a link to choose a page; it disappears when the toggle is off or once a privacy page exists.
- [ ] With the notice enabled and a privacy page set, the chat widget shows the "By chatting, you agree to our Privacy Policy" line on the frontend.
- [ ] Known behavior: each card's Save posts the whole settings object, so edits pending in another card get saved too.

## 18. Settings > Chat: Appearance

- [ ] A notice points at the floating chat widget as the live preview (only when the test widget is present on the page; it needs content in the knowledge base to appear).
- [ ] Position: switching Left/Right moves the floating widget instantly, before saving.
- [ ] Timestamps: Show/Hide updates the widget's message timestamps instantly.
- [ ] Save persists position and timestamps (reload to confirm).
- [ ] **Free**: Assistant name, Custom icon image, and Colors rows show PRO chips, controls are visible but disabled (greyed), and color tiles show the defaults without opening a picker.
- [ ] **Free**: the card footer shows Save changes on the left and the upsell on the right ("A custom name, icon and colors are part of Hyve Pro." + a secondary "Unlock with Pro" button, UTM `appearance-settings`); no separate upsell band above the footer.
- [ ] **Pro**: typing an assistant name updates the widget header live; empty name falls back to the default.
- [ ] **Pro**: the launcher icon set row appears (icons come from the pro bundle), picking one swaps the widget button icon live; Default resets it.
- [ ] **Pro**: selecting a media-library image previews it in the form and on the widget; Remove reverts to the default icon.
- [ ] **Pro**: each color tile opens a picker, the widget recolors as you drag, light/dark text contrast flips correctly on dark backgrounds, and Reset to defaults restores all four.
- [ ] **Pro**: saved appearance matches the real site frontend after reload (admin preview and frontend agree).

## 19. Copy and general polish

- [ ] Every button, link, select, toggle, slider, and focus ring renders in the WP admin blue (#2271b1); no Gutenberg indigo (#3858e9) anywhere, including inside modals and their buttons.
- [ ] Controls are compact (about 30px tall): check the Manage visibility button, the Usage range select, table View buttons, pagination, and the key field, model select, and save buttons under Settings.
- [ ] No em or en dashes anywhere in the visible copy.
- [ ] Nothing references a screen location it should not (error strings are location-neutral).
- [ ] Free tier shows no Pro chips in nav or subnav (upsells live inside screens only).
- [ ] Table row actions (View, Add, Remove) are secondary buttons; a primary button appears at most once per card or modal (Save, Connect, Unlock with Pro, confirm actions).
- [ ] With the pro plugin active, everything above still holds (no duplicate screens, no lite-only assumptions breaking).

## 20. Knowledge Base (slice 1)

- [ ] The Knowledge Base tab shows the subnav (All sources / Needs attention / FAQ).
- [ ] FAQ, free: PRO chip in the card head, the FAQ description, a faded non-interactive preview table (6 dummy questions in greyed text so they read as sample data, Asked counts, disabled Delete/Answer buttons per row; nothing clickable or tabbable), and the blue "FAQ is a Premium feature" upsell (UTM `faq-feature`, opens in a new tab). Pro: description plus a hint that the working panel arrives here (P2); no chip, no preview, no upsell.
- [ ] In both Needs attention and the FAQ preview, grouped row actions sit flush right like every other table.
- [ ] **Add a source grid**: five cards (WordPress, Custom Data, Website URL, Sitemap, Documents) with icon, title, description; free shows PRO chips on the four pro cards; pro shows no chips.
- [ ] Clicking a source card drills in (`&sub=source-...` in the URL) and browser Back returns to All sources.
- [ ] **Locked source drill (free)**: back link, card with the feature description, PRO chip, blue upsell block with the old title ("... is a Premium feature") and an "Unlock with Pro" button using the source's own UTM campaign (`custom-data-feature`, `website-crawling-feature`, `sitemap-crawling-feature`, `document-import-feature`).
- [ ] **Indexed content card**: chunk-count chip in the header updates after fetches; table lists added WordPress content with Title, Source (post type), Chunks, Status chip, Remove; with more than 20 items a Previous/"Page X of Y"/Next pager appears in the card footer (same style as Messages), hidden when one page is enough.
- [ ] The per-row Chunks numbers add up to the header chip's total (across all pages), and a freshly added long post shows more than 1 chunk.
- [ ] Remove opens a confirm modal naming the item ("Hyve will stop using ... in its answers"); Cancel/Escape close it harmlessly; confirming removes with the snackbar.
- [ ] Removing the last item on a later page steps back one page; removing elsewhere refills the current page.
- [ ] Remove deletes the row (snackbar "Post has been removed.") and the item becomes addable again in the WordPress drill-in.
- [ ] A row with a processing error shows the "Indexing failed: ..." detail under the title and a warn chip.
- [ ] **WordPress drill-in**: content-type select (All + public post types, no attachments) and search filter the table after a short pause (debounced, no request per keystroke); changing a filter resets to page 1.
- [ ] The type select and the search field are the same height and vertically aligned; a muted "N results" count sits at the right of the toolbar and matches the pager's total.
- [ ] The drill-in table paginates like Indexed content (footer pager past 20 results); page and filters play together without stale results.
- [ ] **Bulk add**: row checkboxes and a select-all-on-page header checkbox; selecting shows the blue bar with "N items selected", Clear, and "Add N to Knowledge Base"; the selection survives changing page or filters.
- [ ] Bulk add on public posts: the button counts up ("Adding X of N"), rows flip to Added chips as they finish, and one summary snackbar reports the result (no per-item snackbar spam).
- [ ] Bulk selection including private/password posts opens one combined restricted-content modal with Cancel / "Skip them" / "Add anyway"; Skip queues only the public ones.
- [ ] Items that fail during bulk add stay selected (warning snackbar says so) and can be retried.
- [ ] While a bulk run is in flight: checkboxes, per-row Add, and Clear are disabled; at the chunk limit the bulk button is disabled like the row buttons.
- [ ] Add on a public post: button shows busy, then the row flips to a green "Added" chip and a success snackbar appears.
- [ ] Add on a private or password-protected post opens the restricted-content confirm modal (medium width); Cancel closes harmlessly, "Add anyway" imports it.
- [ ] A post that fails moderation shows an error snackbar (the review modal ships with slice 2).
- [ ] **Chunk limit** (free, 500 chunks reached, Qdrant off): a warning notice shows in the drill-in and every Add button is disabled; with Qdrant connected the limit never triggers.
- [ ] Adding or removing content updates the chunk-count chip on the next fetch and keeps the Dashboard KB stat consistent after reload.

## 21. Knowledge Base (slice 2): Needs attention

How to force the states: edit an already-indexed post to get "Edited since indexing"; add a post with policy-violating content (or temporarily lower the moderation threshold) to get "Failed moderation".

- [ ] The subnav shows an amber count badge on "Needs attention" equal to pending + failed-moderation items; no badge when the count is zero.
- [ ] The badge updates without a reload after actions that change it (update, override, remove, a failed add).
- [ ] The panel merges both lists into one table: Title, Source, Issue chip (amber "Edited since indexing" / red "Failed moderation").
- [ ] Empty state reads "Nothing needs your attention right now."
- [ ] **Update** on an edited item: busy state, success snackbar, the row leaves the list and the badge drops.
- [ ] **Update all** appears only when edited items exist; it counts up ("Updating X of Y"), row actions are disabled during the run, and one summary snackbar reports the result.
- [ ] If an update fails moderation (item content now violates policies), the review modal opens and the item shows under Failed moderation after the refresh.
- [ ] **Review** on a flagged item opens the moderation modal: intro copy, one row per flagged category with an info tooltip, a score bar, and a percentage.
- [ ] **Override Moderation** in the modal: busy state, success snackbar, item leaves the list (its content gets indexed), badge drops.
- [ ] **Retry** on a flagged item re-runs moderation: clean content passes and leaves the list; still-flagged content reopens the modal with fresh scores.
- [ ] In the WordPress drill-in, adding a post that fails moderation now opens the same review modal (not just a snackbar); overriding from there flips the row to Added.
- [ ] Escape and Cancel close the modal harmlessly from every entry point.

## 22. Service errors (F10) and tracking (F12)

- [ ] With a service error present (for example an OpenAI key with no credits after a chat attempt), a red-left-bordered notice appears above the page heading on EVERY screen: provider tag, message, code, timestamp, and the "test the chat after solving" instruction.
- [ ] Saving settings refreshes the notice list without a reload (the settings response carries fresh service errors).
- [ ] The notice disappears after a successful chat interaction (matches the old dashboard behavior).
- [ ] Tracking (needs telemetry enabled, watch the tiTrk network calls): switching tabs/panels queues `dashboard / route / <screen>/<sub>` events; programmatic redirects (gating) do not.
- [ ] Saving a non-empty API key queues `openai / api-key / added`.
- [ ] Themeisle campaign banners (when a campaign is live, or with the SDK banner test mode) inject at the top of the content area, above the page heading; with a valid pro license the slot stays hidden.

## 23. Pro extension contract (P1) and Access Tokens (needs the pro plugin active)

- [ ] With pro active, Settings > Integrations > API Access shows the description + curl preview card AND the Access Tokens card below it (no "on its way" hint, no PRO chip, no upsell).
- [ ] With pro active but an outdated pro build, the "The access token manager is on its way here." fallback card shows instead (nothing breaks).
- [ ] Generate New Token: busy state, new masked row appears (first 14 characters + asterisks), success snackbar.
- [ ] Show reveals the full token (button flips to Hide); Copy puts the full token on the clipboard with a snackbar even while masked.
- [ ] Delete opens the confirm modal (medium, two warning paragraphs); Cancel/Escape close harmlessly; confirming deletes the row with a snackbar.
- [ ] Tokens work end to end: a generated token authorizes `POST {rest}/knowledge-base/search` per the curl preview; a deleted token stops working.
- [ ] Deactivating pro flips the panel back to the free view (chip, upsell) without errors.

## 24. License (needs the pro plugin active)

- [ ] With pro active, the Settings sidebar shows "License" under the Plugin group, right after General; without pro the entry does not exist.
- [ ] The License panel works without an OpenAI API key (it is not key-gated).
- [ ] **Not active**: muted "Not active" chip, editable key field, "Get license from Purchase History" link, Activate button (disabled while the field is empty).
- [ ] Activating a valid key: busy state, then the page reloads and the panel shows the Active state (license gates localized flags, so the reload is expected).
- [ ] Activating an invalid key: error snackbar with the SDK message, no reload, the field stays editable.
- [ ] **Active**: green "Active" chip, masked key (asterisks + last 5 characters), disabled field, "Valid - Expires <date>" hint sitting tight under the field (no extra gap), Deactivate button (secondary, destructive).
- [ ] Deactivate opens a confirm modal (medium): Cancel/Escape close harmlessly, confirming deactivates and reloads into the Not active state.
- [ ] **Expired** (license past its renewal date): red "Expired" chip, the old renewal warning text, and a "Renew License" link pointing at the store with the key attached.
- [ ] **Header plan pill** (pro only): a tinted pill next to the version reads "Pro" (green, hover shows the expiration date), "Pro · Inactive" (amber), or "Pro · Expired" (red); the right side keeps only the API chip; free lite shows no pill.
- [ ] Clicking the plan pill navigates to Settings > License.
- [ ] With pro active but an outdated pro build, the sidebar simply has no License entry and the plan pill still renders from the localized license data (nothing breaks).
- [ ] With an inactive or expired license, the SDK's red "add your license code" admin notice does NOT show anywhere on the Hyve dashboard (old or new UI); it still shows on other wp-admin pages.
