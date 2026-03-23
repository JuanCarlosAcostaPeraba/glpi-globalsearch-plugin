<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$config = new PluginGlobalsearchConfig();

if (isset($_POST['update_config'])) {
    // Preparar datos: si el checkbox no está marcado, no viene en $_POST
    $data = [];
    $all_types = array_keys(PluginGlobalsearchConfig::getSearchTypeNames());

    foreach ($all_types as $type) {
        // Si existe en $_POST['config'], está marcado (1), sino está desmarcado (0)
        $data[$type] = isset($_POST['config'][$type]) ? 1 : 0;
    }

    if (PluginGlobalsearchConfig::updateConfig($data)) {
        Session::addMessageAfterRedirect(__('Configuration saved successfully', 'globalsearch'), false, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Error saving configuration', 'globalsearch'), false, ERROR);
    }

    Html::back();
} else {
    Html::header(
        __('Global Search Configuration', 'globalsearch'),
        $_SERVER['PHP_SELF'],
        'config',
        'plugins',
        'globalsearch'
    );

    $config->showConfigForm();

    Html::footer();
}
