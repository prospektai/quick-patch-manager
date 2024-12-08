<?php
/**
 * Handles file editing operations for Quick Patch Manager
 */
class QPM_Patch_Editor {
    /**
     * @var QPM_Patch_Manager Patch management handler
     */
    private $patch_manager;

    /**
     * @var QPM_Patch_Database Database handler
     */
    private $database;

    /**
     * Constructor
     */
    public function __construct() {
        $this->patch_manager = new QPM_Patch_Manager();
        $this->database = new QPM_Patch_Database();
    }

    /**
     * Validate file edit permissions and file type
     *
     * @param string $file_path Full path to the file
     * @return bool|WP_Error Validation result
     */
    public function validate_file_edit($file_path) {
        // Check user permissions
        if (!current_user_can('administrator')) {
            return new WP_Error('permission_denied', __('You do not have permission to edit files.', 'quick-patch-manager'));
        }

        // Normalize and clean the file path
        $file_path = wp_normalize_path($file_path);

        // Check if file path is absolute
        if (!path_is_absolute($file_path)) {
            return new WP_Error('invalid_path', __('File path must be an absolute path.', 'quick-patch-manager'));
        }

        // Validate file path exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', sprintf(__('The file %s does not exist.', 'quick-patch-manager'), $file_path));
        }

        // Check file is within allowed directories
        $allowed_dirs = array(
            wp_normalize_path(WP_PLUGIN_DIR),
            wp_normalize_path(get_theme_root()),
            wp_normalize_path(WPMU_PLUGIN_DIR)
        );

        $is_in_allowed_dir = false;
        foreach ($allowed_dirs as $allowed_dir) {
            if (strpos($file_path, $allowed_dir) === 0) {
                $is_in_allowed_dir = true;
                break;
            }
        }

        if (!$is_in_allowed_dir) {
            return new WP_Error('invalid_file_location', __('You can only edit files within plugins, themes, or must-use plugin directories.', 'quick-patch-manager'));
        }

        // Check file type (only allow certain file types)
        $allowed_extensions = array('php', 'js', 'css', 'txt', 'json', 'xml', 'html', 'htm', 'inc');
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            return new WP_Error('invalid_file_type', __('You can only edit specific file types.', 'quick-patch-manager'));
        }

        // Check file is writable
        if (!is_writable($file_path)) {
            return new WP_Error('file_not_writable', __('The file is not writable.', 'quick-patch-manager'));
        }

        return true;
    }

    /**
     * Prepare file for editing
     *
     * @param string $plugin_path Plugin path
     * @param string $file_relative_path Relative path of the file within the plugin
     * @return array|WP_Error File details or error
     */
    public function prepare_file_for_edit($plugin_path, $file_relative_path) {
        // Sanitize inputs
        $plugin_path = sanitize_text_field($plugin_path);
        $file_relative_path = sanitize_text_field($file_relative_path);

        // Get full plugin directory
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
        $full_file_path = wp_normalize_path($plugin_dir . '/' . $file_relative_path);

        // Validate file edit
        $validation = $this->validate_file_edit($full_file_path);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Read file content
        $file_content = $this->read_file_content($full_file_path);
        if ($file_content === false) {
            return new WP_Error('read_file_failed', __('Could not read file contents.', 'quick-patch-manager'));
        }

        return array(
            'path' => $full_file_path,
            'relative_path' => $file_relative_path,
            'plugin_name' => dirname($plugin_path),
            'content' => $file_content
        );
    }

    /**
     * Read file content safely
     *
     * @param string $file_path Full path to the file
     * @return string|false File contents or false on failure
     */
    private function read_file_content($file_path) {
        // Ensure file exists and is readable
        if (!is_readable($file_path)) {
            return false;
        }

        // Use WordPress file system API for reading
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        
        // Initialize WordPress filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        global $wp_filesystem;

        // Read file content with error handling
        $content = $wp_filesystem->get_contents($file_path);
        
        // Additional validation
        if ($content === false) {
            error_log("QPM: Failed to read file {$file_path}");
            return false;
        }

        return $content;
    }

    /**
     * Apply file patch
     *
     * @param string $file_path Full path to the file
     * @param string $new_content New file content
     * @param string $plugin_name Name of the plugin
     * @return bool|WP_Error Patch result
     */
    public function apply_file_patch($file_path, $new_content, $plugin_name) {
        // Normalize file path
        $file_path = wp_normalize_path($file_path);

        // Validate file edit
        $validation = $this->validate_file_edit($file_path);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Create a backup of the original file
        $backup_path = $this->database->create_file_backup($file_path, $plugin_name);
        
        if (!$backup_path) {
            return new WP_Error('backup_failed', __('Could not create a backup of the file.', 'quick-patch-manager'));
        }

        // Use WordPress file system API for writing
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;

        // Attempt to write new content
        $result = $wp_filesystem->put_contents(
            $file_path, 
            $new_content, 
            FS_CHMOD_FILE
        );

        if ($result) {
            // Log the patch action
            $this->database->log_patch_action(
                $plugin_name, 
                basename($file_path), 
                $file_path, 
                'edit', 
                $new_content
            );

            return true;
        }

        return new WP_Error('patch_failed', __('Could not write changes to the file.', 'quick-patch-manager'));
    }

    /**
     * Restore a file to a previous backup
     *
     * @param string $file_path Full path to the file
     * @param string $plugin_name Name of the plugin
     * @return bool|WP_Error Restore result
     */
    public function restore_file($file_path, $plugin_name) {
        // Normalize file path
        $file_path = wp_normalize_path($file_path);

        // Validate file edit
        $validation = $this->validate_file_edit($file_path);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Find the most recent backup for this file
        global $wpdb;
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';

        $backup = $wpdb->get_row($wpdb->prepare(
            "SELECT backup_file_path FROM $backup_table 
            WHERE original_file_path = %s 
            AND plugin_name = %s 
            ORDER BY backup_date DESC 
            LIMIT 1",
            $file_path,
            $plugin_name
        ));

        if (!$backup) {
            return new WP_Error('no_backup', __('No backup found for this file.', 'quick-patch-manager'));
        }

        // Use WordPress file system API for copying
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;

        // Verify backup file exists
        if (!$wp_filesystem->exists($backup->backup_file_path)) {
            return new WP_Error('backup_missing', __('Backup file is missing.', 'quick-patch-manager'));
        }

        // Copy backup file back to original location
        $restore_result = $wp_filesystem->copy(
            $backup->backup_file_path, 
            $file_path, 
            true
        );

        if ($restore_result) {
            // Log the restore action
            $this->database->log_patch_action(
                $plugin_name, 
                basename($file_path), 
                $file_path, 
                'restore'
            );

            return true;
        }

        return new WP_Error('restore_failed', __('Could not restore the file.', 'quick-patch-manager'));
    }
}
