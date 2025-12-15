<?php

use Glpi\Plugin\Hooks;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('GLOBALSEARCH_VERSION', '2.0.0');
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
        'author' => 'Juan Carlos Acosta Perabá',
        'license' => 'GPLv3+',
        'homepage' => 'https://github.com/JuanCarlosAcostaPeraba',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.0.99',
            ],
        ],
    ];
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
