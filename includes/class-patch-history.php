<?php
/**
 * Manages patch history and tracking for Quick Patch Manager
 */
class QPM_Patch_History {
    /**
     * @var QPM_Patch_Database Database handler
     */
    private $database;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new QPM_Patch_Database();
    }

    /**
     * Get patch history for a specific plugin
     *
     * @param string $plugin_name Name of the plugin
     * @param int $limit Number of history entries to retrieve
     * @return array Patch history entries
     */
    public function get_plugin_patch_history($plugin_name, $limit = 50) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plugin_patch_manager';

        $query = $wpdb->prepare(
            "SELECT 
                id, 
                plugin_name, 
                file_name, 
                file_path, 
                patch_date, 
                user_id, 
                action
            FROM $table_name 
            WHERE plugin_name = %s 
            ORDER BY patch_date DESC 
            LIMIT %d",
            $plugin_name,
            $limit
        );

        $history = $wpdb->get_results($query, ARRAY_A);

        // Enrich history with user information
        foreach ($history as &$entry) {
            $user = get_userdata($entry['user_id']);
            $entry['user_display_name'] = $user ? $user->display_name : 'Unknown User';
        }

        return $history;
    }

    /**
     * Get detailed patch information
     *
     * @param int $patch_id ID of the patch
     * @return array|null Patch details
     */
    public function get_patch_details($patch_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plugin_patch_manager';

        $patch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $patch_id
        ), ARRAY_A);

        if (!$patch) {
            return null;
        }

        // Get user information
        $user = get_userdata($patch['user_id']);
        $patch['user_display_name'] = $user ? $user->display_name : 'Unknown User';

        // Get backup information
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';
        $backup = $wpdb->get_row($wpdb->prepare(
            "SELECT backup_file_path FROM $backup_table 
            WHERE original_file_path = %s 
            AND plugin_name = %s 
            AND backup_date <= %s 
            ORDER BY backup_date DESC 
            LIMIT 1",
            $patch['file_path'],
            $patch['plugin_name'],
            $patch['patch_date']
        ), ARRAY_A);

        $patch['backup_file_path'] = $backup ? $backup['backup_file_path'] : null;

        return $patch;
    }

    /**
     * Generate a comprehensive change log for a plugin
     *
     * @param string $plugin_name Name of the plugin
     * @param int $limit Number of log entries to retrieve
     * @return array Comprehensive change log
     */
    public function generate_change_log($plugin_name, $limit = 100) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plugin_patch_manager';

        $query = $wpdb->prepare(
            "SELECT 
                id, 
                plugin_name, 
                file_name, 
                file_path, 
                patch_date, 
                user_id, 
                action,
                patch_content
            FROM $table_name 
            WHERE plugin_name = %s 
            ORDER BY patch_date DESC 
            LIMIT %d",
            $plugin_name,
            $limit
        );

        $log_entries = $wpdb->get_results($query, ARRAY_A);

        // Process and enrich log entries
        $change_log = array();
        foreach ($log_entries as $entry) {
            $user = get_userdata($entry['user_id']);
            
            $change_log[] = array(
                'id' => $entry['id'],
                'date' => $entry['patch_date'],
                'user' => $user ? $user->display_name : 'Unknown User',
                'file' => $entry['file_name'],
                'action' => $entry['action'],
                'summary' => $this->generate_change_summary($entry)
            );
        }

        return $change_log;
    }

    /**
     * Generate a summary of the change
     *
     * @param array $entry Patch entry
     * @return string Change summary
     */
    private function generate_change_summary($entry) {
        switch ($entry['action']) {
            case 'edit':
                return sprintf(
                    __('Edited file %s', 'quick-patch-manager'),
                    $entry['file_name']
                );
            
            case 'restore':
                return sprintf(
                    __('Restored file %s to previous version', 'quick-patch-manager'),
                    $entry['file_name']
                );
            
            default:
                return sprintf(
                    __('Performed %s action on %s', 'quick-patch-manager'),
                    $entry['action'],
                    $entry['file_name']
                );
        }
    }

    /**
     * Export patch history for a plugin
     *
     * @param string $plugin_name Name of the plugin
     * @param string $format Export format (json, csv)
     * @return string Exported data
     */
    public function export_patch_history($plugin_name, $format = 'json') {
        $change_log = $this->generate_change_log($plugin_name, 1000);

        switch ($format) {
            case 'json':
                return json_encode($change_log, JSON_PRETTY_PRINT);
            
            case 'csv':
                $csv = "ID,Date,User,File,Action,Summary\n";
                foreach ($change_log as $entry) {
                    $csv .= sprintf(
                        "%d,%s,%s,%s,%s,%s\n",
                        $entry['id'],
                        $entry['date'],
                        str_replace(',', ' ', $entry['user']),
                        $entry['file'],
                        $entry['action'],
                        str_replace(',', ' ', $entry['summary'])
                    );
                }
                return $csv;
            
            default:
                return json_encode($change_log, JSON_PRETTY_PRINT);
        }
    }
}
