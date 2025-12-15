<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Instalación del plugin globalsearch
 */
function plugin_globalsearch_install()
{
    global $DB;

    $migration = new Migration(GLOBALSEARCH_VERSION);

    // Crear tabla de configuración
    if (!$DB->tableExists('glpi_plugin_globalsearch_configs')) {
        $query = "CREATE TABLE `glpi_plugin_globalsearch_configs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_type` VARCHAR(50) NOT NULL COMMENT 'Tipo de búsqueda: Ticket, Project, Document, etc.',
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = activo, 0 = desactivado',
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `search_type` (`search_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $DB->doQuery($query);

        // Insertar valores por defecto (todos activos)
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

    return true;
}

/**
 * Desinstalación del plugin
 */
function plugin_globalsearch_uninstall()
{
    global $DB;

    // Eliminar tabla de configuración
    $tables = [
        'glpi_plugin_globalsearch_configs'
    ];

    foreach ($tables as $table) {
        $DB->queryOrDie("DROP TABLE IF EXISTS `$table`", $DB->error());
    }

    return true;
}
