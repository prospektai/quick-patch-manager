<?php
/**
 * Handles admin menu and page registration for Quick Patch Manager
 */
class QPM_Admin_Menu {
    /**
     * @var QPM_Patch_Manager Patch management handler
     */
    private $patch_manager;

    /**
     * @var QPM_Patch_Editor Patch editor handler
     */
    private $patch_editor;

    /**
     * @var QPM_Patch_History Patch history handler
     */
    private $patch_history;

    /**
     * Initialize admin menu and pages
     */
    public function __construct() {
        $this->patch_manager = new QPM_Patch_Manager();
        $this->patch_editor = new QPM_Patch_Editor();
        $this->patch_history = new QPM_Patch_History();

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX action hooks
        add_action('wp_ajax_qpm_get_plugin_files', array($this, 'ajax_get_plugin_files_callback'));
        add_action('wp_ajax_qpm_load_file_content', array($this, 'ajax_load_file_content_callback'));
        add_action('wp_ajax_qpm_save_file_patch', array($this, 'ajax_save_file_patch_callback'));
        add_action('wp_ajax_qpm_restore_file', array($this, 'ajax_restore_file_callback'));
        add_action('wp_ajax_qpm_get_patch_history', array($this, 'ajax_get_patch_history_callback'));
    }

    /**
     * Add menu items to WordPress admin
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Quick Patch Manager', 'quick-patch-manager'),
            __('Patch Manager', 'quick-patch-manager'),
            'manage_options',
            'quick-patch-manager',
            array($this, 'render_plugin_page'),
            'dashicons-admin-tools',
            99
        );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on our plugin page
        if ($hook !== 'toplevel_page_quick-patch-manager') {
            return;
        }

        // Enqueue Monaco Editor
        wp_enqueue_script(
            'monaco-editor', 
            'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.34.0/min/vs/loader.min.js', 
            array(), 
            '0.34.0', 
            true
        );

        // Enqueue our custom admin scripts
        wp_enqueue_script(
            'qpm-admin-script', 
            plugin_dir_url(__FILE__) . '../assets/js/admin-script.js', 
            array('jquery', 'monaco-editor'), 
            '1.0.0', 
            true
        );

        // Localize script with ajax url and nonce
        wp_localize_script('qpm-admin-script', 'qpmAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qpm_ajax_nonce')
        ));

        // Localize translations
        wp_localize_script('qpm-admin-script', 'qpmTranslations', array(
            'choose_file' => __('Choose a File', 'quick-patch-manager'),
            'confirm_restore' => __('Are you sure you want to restore this file to its original version?', 'quick-patch-manager'),
            'no_history' => __('No patch history available for this plugin.', 'quick-patch-manager')
        ));

        // Enqueue admin styles
        wp_enqueue_style(
            'qpm-admin-style', 
            plugin_dir_url(__FILE__) . '../assets/css/admin-style.css', 
            array(), 
            '1.0.0'
        );

        // Enqueue tree view styles
        wp_enqueue_style(
            'qpm-tree-view-style', 
            plugin_dir_url(__FILE__) . '../assets/css/tree-view.css', 
            array(), 
            '1.0.0'
        );

        // Enqueue Monaco Editor CSS
        wp_enqueue_style(
            'monaco-editor-css', 
            'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.34.0/min/vs/editor.main.min.css', 
            array(), 
            '0.34.0'
        );
    }

    /**
     * Render the main plugin admin page
     */
    public function render_plugin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'quick-patch-manager'));
        }

        // Get list of patchable plugins
        $plugins = $this->patch_manager->get_patchable_plugins();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="qpm-plugin-selector">
                <select id="qpm-plugin-select" class="qpm-select">
                    <option value=""><?php _e('Choose a Plugin', 'quick-patch-manager'); ?></option>
                    <?php foreach ($plugins as $plugin): ?>
                        <option value="<?php echo esc_attr($plugin['path']); ?>">
                            <?php 
                            echo esc_html($plugin['name']); 
                            echo $plugin['active'] ? ' (Active)' : ' (Inactive)';
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="qpm-editor-wrapper" style="display:none;">
                <div id="qpm-file-browser" class="qpm-file-browser">
                    <div id="qpm-file-tree-content"></div>
                </div>
                
                <div id="qpm-file-editor">
                    <div id="qpm-file-content"></div>
                    <div class="qpm-editor-actions">
                        <button id="qpm-save-patch" class="button"><?php _e('Save Changes', 'quick-patch-manager'); ?></button>
                        <button id="qpm-restore-file" class="button"><?php _e('Restore Original', 'quick-patch-manager'); ?></button>
                    </div>
                </div>
            </div>

            <div id="qpm-patch-history" style="display:none;">
                <h2><?php _e('Patch History', 'quick-patch-manager'); ?></h2>
                <table id="qpm-history-table" class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'quick-patch-manager'); ?></th>
                            <th><?php _e('File', 'quick-patch-manager'); ?></th>
                            <th><?php _e('Action', 'quick-patch-manager'); ?></th>
                            <th><?php _e('User', 'quick-patch-manager'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="qpm-history-content"></tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX callback to get plugin files
     */
    public function ajax_get_plugin_files_callback() {
        // Verify nonce for security
        check_ajax_referer('qpm_ajax_nonce', 'nonce');

        // Sanitize input
        $plugin_path = sanitize_text_field($_POST['plugin_path']);

        error_log("QPM Debug - Getting files for plugin: " . $plugin_path);

        // Get hierarchical file structure
        $file_structure = QPM_File_Structure::get_hierarchical_plugin_files($plugin_path);

        error_log("QPM Debug - File structure: " . print_r($file_structure, true));

        // Send JSON response
        wp_send_json_success($file_structure);
    }

    /**
     * AJAX callback to load file content
     */
    public function ajax_load_file_content_callback() {
        // Verify nonce for security
        check_ajax_referer('qpm_ajax_nonce', 'nonce');

        // Sanitize inputs
        $plugin_path = sanitize_text_field($_POST['plugin_path']);
        $file_path = sanitize_text_field($_POST['file_path']);

        error_log("QPM Debug - Loading file content for: " . $file_path);

        // Prepare file for editing
        $file_details = $this->patch_editor->prepare_file_for_edit($plugin_path, $file_path);

        // Send JSON response
        if (is_wp_error($file_details)) {
            error_log("QPM Debug - Error loading file: " . $file_details->get_error_message());
            wp_send_json_error($file_details->get_error_message());
        } else {
            error_log("QPM Debug - File loaded successfully");
            wp_send_json_success($file_details);
        }
    }

    /**
     * AJAX callback to save file patch
     */
    public function ajax_save_file_patch_callback() {
        // Verify nonce for security
        check_ajax_referer('qpm_ajax_nonce', 'nonce');

        // Sanitize inputs
        $file_path = sanitize_text_field($_POST['file_path']);
        $plugin_name = sanitize_text_field($_POST['plugin_name']);
        $new_content = wp_unslash($_POST['file_content']);

        error_log("QPM Debug - Saving patch for: " . $file_path);

        // Apply patch
        $result = $this->patch_editor->apply_file_patch($file_path, $new_content, $plugin_name);

        // Send JSON response
        if (is_wp_error($result)) {
            error_log("QPM Debug - Error saving patch: " . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        } else {
            error_log("QPM Debug - Patch saved successfully");
            wp_send_json_success(__('File patched successfully', 'quick-patch-manager'));
        }
    }

    /**
     * AJAX callback to restore a file
     */
    public function ajax_restore_file_callback() {
        // Verify nonce for security
        check_ajax_referer('qpm_ajax_nonce', 'nonce');

        // Sanitize inputs
        $file_path = sanitize_text_field($_POST['file_path']);
        $plugin_name = sanitize_text_field($_POST['plugin_name']);

        error_log("QPM Debug - Restoring file: " . $file_path);

        // Restore file
        $result = $this->patch_editor->restore_file($file_path, $plugin_name);

        // Send JSON response
        if (is_wp_error($result)) {
            error_log("QPM Debug - Error restoring file: " . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        } else {
            error_log("QPM Debug - File restored successfully");
            wp_send_json_success(__('File restored successfully', 'quick-patch-manager'));
        }
    }

    /**
     * AJAX callback to get patch history
     */
    public function ajax_get_patch_history_callback() {
        // Verify nonce for security
        check_ajax_referer('qpm_ajax_nonce', 'nonce');

        // Sanitize input
        $plugin_name = sanitize_text_field($_POST['plugin_name']);

        error_log("QPM Debug - Getting patch history for: " . $plugin_name);

        // Get patch history
        $history = $this->patch_history->generate_change_log($plugin_name);

        error_log("QPM Debug - Patch history: " . print_r($history, true));

        // Send JSON response
        wp_send_json_success($history);
    }
}
