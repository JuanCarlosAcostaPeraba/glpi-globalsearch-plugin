# Global Search Enhancer

[![Version](https://img.shields.io/badge/Version-2.2.0-green.svg)](https://github.com/JuanCarlosAcostaPeraba/glpi-globalsearch-plugin/releases)
[![GLPI Marketplace](https://img.shields.io/badge/GLPI_Marketplace-Available-orange.svg)](https://plugins.glpi-project.org/#/plugin/globalsearch)
[![GLPI](https://img.shields.io/badge/GLPI-11.0.x-blue.svg)](https://glpi-project.org)
[![License: GPLv3+](https://img.shields.io/badge/License-GPLv3+-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Maintained](https://img.shields.io/badge/Maintained-yes-success.svg)]()

A lightweight GLPI plugin that enhances the **Global Search** by replacing the default search with a custom engine that queries the database directly, including closed tickets and resolved projects.

## ✨ Features

* 🔹 Multi-word "Google-style" search functionality
* 🔹 Search in closed tickets and resolved projects
* 🔹 Configurable search types via admin panel
* 🔹 Search across 8 different item types:
  * Tickets (with status and assigned technician)
  * Changes (with status and assigned technician)
  * Projects (including resolved)
  * Documents
  * Software
  * Users
  * Ticket Tasks
  * Project Tasks
* 🔹 Smart ID search for numeric queries (also searches in content)
* 🔹 Enhanced numeric search - finds numbers within text content
* 🔹 Entity-aware with permission checking
* 🔹 Results ranked by modification date
* 🔹 Assigned technician column in ticket results

## 📦 Requirements

* GLPI **11.0.x**
* PHP **8.1+**

## 🚀 Installation

### Option 1: From GLPI Marketplace (Recommended)

1. Go to **GLPI → Configuration → Plugins → Marketplace**
2. Search for **Global Search Enhancer**
3. Click **Install**, then **Enable**

### Option 2: Manual Installation

1. Download the latest release from GitHub Releases
2. Extract and copy the folder `globalsearch` into:  
```  
glpi/plugins/  
```
3. Go to **GLPI → Configuration → Plugins**
4. Find **Global Search Enhancer**
5. Click **Install**, then **Enable**

## ⚙️ Configuration

Access the plugin settings via **GLPI → Configuration → Plugins → Global Search Enhancer**.

Available options:

* **Enable/disable search** for each type (Tickets, Changes, Projects, Documents, Software, Users, Ticket Tasks, Project Tasks)

## 🔍 How it works

### Search

* **Multi-word search**: "Google-style" logic - all words must appear in results.
* **Literal phrases**: Use double quotes (e.g., `"router cisco"`) to search for exact text matches.
* **Smart ID search**:
    * Numeric queries find both IDs and content (e.g., `5457` finds ID 5457 and items containing "5457").
    * Use the `#` prefix (e.g., `#123`) to force a search **only by ID**, which is faster and more precise.
* **Closed/Resolved**: Searches include closed tickets and resolved projects.
* **Sorting**: Results are ranked by modification date.

## 🏗️ Plugin Structure

```
globalsearch/
├── setup.php                  # Plugin registration
├── hook.php                   # Installation hooks
├── plugin.xml                 # Plugin metadata
├── inc/
│   ├── config.class.php       # Configuration management
│   └── searchengine.class.php # Search engine logic
├── front/
│   ├── search.php             # Search results page
│   └── config.form.php        # Configuration form
├── public/
│   ├── js/
│   │   ├── globalsearch_header.js # Frontend override
│   │   └── globalsearch_enhanced.js # Enhanced search functionality
│   └── css/
│       └── globalsearch.css   # Styling
├── templates/
│   └── search_results.html.twig # Results template
├── locales/
│   ├── en_GB.po               # English (UK) source
│   ├── en_US.po               # English (US) source
│   ├── es_ES.po               # Spanish source
│   └── es_ES.mo               # Compiled Spanish (generated)
├── assets/
│   └── logo.png               # Plugin logo
└── README.md
```

## 🌐 Translations

* English (en_GB) - Default
* Spanish (es_ES)

## 📝 License

**GPLv3+**

Fully compatible with GLPI plugin licensing requirements.

## 👥 Authors

Developed by **Juan Carlos Acosta Perabá** and **contributors** for **HUC – Hospital Universitario de Canarias**.
