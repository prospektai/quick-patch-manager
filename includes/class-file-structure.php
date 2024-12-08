<?php
/**
 * Handles hierarchical file structure operations for Quick Patch Manager
 */
class QPM_File_Structure {
    /**
     * Get hierarchical file structure for a plugin
     *
     * @param string $plugin_path Path to the plugin
     * @return array Hierarchical file structure
     */
    public static function get_hierarchical_plugin_files($plugin_path) {
        // Sanitize input
        $plugin_path = sanitize_text_field($plugin_path);
        
        // Get full plugin directory
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_path);
        
        if (!is_dir($plugin_dir)) {
            error_log("QPM: Directory not found - {$plugin_dir}");
            return array();
        }

        // Recursively get file structure
        return self::get_directory_structure($plugin_dir);
    }

    /**
     * Recursively build directory structure
     *
     * @param string $directory Directory path
     * @param string $base_path Base path for relative path calculation
     * @return array Directory structure
     */
    private static function get_directory_structure($directory, $base_path = null) {
        // Normalize paths
        $directory = wp_normalize_path($directory);
        $base_path = $base_path ?? $directory;

        // Initialize structure
        $structure = array(
            'name' => basename($directory),
            'path' => $directory,
            'type' => 'directory',
            'children' => array()
        );

        // Get directory contents
        $items = @scandir($directory);
        if ($items === false) {
            error_log("QPM: Failed to scan directory - {$directory}");
            return $structure;
        }

        foreach ($items as $item) {
            // Skip hidden files and directories
            if ($item === '.' || $item === '..' || substr($item, 0, 1) === '.') {
                continue;
            }

            $full_path = wp_normalize_path($directory . '/' . $item);
            $relative_path = str_replace($base_path . '/', '', $full_path);

            if (is_dir($full_path)) {
                // Recursively process subdirectories
                $subdir = self::get_directory_structure($full_path, $base_path);
                if (!empty($subdir['children'])) {
                    $structure['children'][] = $subdir;
                }
            } else {
                // Only include certain file types
                $allowed_extensions = array('php', 'js', 'css', 'txt', 'json', 'xml', 'html', 'htm', 'inc');
                $file_extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $structure['children'][] = array(
                        'name' => $item,
                        'path' => $full_path,
                        'relative_path' => $relative_path,
                        'type' => 'file',
                        'extension' => $file_extension
                    );
                }
            }
        }

        // Sort children (directories first, then files)
        usort($structure['children'], function($a, $b) {
            if ($a['type'] === $b['type']) {
                return strcasecmp($a['name'], $b['name']);
            }
            return $a['type'] === 'directory' ? -1 : 1;
        });

        return $structure;
    }

    /**
     * Flatten hierarchical structure to a list of files
     *
     * @param array $structure Hierarchical file structure
     * @return array Flattened list of files
     */
    public static function flatten_structure($structure) {
        $files = array();

        if (!isset($structure['children'])) {
            return $files;
        }

        foreach ($structure['children'] as $item) {
            if ($item['type'] === 'file') {
                $files[] = $item;
            } elseif ($item['type'] === 'directory') {
                $files = array_merge($files, self::flatten_structure($item));
            }
        }

        return $files;
    }

    /**
     * Get plugin files as JSON for frontend consumption
     *
     * @param string $plugin_path Path to the plugin
     * @return string JSON representation of the plugin file structure
     */
    public static function get_plugin_files_json($plugin_path) {
        $structure = self::get_hierarchical_plugin_files($plugin_path);
        if (empty($structure)) {
            error_log("QPM: Empty structure returned for plugin - {$plugin_path}");
        }
        return wp_json_encode($structure);
    }

    /**
     * Debug function to log file structure
     *
     * @param string $plugin_path Path to the plugin
     * @return void
     */
    public static function debug_file_structure($plugin_path) {
        $structure = self::get_hierarchical_plugin_files($plugin_path);
        error_log("QPM Debug - File Structure for {$plugin_path}: " . print_r($structure, true));
    }
}
