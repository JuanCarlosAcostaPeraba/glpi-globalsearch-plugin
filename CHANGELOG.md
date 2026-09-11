# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.0] - 2026-09-11

### Added
- **Wildcard Search Support**: Direct support for wildcards (`*` and `?`) in search queries (e.g., `imp*laser`, `serv*`, `*switch*`, `pc-?01`), providing seamless compatibility for technicians accustomed to legacy systems (Track-It!).
- **Regular Expressions**: Native support for regular expressions with delimiters (`/pattern/flags`), `regex:` prefix, and regex operators (`^`, `$`, `[...]`, `\d`).
- **Safe Regex Translation**: Automatic compilation of glob wildcards to safe MySQL/MariaDB `REGEXP` patterns with PHP `@preg_match()` pre-validation to prevent SQL errors (e.g. dangling `*` quantifier).
- **DOM-Aware Highlighting**: Updated frontend search term highlighting in `globalsearch_enhanced.js` to match wildcard and regex patterns accurately across table results.
- **Search Modal Query Persistence**: Retains the active search query when reopening the search modal and autofocusses the input.

### Fixed
- **Literal Quotes Matching**: Fixed exact phrase search within double, single, and typographical/curly quotes (`“...”`, `«...»`, `‘...’`).
- **Unbalanced Quotes Handling**: Gracefully handles dangling, trailing, or unclosed quotation marks without breaking multi-term search.
- **HTML & Non-Breaking Spaces**: Search criteria and DOM highlighting now seamlessly handle non-breaking spaces (`&nbsp;` / `\u00A0`) common in GLPI rich text fields.
- **Search Term Protection**: Minimum query length check in `hasSearchTerms()` now ignores wildcards and regex delimiters (`*?\/`) to prevent accidental full-table dumps.
- **Localized UI Strings**: Updated placeholder and contextual help text across all supported languages (Spanish, English UK/US, Dutch) explaining ID, exact phrase, wildcard, and regex search syntax.

## [2.3.0] - 2026-09-10

### Added
- Dutch (nl_NL) translation, with compiled `.mo` files for all locales.

### Fixed
- Results-page labels (pagination, filters, columns) are now translatable.
- Date-range filter recognises Dutch column headers.
- Private ticket tasks and document notes are only shown to users allowed to see them.
- Error details are no longer disclosed to the browser.
- Plugin no longer includes legacy `inc/includes.php`.

### Security
- Search highlighting no longer uses `innerHTML` (XSS).

## [2.2.0] - 2026-03-23

### Fixed
- **Translations (i18n)**: Modernized translation system by switching to gettext (`.po`/`.mo`) files with legacy `.php` fallback for maximum compatibility.
- **Translations (i18n)**: Correctly registered plugin ID in PHP and Twig to ensure localizations are picked up.
- **Translations (i18n)**: Added automatic locale compilation hook during plugin installation/update.
- **Architecture**: Resolved `Cannot redeclare function` error by consolidating installation logic from `install/install.php` into `setup.php`.
- **Security**: Fixed `AccessDeniedHttpException` (CSRF) issues during uninstallation by simplifying the hook structure.
- **Database**: Fixed `Executing direct queries is not allowed!` error in GLPI 10+ by using modern abstraction methods like `$DB->dropTable()`.

### Changed
- **Metadata**: Generalized contributor attribution to "Juan Carlos Acosta Perabá and contributors" in `setup.php`, `plugin.xml`, and `README.md`.

## [2.1.0] - 2024-12-XX

### Security
- Enhanced entity permission handling and validation
- Improved security checks for entity access control
- Strengthened entity restrictions to prevent unauthorized access
- Additional security measures for GLPI entity system compliance

### Fixed
- Fixed entity permission edge cases in search results
- Improved entity recursive permission handling
- Enhanced security validation for entity-aware searches

## [2.0.0] - 2024-12-XX

### Added
- Full compatibility with GLPI 11.0.x
- Enhanced search functionality with multi-word support
- Configurable search filters via admin panel
- Search across 7 item types: Tickets, Projects, Documents, Software, Users, Ticket Tasks, Project Tasks
- Smart ID search for numeric queries
- Results ranked by modification date
- Entity-aware search with permission checking
- Support for searching in closed/resolved items

### Changed
- Migrated from GLPI 10.x to GLPI 11.x native support
- Updated codebase to use GLPI 11 API and structure
- Improved search algorithm for better performance
- Updated plugin structure to follow GLPI 11 conventions (public/js, public/css)

### Fixed
- Fixed entity permission handling in search results
- Improved numeric search to find IDs and content matches

### Notes
- ⚠️ **IMPORTANT**: This version requires GLPI 11.0.x
- For GLPI 10.x compatibility, please use version 1.5.1
- PHP 8.1+ is required

[2.1.0]: https://github.com/JuanCarlosAcostaPeraba/glpi-globalsearch-plugin/releases/tag/v2.1.0
[2.0.0]: https://github.com/JuanCarlosAcostaPeraba/glpi-globalsearch-plugin/releases/tag/v2.0.0

