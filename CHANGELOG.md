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
