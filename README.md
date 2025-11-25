# Global Search Enhancer for GLPI

[![GLPI](https://img.shields.io/badge/GLPI-10.x-blue.svg)](https://glpi-project.org)
[![License: GPLv3+](https://img.shields.io/badge/License-GPLv3+-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
![Status](https://img.shields.io/badge/Status-Stable-brightgreen.svg)
![Maintained](https://img.shields.io/badge/Maintained-yes-success.svg)

A custom global search engine plugin for [GLPI](https://glpi-project.org/) that replaces the default search functionality with an enhanced search experience.

## Description

This plugin replaces GLPI's default global search with a custom engine that queries the database directly, including closed tickets and resolved projects, with full control over filters and ranking.

## Features

- **Multi-word search**: Searches using "Google-style" query matching, where all words must appear in at least one of the searched fields
- **Comprehensive search**: Searches across multiple GLPI item types in a single query
- **ID search**: Supports direct search by numeric ID for quick access
- **Configurable search types**: Enable or disable specific search categories
- **Entity-aware**: Respects GLPI's entity permissions
- **Modern UI**: Clean modal-based search interface integrated into GLPI's header

## Supported Search Types

| Type | Description |
|------|-------------|
| Tickets | Search in ticket name and content, including closed tickets |
| Projects | Search in project name, comment, and content |
| Documents | Search in document name, filename, and comments |
| Software | Search in software name and comments |
| Users | Search by name, username, first name, phone, or mobile |
| Ticket Tasks | Search in ticket task content |
| Project Tasks | Search in project task name, content, and comments |

## Requirements

- GLPI version: **10.0.0** to **10.0.99**
- PHP: Compatible with GLPI 10.0 requirements

## Installation

1. Download or clone the plugin to your GLPI plugins directory:
   ```bash
   cd /path/to/glpi/plugins
   git clone https://github.com/JuanCarlosAcostaPeraba/globalsearch-glpi-plugin globalsearch
   ```

2. Navigate to **Setup > Plugins** in GLPI

3. Find "Global Search Enhancer" in the plugin list

4. Click **Install** and then **Enable**

## Configuration

1. Go to **Setup > Plugins**

2. Click on **Global Search Enhancer** to access the configuration page

3. Enable or disable the search types you want to include in global searches

4. Click **Save** to apply your changes

## Usage

1. Click the **"Búsqueda global"** button in the GLPI header (labeled "Global Search" in the UI)

2. Enter your search query in the modal that appears:
   - Minimum 3 characters for text search
   - Enter a number for direct ID search

3. Press **Enter** or click the search button

4. Results are displayed grouped by item type (Tickets, Projects, Documents, etc.)

## Search Tips

- **Multi-word queries**: Enter multiple words to find items containing all words (e.g., "network issue" finds items with both "network" AND "issue")
- **ID search**: Enter a numeric ID to directly find an item by its ID
- **Minimum length**: Text searches require at least 3 characters

## File Structure

```
globalsearch/
├── css/
│   └── globalsearch.css       # Modal and UI styles
├── front/
│   ├── config.form.php        # Configuration form handler
│   └── search.php             # Search endpoint
├── inc/
│   ├── config.class.php       # Configuration management
│   └── searchengine.class.php # Core search engine
├── install/
│   └── install.php            # Installation/uninstallation
├── js/
│   └── globalsearch_header.js # Header button and modal
├── templates/
│   └── search_results.html.twig # Results template
├── hook.php                   # Plugin hooks
├── plugin.xml                 # Plugin metadata
└── setup.php                  # Plugin initialization
```

## Author

**Juan Carlos Acosta Perabá**

- GitHub: [@JuanCarlosAcostaPeraba](https://github.com/JuanCarlosAcostaPeraba)

## License

This plugin is licensed under the **GPLv3+** license.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
