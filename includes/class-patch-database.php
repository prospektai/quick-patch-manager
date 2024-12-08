<?php
/**
 * Handles database operations for Quick Patch Manager
 */
class QPM_Patch_Database {
    /**
     * Create or upgrade custom database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Patch tracking table
        $patch_table = $wpdb->prefix . 'plugin_patch_manager';
        
        // Backup files table
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';

        // SQL to create patch tracking table
        $patch_sql = "CREATE TABLE IF NOT EXISTS $patch_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plugin_name VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            patch_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            patch_content LONGTEXT,
            PRIMARY KEY (id),
            KEY plugin_name (plugin_name),
            KEY patch_date (patch_date)
        ) $charset_collate;";

        // SQL to create backup files table
        $backup_sql = "CREATE TABLE IF NOT EXISTS $backup_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_file_path VARCHAR(500) NOT NULL,
            backup_file_path VARCHAR(500) NOT NULL,
            backup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            plugin_name VARCHAR(255) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY original_file_path (original_file_path)
        ) $charset_collate;";

        // Include WordPress upgrade script
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Execute table creation
        dbDelta($patch_sql);
        dbDelta($backup_sql);

        // Check if we need to add new columns
        $this->maybe_upgrade_tables();
    }

    /**
     * Add new columns if they don't exist
     */
    private function maybe_upgrade_tables() {
        global $wpdb;
        $patch_table = $wpdb->prefix . 'plugin_patch_manager';
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';

        // Check if description column exists in patch_manager table
        $description_exists = $wpdb->get_results("SHOW COLUMNS FROM $patch_table LIKE 'description'");
        if (empty($description_exists)) {
            $wpdb->query("ALTER TABLE $patch_table ADD COLUMN description TEXT AFTER patch_content");
        }

        // Check if is_original column exists in patch_backups table
        $is_original_exists = $wpdb->get_results("SHOW COLUMNS FROM $backup_table LIKE 'is_original'");
        if (empty($is_original_exists)) {
            $wpdb->query("ALTER TABLE $backup_table ADD COLUMN is_original BOOLEAN NOT NULL DEFAULT 0");
        }
    }

    /**
     * Log a patch action
     *
     * @param string $plugin_name Name of the plugin being patched
     * @param string $file_name Name of the file patched
     * @param string $file_path Full path of the file
     * @param string $action Type of action (edit, restore, etc.)
     * @param string $patch_content Content of the patch
     * @return int|false ID of the inserted log entry or false on failure
     */
    public function log_patch_action($plugin_name, $file_name, $file_path, $action, $patch_content = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plugin_patch_manager';

        $result = $wpdb->insert(
            $table_name,
            [
                'plugin_name' => sanitize_text_field($plugin_name),
                'file_name' => sanitize_file_name($file_name),
                'file_path' => sanitize_text_field($file_path),
                'user_id' => get_current_user_id(),
                'action' => sanitize_text_field($action),
                'patch_content' => $patch_content,
                'description' => sprintf('Patch applied by %s', wp_get_current_user()->display_name)
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Create a backup of a file before patching
     *
     * @param string $original_file_path Path of the original file
     * @param string $plugin_name Name of the plugin
     * @return string|false Path of the backup file or false on failure
     */
    public function create_file_backup($original_file_path, $plugin_name) {
        // Ensure the backup directory exists
        $upload_dir = wp_upload_dir();
        $backup_base_dir = $upload_dir['basedir'] . '/qpm-backups/' . sanitize_file_name($plugin_name);
        
        // Create backup directory if it doesn't exist
        wp_mkdir_p($backup_base_dir);

        // Check if this is the first backup for this file
        global $wpdb;
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';
        $is_original = !$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $backup_table WHERE original_file_path = %s",
            $original_file_path
        ));

        // Generate unique backup filename
        $backup_filename = sprintf(
            'backup_%s_%s_%s',
            md5($original_file_path),
            time(),
            basename($original_file_path)
        );
        $backup_file_path = $backup_base_dir . '/' . $backup_filename;

        // Copy the original file to backup location
        if (copy($original_file_path, $backup_file_path)) {
            // Log the backup in the database
            $wpdb->insert(
                $backup_table,
                [
                    'original_file_path' => $original_file_path,
                    'backup_file_path' => $backup_file_path,
                    'plugin_name' => $plugin_name,
                    'user_id' => get_current_user_id(),
                    'is_original' => $is_original
                ],
                ['%s', '%s', '%s', '%d', '%d']
            );

            return $backup_file_path;
        }

        return false;
    }

    /**
     * Retrieve patch history for a specific file or plugin
     *
     * @param string $plugin_name Name of the plugin
     * @param string $file_name Optional file name to filter
     * @param int $limit Optional limit of records to retrieve
     * @return array List of patch history entries
     */
    public function get_patch_history($plugin_name, $file_name = null, $limit = 50) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'plugin_patch_manager';

        $query = $wpdb->prepare(
            "SELECT 
                p.*,
                u.display_name as user_name,
                DATE_FORMAT(p.patch_date, '%Y-%m-%d %H:%i:%s') as formatted_date
            FROM $table_name p
            LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
            WHERE p.plugin_name = %s 
            " . ($file_name ? "AND p.file_name = %s " : "") . "
            ORDER BY p.patch_date DESC 
            LIMIT %d",
            $plugin_name,
            $file_name ? $file_name : '',
            $limit
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        // Format the results for display
        return array_map(function($entry) {
            return [
                'date' => $entry['formatted_date'],
                'file' => $entry['file_name'],
                'action' => $entry['action'],
                'user' => $entry['user_name'],
                'description' => $entry['description']
            ];
        }, $results);
    }

    /**
     * Get all backups for a specific file
     *
     * @param string $file_path Full path of the file
     * @return array List of all backups
     */
    public function get_file_backups($file_path) {
        global $wpdb;
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $backup_table 
            WHERE original_file_path = %s 
            ORDER BY backup_date DESC",
            $file_path
        ), ARRAY_A);
    }

    /**
     * Get the original backup of a file
     *
     * @param string $file_path Full path of the file
     * @return array|null Original backup data or null if not found
     */
    public function get_original_backup($file_path) {
        global $wpdb;
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $backup_table 
            WHERE original_file_path = %s 
            AND is_original = 1
            LIMIT 1",
            $file_path
        ), ARRAY_A);
    }
}
