<?php

use Glpi\Plugin\Hooks;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('GLOBALSEARCH_VERSION', '2.1.0');
define('GLOBALSEARCH_MIN_GLPI', '11.0.0');
define('GLOBALSEARCH_MAX_GLPI', '11.0.99');

/**
 * Plugin initialization (GLPI executes this when loading the plugin)
 */
function plugin_init_globalsearch()
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Mark the plugin as CSRF-compliant
    $PLUGIN_HOOKS['csrf_compliant']['globalsearch'] = true;

    // Inject our JS into the central interface
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['globalsearch'][] = 'js/globalsearch_header.js';

    // Optional: custom CSS for the modal
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['globalsearch'][] = 'css/globalsearch.css';

    // Add configuration link in the Configuration > Plugins menu
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['globalsearch'] = 'front/config.form.php';
    }

    // Register the configuration class
    Plugin::registerClass('PluginGlobalsearchConfig', ['addtabon' => 'Config']);
}

/**
 * Plugin information (plugin screen)
 */
function plugin_version_globalsearch()
{
    return [
        'name' => 'Global Search Enhancer',
        'version' => GLOBALSEARCH_VERSION,
        'author' => 'Juan Carlos Acosta Perabá',
        'license' => 'GPLv3+',
        'homepage' => 'https://github.com/JuanCarlosAcostaPeraba/glpi-globalsearch-plugin',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.0.99',
            ],
        ],
    ];
}

/**
 * Minimum requirements check
 */
function plugin_globalsearch_check_prerequisites()
{
    // Check GLPI version to ensure compatibility with the 11.x branch
    if (version_compare(GLPI_VERSION, GLOBALSEARCH_MIN_GLPI, 'lt')) {
        echo sprintf(
            'This plugin requires GLPI >= %s. Current version: %s. For GLPI 10.x, please use plugin version 1.5.1',
            GLOBALSEARCH_MIN_GLPI,
            GLPI_VERSION
        );
        return false;
    }

    if (version_compare(GLPI_VERSION, GLOBALSEARCH_MAX_GLPI, 'gt')) {
        echo sprintf(
            'This plugin is not compatible with GLPI > %s. Current version: %s',
            GLOBALSEARCH_MAX_GLPI,
            GLPI_VERSION
        );
        return false;
    }

    return true;
}

/**
 * Configuration status check (simple for now)
 */
function plugin_globalsearch_check_config($verbose = false)
{
    return true;
}
