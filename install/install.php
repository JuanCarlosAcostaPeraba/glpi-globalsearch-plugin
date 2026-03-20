<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Plugin installation
 */
function plugin_globalsearch_install()
{
    global $DB;

    $migration = new Migration(GLOBALSEARCH_VERSION);

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

    // Migration: add missing search types
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

    // Remove configuration table
    $tables = [
        'glpi_plugin_globalsearch_configs'
    ];

    foreach ($tables as $table) {
        $DB->queryOrDie("DROP TABLE IF EXISTS `$table`", $DB->error());
    }

    return true;
}
