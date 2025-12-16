<?php

/**
 * Custom Global Search Endpoint
 * plugins/globalsearch/front/search.php
 */


use Glpi\Application\View\TemplateRenderer;

global $CFG_GLPI;

include('../../../inc/includes.php');

Session::checkCentralAccess();
Html::header(__('Search'), $_SERVER['PHP_SELF']);

// Respetar la opción de permitir búsqueda global
if (!$CFG_GLPI['allow_search_global']) {
    Html::displayRightError();
    Html::footer();
    exit;
}

$query   = isset($_GET['globalsearch']) ? trim($_GET['globalsearch']) : '';
$results = [];

// Cargar el motor de búsqueda del plugin
require_once GLPI_ROOT . '/plugins/globalsearch/inc/searchengine.class.php';

if ($query !== '') {
    $engine  = new PluginGlobalsearchSearchEngine($query);
    $results = $engine->searchAll();  // Ejecuta Tickets, Project, etc.
}

// Debug: verificar qué estamos obteniendo
error_log("Query: " . $query);
error_log("Results Tickets: " . count($results['Ticket'] ?? []));
error_log("Results Projects: " . count($results['Project'] ?? []));

// Obtener ruta web del plugin para el script JS
$plugin_webroot = Plugin::getWebDir('globalsearch');

// Renderizar plantilla Twig usando el namespace del plugin
// @globalsearch/ apunta a /plugins/globalsearch/templates/
try {
    TemplateRenderer::getInstance()->display(
        '@globalsearch/search_results.html.twig',
        [
            'query'   => $query,
            'results' => $results,
            'plugin_webroot' => $plugin_webroot
        ]
    );
} catch (Exception $e) {
    echo "<pre>Error en template: " . $e->getMessage() . "</pre>";
    error_log("Template error: " . $e->getMessage());
}

Html::footer();
