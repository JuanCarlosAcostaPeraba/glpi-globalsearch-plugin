<?php

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$config = new PluginGlobalsearchConfig();

if (isset($_POST['update_config'])) {
    // Prepare data: if the checkbox is not checked, it won't be in $_POST
    $data = [];
    $all_types = array_keys(PluginGlobalsearchConfig::getSearchTypeNames());

    foreach ($all_types as $type) {
        // If it exists in $_POST['config'], it's checked (1), otherwise unchecked (0)
        $data[$type] = isset($_POST['config'][$type]) ? 1 : 0;
    }

    if (PluginGlobalsearchConfig::updateConfig($data)) {
        Session::addMessageAfterRedirect(__('Configuration saved successfully'), false, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Error saving configuration'), false, ERROR);
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
