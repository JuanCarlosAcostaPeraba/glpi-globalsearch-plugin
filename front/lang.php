<?php
if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

header('Content-Type: application/javascript; charset=UTF-8');

// Serve translated strings as JavaScript global variable
?>
window.GLOBALSEARCH_LANG = {
    btn_title:   <?= json_encode(__('Advanced global search', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    btn_label:   <?= json_encode(__('Global search', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    modal_title: <?= json_encode(__('Global search', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    close:       <?= json_encode(__('Close', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    placeholder: <?= json_encode(__('Search tickets, projects (min. 3 characters)...', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    help_text:   <?= json_encode(__('Search by ID (e.g. #123), exact phrases (e.g. "web server") or individual words.', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    searching:   <?= json_encode(__('Searching…', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    cancel:      <?= json_encode(__('Cancel', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>,
    search:      <?= json_encode(__('Search', 'globalsearch'), JSON_UNESCAPED_UNICODE) ?>
};
