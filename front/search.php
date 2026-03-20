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

// Respect the allow global search option
if (!$CFG_GLPI['allow_search_global']) {
    Html::displayRightError();
    Html::footer();
    exit;
}

$query   = isset($_GET['globalsearch']) ? trim($_GET['globalsearch']) : '';
$results = [];

// Load the plugin search engine
require_once GLPI_ROOT . '/plugins/globalsearch/inc/searchengine.class.php';

if ($query !== '') {
    $engine  = new PluginGlobalsearchSearchEngine($query);
    $results = $engine->searchAll();  // Executes Tickets, Changes, Projects, etc.
}

// Debug: verify what we're getting
error_log("Query: " . $query);
error_log("Results Tickets: " . count($results['Ticket'] ?? []));
error_log("Results Projects: " . count($results['Project'] ?? []));

// Get plugin web path for the JS script
$plugin_webroot = Plugin::getWebDir('globalsearch');

// Render Twig template using the plugin namespace
// @globalsearch/ points to /plugins/globalsearch/templates/
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
    echo "<pre>Template error: " . $e->getMessage() . "</pre>";
    error_log("Template error: " . $e->getMessage());
}

Html::footer();
