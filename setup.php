<?php

use Glpi\Plugin\Hooks;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('GLOBALSEARCH_VERSION', '2.2.0');
define('GLOBALSEARCH_MIN_GLPI', '11.0.0');
define('GLOBALSEARCH_MAX_GLPI', '11.0.99');

/**
 * Plugin initialization (executed by GLPI when loading the plugin)
 */
function plugin_init_globalsearch()
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Mark the plugin as CSRF compliant
    $PLUGIN_HOOKS['csrf_compliant']['globalsearch'] = true;

    // Inject our JS into the central interface
    // Load translated strings for JS before the main script
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['globalsearch'][] = 'front/lang.php';
    // Main JS reads window.GLOBALSEARCH_LANG
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['globalsearch'][] = 'js/globalsearch_header.js';

    // Optional: Custom CSS for the modal
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['globalsearch'][] = 'css/globalsearch.css';

    // Add configuration link in the Configuration > Plugins menu
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['globalsearch'] = 'front/config.form.php';
    }

    // Register the configuration class
    Plugin::registerClass('PluginGlobalsearchConfig', ['addtabon' => 'Config']);
}

/**
 * Plugin information (plugins screen)
 */
function plugin_version_globalsearch()
{
    return [
        'name' => 'Global Search Enhancer',
        'version' => GLOBALSEARCH_VERSION,
        'author' => 'Juan Carlos Acosta Perabá and contributors',
        'license' => 'GPLv3+',
        'homepage' => 'https://github.com/JuanCarlosAcostaPeraba/glpi-globalsearch-plugin',
        'id' => 'globalsearch',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.0.99',
            ],
        ],
    ];
}

/**
 * Plugin installation
 */
function plugin_globalsearch_install()
{
    global $DB;

    // Compile .po to .mo files if msgfmt is available
    $locales_dir = dirname(__FILE__) . '/locales';
    if (is_dir($locales_dir)) {
        $po_files = glob($locales_dir . '/*.po');
        if ($po_files) {
            foreach ($po_files as $po_file) {
                $mo_file = str_replace('.po', '.mo', $po_file);
                $cmd = "msgfmt -f -o " . escapeshellarg($mo_file) . " " . escapeshellarg($po_file);
                @exec($cmd);
            }
        }
    }

    // Create configuration table
    if (!$DB->tableExists('glpi_plugin_globalsearch_configs')) {
        $query = "CREATE TABLE `glpi_plugin_globalsearch_configs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_type` VARCHAR(50) NOT NULL COMMENT 'Search type: Ticket, Project, Document, etc.',
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = disabled',
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `search_type` (`search_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($query);

        // Insert default values (all active)
        $default_types = [
            'Ticket',
            'Change',
            'Project',
            'Document',
            'Software',
            'User',
            'TicketTask',
            'ProjectTask'
        ];

        foreach ($default_types as $type) {
            $DB->insert('glpi_plugin_globalsearch_configs', [
                'search_type' => $type,
                'is_enabled'  => 1,
                'date_mod'    => date('Y-m-d H:i:s')
            ]);
        }
    }

    // Migration: add missing search types for existing installations
    $expected_types = [
        'Ticket',
        'Change',
        'Project',
        'Document',
        'Software',
        'User',
        'TicketTask',
        'ProjectTask'
    ];

    foreach ($expected_types as $type) {
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_globalsearch_configs',
            'WHERE' => ['search_type' => $type],
            'LIMIT' => 1
        ]);

        if (count($iterator) === 0) {
            $DB->insert('glpi_plugin_globalsearch_configs', [
                'search_type' => $type,
                'is_enabled'  => 1,
                'date_mod'    => date('Y-m-d H:i:s')
            ]);
        }
    }

    return true;
}

/**
 * Plugin uninstallation
 */
function plugin_globalsearch_uninstall()
{
    global $DB;
    $DB->dropTable('glpi_plugin_globalsearch_configs');
    return true;
}

/**
 * Plugin update
 */
function plugin_globalsearch_upgrade()
{
    // Re-compile .po to .mo files on each update
    $locales_dir = dirname(__FILE__) . '/locales';
    if (is_dir($locales_dir)) {
        $po_files = glob($locales_dir . '/*.po');
        if ($po_files) {
            foreach ($po_files as $po_file) {
                $mo_file = str_replace('.po', '.mo', $po_file);
                $cmd = "msgfmt -f -o " . escapeshellarg($mo_file) . " " . escapeshellarg($po_file);
                @exec($cmd);
            }
        }
    }
    return true;
}


/**
 * Minimum prerequisites
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
 * Configuration status (simple for now)
 */
function plugin_globalsearch_check_config($verbose = false)
{
    return true;
}
