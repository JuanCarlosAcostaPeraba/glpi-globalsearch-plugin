<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginGlobalsearchConfig extends CommonDBTM
{
    static $rightname = 'config';

    /**
     * Gets configuration for all search types
     *
     * @return array Associative array [search_type => is_enabled]
     */
    public static function getConfig()
    {
        global $DB;

        $config = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_globalsearch_configs',
            'ORDER' => 'search_type ASC'
        ]);

        foreach ($iterator as $row) {
            $config[$row['search_type']] = (bool)$row['is_enabled'];
        }

        return $config;
    }

    /**
     * Updates configuration
     *
     * @param array $data Associative array [search_type => is_enabled]
     * @return bool
     */
    public static function updateConfig($data)
    {
        global $DB;

        foreach ($data as $search_type => $is_enabled) {
            $DB->update(
                'glpi_plugin_globalsearch_configs',
                [
                    'is_enabled' => (int)$is_enabled,
                    'date_mod'   => date('Y-m-d H:i:s')
                ],
                [
                    'search_type' => $search_type
                ]
            );
        }

        return true;
    }

    /**
     * Checks if a search type is enabled
     *
     * @param string $search_type
     * @return bool
     */
    public static function isEnabled($search_type)
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_globalsearch_configs',
            'WHERE' => ['search_type' => $search_type],
            'LIMIT' => 1
        ]);

        if (count($iterator)) {
            $row = $iterator->current();
            return (bool)$row['is_enabled'];
        }

        // By default, if it doesn't exist, it is enabled
        return true;
    }

    /**
     * Gets translated names for search types
     *
     * @return array
     */
    public static function getSearchTypeNames()
    {
        return [
            'Ticket'      => _n('Ticket', 'Tickets', 2, 'globalsearch'),
            'Change'      => _n('Change', 'Changes', 2, 'globalsearch'),
            'Project'     => _n('Project', 'Projects', 2, 'globalsearch'),
            'Document'    => _n('Document', 'Documents', 2, 'globalsearch'),
            'Software'    => _n('Software', 'Software', 2, 'globalsearch'),
            'User'        => _n('User', 'Users', 2, 'globalsearch'),
            'TicketTask'  => __('Ticket tasks', 'globalsearch'),
            'ProjectTask' => __('Project tasks', 'globalsearch')
        ];
    }

    /**
     * Shows configuration form
     */
    public function showConfigForm()
    {
        if (!Config::canUpdate()) {
            return false;
        }

        $config = self::getConfig();
        $names  = self::getSearchTypeNames();

        echo "<form method='post' action='" . Plugin::getWebDir('globalsearch') . "/front/config.form.php'>";
        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr class='tab_bg_1'>";
        echo "<th colspan='2'>" . __('Global Search Configuration', 'globalsearch') . "</th>";
        echo "</tr>";

        foreach ($names as $type => $label) {
            $checked = isset($config[$type]) && $config[$type] ? 'checked' : '';

            echo "<tr class='tab_bg_1'>";
            echo "<td width='50%'><strong>" . $label . "</strong></td>";
            echo "<td>";
            echo "<input type='checkbox' name='config[{$type}]' value='1' {$checked} />";
            echo " " . __('Enable search in this type', 'globalsearch');
            echo "</td>";
            echo "</tr>";
        }

        echo "<tr class='tab_bg_2'>";
        echo "<td colspan='2' class='center'>";
        echo "<input type='submit' name='update_config' value='" . __('Save', 'globalsearch') . "' class='btn btn-primary'/>";
        echo "</td>";
        echo "</tr>";

        echo "</table>";
        echo "</div>";
        Html::closeForm();
    }
}
