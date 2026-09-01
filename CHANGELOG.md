##### [Version 2.0.2](https://github.com/Codeinwp/hyve-lite/compare/v2.0.1...v2.0.2) (2026-09-01)

- Improved chat behavior for proactive messages and unrelated requests.
- Added message details that show how chat replies were created.
- Fixed chatbot answers from knowledge base pages and tables.

##### [Version 2.0.1](https://github.com/Codeinwp/hyve-lite/compare/v2.0.0...v2.0.1) (2026-08-12)

- Expanded default window size and fixed the menu label not visible in RTL mode
- Fixed uncaught TypeError with Qdrant connection

#### [Version 2.0.0](https://github.com/Codeinwp/hyve-lite/compare/v1.3.3...v2.0.0) (2026-08-05)

New features
- Added hosted Hyve Agent support for chat and embeddings without a separate OpenAI API key.
- Added document uploads for the knowledge base, including PDF, Word, and CSV files. [PRO]
- Added custom system prompt controls for chat persona, tone, and scope. [PRO]
- Added proactive chat messages based on configurable triggers. [PRO]
- Added contextual follow-up questions after successful answers. [PRO]
- Added in-chat lead capture and contact forms with webhook support. [PRO]
- Added role-based access for viewing and exporting chat conversations.
- Added support for private and password-protected posts in the knowledge base.
- Added page-aware context when knowledge base content is missing. [PRO]
- Added numbered source links below chat answers.
- Added visibility rules for controlling where the chat appears.
- Added privacy controls, policy guidance, and an optional chat privacy notice.

Enhancement
- Refreshed chat design and appearance controls, including styling, position, timestamps, live preview, sound, and clearer suggestions.
- Redesigned the dashboard with usage stats, setup checklist, knowledge base filters, and easier navigation.
- Updated available OpenAI models and removed outdated GPT-3.5 options.
- Improved API key status and chatbot error reporting.
- Improved WooCommerce answers with product links, prices, stock, and attributes through the new Skills feature. [PRO]
- Added streaming chat responses with fallback support.
- Encrypted sensitive settings such as OpenAI API keys.

Bug fixes
- Improved chat button accessibility for screen readers.
- Fixed the Chat Bubble block placeholder text in the editor.
- Fixed encoded characters showing in knowledge base page titles.
- Fixed inline chat loading without the welcome message or suggestions.
- Fixed Q&A entries not matching when the question was only in the title.
- Fixed follow-up questions by making retrieval conversation-aware.
- Hardened conversation CSV exports against spreadsheet formula injection.

##### [Version 1.3.3](https://github.com/Codeinwp/hyve-lite/compare/v1.3.2...v1.3.3) (2026-05-07)

- Update dependenceis

##### [Version 1.3.2](https://github.com/Codeinwp/hyve-lite/compare/v1.3.1...v1.3.2) (2025-11-11)

- Updated dependencies.
- Migrated to OpenAI Response API format.

##### [Version 1.3.1](https://github.com/Codeinwp/hyve-lite/compare/v1.3.0...v1.3.1) (2025-09-22)

- Fixed the text alignment in the grid components
- Updated dependencies

#### [Version 1.3.0](https://github.com/Codeinwp/hyve-lite/compare/v1.2.4...v1.3.0) (2025-06-23)

### New Features

- External API support for knowledge base search **[PRO]**
- Customizable similarity threshold
- Option to export messages **[PRO]**
- Option to delete threads
- Show connectivity errors in the dashboard
- NPS survey added
- New chatbot icons **[PRO]**

### Improvements

- Compatibility with PHP 7.4 or higher (Previously 8.1+)
- Add support for more OpenAI models
- Dashboard charts for usage statistics
- Optimize memory consumption for chat endpoint
- Reduced payload size on data endpoint
- Improved time to calculate the cosine similarity score
- Consistent chat style options across themes
- Quick actions for adding/removing posts to knowledge base
- Better UX while waiting for bots response
- Hide chat and show dashboard notice when knowledge base is empty
- Better explanation for Enable Chat options (global vs. specific pages)
- Lowered UI gaps in the messages page
- License key notice redirects to activation page

### Bug Fixes

- Fixed handling of OpenAI status
- Fixed warning on early translation function calls
- Fixed missing embeddings in FAQ

##### [Version 1.2.4](https://github.com/Codeinwp/hyve-lite/compare/v1.2.3...v1.2.4) (2025-05-27)

- Updated dependencies

##### [Version 1.2.3](https://github.com/Codeinwp/hyve-lite/compare/v1.2.2...v1.2.3) (2024-12-24)

- Improvement to sanitization.

##### [Version 1.2.2](https://github.com/Codeinwp/hyve-lite/compare/v1.2.1...v1.2.2) (2024-11-19)

### Bug Fixes
- **Hyve Icon in Widgets**: Resolved an issue where the Hyve icon was not appearing in Widgets.
- **Suggested Questions**: Fixed an issue preventing Suggested Questions from appearing as expected.

##### [Version 1.2.1](https://github.com/Codeinwp/hyve-lite/compare/v1.2.0...v1.2.1) (2024-11-07)

### New Features
- **Background Knowledge Base Updates**: Your Knowledge Base now updates in the background, automatically processing updated posts with the help of a cron job.
- **URL Crawl Update Option**: Added an option to update existing content from URL Crawls, ensuring your Knowledge Base stays current.

### Bug Fixes
- **Chat Timing Display**: Chatbot timing now displays accurately in a 24-hour format.
- **Internationalization Compatibility**: Frontend strings are now fully i18n-compatible, making Hyve easier to localize.

#### [Version 1.2.0](https://github.com/Codeinwp/hyve-lite/compare/v1.1.0...v1.2.0) (2024-10-14)

### New Features
- **Choose Between GPT-3.5 and GPT-4o-mini**: Users can now select between GPT-3.5 and GPT-4o-mini for their interactions. GPT-4o-mini is much cheaper and provides better results.
- **Support for Rich Text in Chat Responses**: Chat responses now support rich text, allowing for enhanced results like images, links, and code snippets.
- **Website URL and Sitemap Crawling for Knowledge Base**: You can now add external content to your Knowledge Base, including data from your website and HelpScout docs, using Website URL and Sitemap Crawling.
- **Qdrant Integration for Improved Performance**: Users can now integrate with Qdrant to improve both performance and Knowledge Base limits. Qdrants free plan offers liberal limits suitable for most websites.

### Improvements
- **PHP-tiktoken for Better Performance**: We’ve replaced js-tiktoken with php-tiktoken to significantly improve performance speed.
- **Minimum PHP Version Bumped to 8.1**: We’ve updated the minimum required PHP version to 8.1 to ensure better performance and security.
- Initial version.

#### [Version 1.1.0](https://github.com/Codeinwp/hyve-lite/compare/v1.0.0...v1.1.0) (2024-09-09)

- Initial version.

####   Version 1.0.0 (2024-07-15)

- Initial release of free version of Hyve
