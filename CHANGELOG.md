# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

