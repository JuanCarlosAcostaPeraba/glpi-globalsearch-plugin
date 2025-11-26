<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('GLOBALSEARCH_VERSION', '1.5.1');

/**
 * Inicialización del plugin (GLPI la ejecuta al cargar el plugin)
 */
function plugin_init_globalsearch()
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Marcar el plugin como compatible con CSRF
    $PLUGIN_HOOKS['csrf_compliant']['globalsearch'] = true;

    // Inyectar nuestro JS en la interfaz central
    $PLUGIN_HOOKS['add_javascript']['globalsearch'][] = 'js/globalsearch_header.js';

    // Opcional: CSS propio para el modal
    $PLUGIN_HOOKS['add_css']['globalsearch'][] = 'css/globalsearch.css';

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
                'min' => '10.0.0',
                'max' => '10.0.99',
            ],
        ],
    ];
}

/**
 * Requisitos mínimos
 */
function plugin_globalsearch_check_prerequisites()
{
    // Aquí podrías comprobar versión PHP/extensiones si hace falta
    return true;
}

/**
 * Estado de configuración (simple por ahora)
 */
function plugin_globalsearch_check_config($verbose = false)
{
    return true;
}
