<?php

use Glpi\Plugin\Hooks;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('GLOBALSEARCH_VERSION', '2.2.0');
define('GLOBALSEARCH_MIN_GLPI', '11.0.0');
define('GLOBALSEARCH_MAX_GLPI', '11.0.99');

/**
 * Inicialización del plugin (GLPI la ejecuta al cargar el plugin)
 */
function plugin_init_globalsearch()
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Marcar el plugin como compatible con CSRF
    $PLUGIN_HOOKS['csrf_compliant']['globalsearch'] = true;

    // Inyectar nuestro JS en la interfaz central
    // Load translated strings for JS before the main script
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['globalsearch'][] = 'front/lang.php';
    // Main JS reads window.GLOBALSEARCH_LANG
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['globalsearch'][] = 'js/globalsearch_header.js';

    // Opcional: CSS propio para el modal
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['globalsearch'][] = 'css/globalsearch.css';

    // Añadir enlace de configuración en el menú de Configuración > Plugins
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['globalsearch'] = 'front/config.form.php';
    }

    // Registrar la clase de configuración
    Plugin::registerClass('PluginGlobalsearchConfig', ['addtabon' => 'Config']);
}

/**
 * Información del plugin (pantalla de plugins)
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
 * Instalación del plugin
 */
function plugin_globalsearch_install()
{
    global $DB;

    // Compilar archivos .po a .mo si msgfmt está disponible
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
    $DB->dropTable('glpi_plugin_globalsearch_configs');
    return true;
}

/**
 * Actualización del plugin
 */
function plugin_globalsearch_upgrade()
{
    // Re-compilar archivos .po a .mo en cada actualización
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
 * Requisitos mínimos
 */
function plugin_globalsearch_check_prerequisites()
{
    // Comprobar versión de GLPI para asegurar compatibilidad con la rama 11.x
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
 * Estado de configuración (simple por ahora)
 */
function plugin_globalsearch_check_config($verbose = false)
{
    return true;
}
