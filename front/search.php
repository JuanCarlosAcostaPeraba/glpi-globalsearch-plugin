<?php

/**
 * Custom Global Search Endpoint
 * plugins/globalsearch/front/search.php
 */


use Glpi\Application\View\TemplateRenderer;

global $CFG_GLPI;


Session::checkCentralAccess();
Html::header(__('Search'), $_SERVER['PHP_SELF']);

// Respect global search allow option
if (!$CFG_GLPI['allow_search_global']) {
    Html::displayRightError();
    Html::footer();
    exit;
}

$query   = isset($_GET['globalsearch']) ? trim($_GET['globalsearch']) : '';
$results = [];

// Load plugin search engine
require_once __DIR__ . '/../inc/searchengine.class.php';

if ($query !== '') {
    $engine  = new PluginGlobalsearchSearchEngine($query);
    $results = $engine->searchAll();  // Executes Tickets, Project, etc.
}



// Get plugin web path for JS script
$plugin_webroot = Plugin::getWebDir('globalsearch');

// Render Twig template using plugin namespace
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
    error_log("Template error: " . $e->getMessage());
    echo "<p class='alert alert-danger'>" . __('An error occurred') . "</p>";
}

Html::footer();
