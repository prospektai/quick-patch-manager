<?php
/**
 * Manages plugin patching operations
 */
class QPM_Patch_Manager {
    /**
     * @var QPM_Patch_Database Database handler
     */
    private $database;

    /**
     * Constructor
     */
    public function __construct() {
        $this->database = new QPM_Patch_Database();
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('admin_init', array($this, 'check_plugin_compatibility'));
    }

    /**
     * Get list of installed plugins that can be patched
     *
     * @return array List of patchable plugins
     */
    public function get_patchable_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Get all installed plugins
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());
        $patchable_plugins = array();

        foreach ($all_plugins as $plugin_path => $plugin_data) {
            // Skip must-use plugins and the Quick Patch Manager itself
            if (strpos($plugin_path, 'mu-plugins') !== false || 
                strpos($plugin_path, 'quick-patch-manager') !== false) {
                continue;
            }

            // Check if plugin directory exists and is readable
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
            if (!is_dir($plugin_dir) || !is_readable($plugin_dir)) {
                continue;
            }

            $patchable_plugins[] = array(
                'path' => $plugin_path,
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'active' => in_array($plugin_path, $active_plugins)
            );
        }

        return $patchable_plugins;
    }

    /**
     * Get files within a specific plugin
     *
     * @param string $plugin_path Path to the plugin
     * @return array List of files in the plugin
     */
    public function get_plugin_files($plugin_path) {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
        
        // Recursively get all PHP files in the plugin directory
        $files = $this->get_php_files($plugin_dir);
        
        return $files;
    }

    /**
     * Recursively get PHP files in a directory
     *
     * @param string $dir Directory to search
     * @return array List of PHP files
     */
    private function get_php_files($dir) {
        $php_files = array();
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Get relative path
                $relative_path = str_replace($dir . '/', '', $file->getPathname());
                $php_files[] = array(
                    'path' => $file->getPathname(),
                    'relative_path' => $relative_path
                );
            }
        }

        return $php_files;
    }

    /**
     * Read content of a specific file
     *
     * @param string $file_path Full path to the file
     * @return string|false File contents or false on failure
     */
    public function read_file_content($file_path) {
        // Ensure file exists and is readable
        if (!is_readable($file_path)) {
            return false;
        }

        return file_get_contents($file_path);
    }

    /**
     * Apply a patch to a file
     *
     * @param string $file_path Full path to the file
     * @param string $new_content New content to write
     * @param string $plugin_name Name of the plugin
     * @return bool Success status
     */
    public function apply_patch($file_path, $new_content, $plugin_name) {
        // Create a backup of the original file
        $backup_path = $this->database->create_file_backup($file_path, $plugin_name);
        
        if (!$backup_path) {
            return false;
        }

        // Attempt to write new content
        $result = file_put_contents($file_path, $new_content);

        if ($result !== false) {
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

        return false;
    }

    /**
     * Restore a file to a previous backup
     *
     * @param string $file_path Full path to the file
     * @param string $plugin_name Name of the plugin
     * @return bool Success status
     */
    public function restore_file($file_path, $plugin_name) {
        global $wpdb;
        $backup_table = $wpdb->prefix . 'plugin_patch_backups';

        // Find the most recent backup for this file
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
            return false;
        }

        // Copy backup file back to original location
        $restore_result = copy($backup->backup_file_path, $file_path);

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

        return false;
    }

    /**
     * Check plugin compatibility and version
     */
    public function check_plugin_compatibility() {
        // Future implementation for checking plugin versions
        // and ensuring patches are compatible
    }

    /**
     * Generate a diff between two file versions
     *
     * @param string $original_content Original file content
     * @param string $new_content New file content
     * @return string Diff representation
     */
    public function generate_diff($original_content, $new_content) {
        // Use PHP's built-in diff functionality
        $diff = new Diff(
            explode("\n", $original_content),
            explode("\n", $new_content)
        );
        $renderer = new Diff_Renderer_Text_Unified();
        return $renderer->render($diff);
    }
}

// Include diff library (you would need to implement or use a third-party library)
if (!class_exists('Diff')) {
    class Diff {
        private $from_lines;
        private $to_lines;

        public function __construct($from_lines, $to_lines) {
            $this->from_lines = $from_lines;
            $this->to_lines = $to_lines;
        }
    }

    class Diff_Renderer_Text_Unified {
        public function render($diff) {
            // Basic diff rendering
            $output = "--- Original\n+++ Modified\n";
            
            foreach ($this->from_lines as $index => $line) {
                if (!isset($this->to_lines[$index]) || $line !== $this->to_lines[$index]) {
                    $output .= "- $line\n";
                    if (isset($this->to_lines[$index])) {
                        $output .= "+ " . $this->to_lines[$index] . "\n";
                    }
                }
            }

            return $output;
        }
    }
}
