<?php
/**
 * Handles additional admin page functionality for Quick Patch Manager
 */
class QPM_Admin_Page {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
    }

    /**
     * Add settings page to the plugin menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'quick-patch-manager', 
            __('Quick Patch Manager Settings', 'quick-patch-manager'),
            __('Settings', 'quick-patch-manager'),
            'manage_options',
            'qpm-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'quick-patch-manager'));
        }

        // Get current settings
        $settings = get_option('qpm_settings', $this->get_default_settings());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                // Output security fields
                settings_fields('qpm_settings_group');
                
                // Output setting sections
                do_settings_sections('qpm-settings');
                
                // Submit button
                submit_button(__('Save Settings', 'quick-patch-manager'));
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register settings group
        register_setting(
            'qpm_settings_group', 
            'qpm_settings', 
            array($this, 'sanitize_settings')
        );

        // General Settings Section
        add_settings_section(
            'qpm_general_settings', 
            __('General Settings', 'quick-patch-manager'),
            array($this, 'general_settings_section_callback'),
            'qpm-settings'
        );

        // Backup Settings
        add_settings_field(
            'qpm_backup_enabled',
            __('Enable Automatic Backups', 'quick-patch-manager'),
            array($this, 'render_checkbox_field'),
            'qpm-settings',
            'qpm_general_settings',
            array(
                'label_for' => 'qpm_backup_enabled',
                'name' => 'qpm_settings[backup_enabled]',
                'value' => isset($settings['backup_enabled']) ? $settings['backup_enabled'] : 1
            )
        );

        // Notification Settings
        add_settings_field(
            'qpm_notification_email',
            __('Notification Email', 'quick-patch-manager'),
            array($this, 'render_text_field'),
            'qpm-settings',
            'qpm_general_settings',
            array(
                'label_for' => 'qpm_notification_email',
                'name' => 'qpm_settings[notification_email]',
                'value' => isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email'),
                'description' => __('Email address to receive patch notifications', 'quick-patch-manager')
            )
        );

        // Patch History Retention
        add_settings_field(
            'qpm_history_retention',
            __('Patch History Retention', 'quick-patch-manager'),
            array($this, 'render_number_field'),
            'qpm-settings',
            'qpm_general_settings',
            array(
                'label_for' => 'qpm_history_retention',
                'name' => 'qpm_settings[history_retention]',
                'value' => isset($settings['history_retention']) ? $settings['history_retention'] : 30,
                'description' => __('Number of days to keep patch history (0 for unlimited)', 'quick-patch-manager')
            )
        );
    }

    /**
     * Render checkbox field
     *
     * @param array $args Field arguments
     */
    public function render_checkbox_field($args) {
        $value = isset($args['value']) ? $args['value'] : 0;
        ?>
        <input 
            type="checkbox" 
            id="<?php echo esc_attr($args['label_for']); ?>" 
            name="<?php echo esc_attr($args['name']); ?>" 
            value="1" 
            <?php checked(1, $value, true); ?>
        />
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render text field
     *
     * @param array $args Field arguments
     */
    public function render_text_field($args) {
        $value = isset($args['value']) ? $args['value'] : '';
        ?>
        <input 
            type="text" 
            id="<?php echo esc_attr($args['label_for']); ?>" 
            name="<?php echo esc_attr($args['name']); ?>" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
        />
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render number field
     *
     * @param array $args Field arguments
     */
    public function render_number_field($args) {
        $value = isset($args['value']) ? $args['value'] : 0;
        ?>
        <input 
            type="number" 
            id="<?php echo esc_attr($args['label_for']); ?>" 
            name="<?php echo esc_attr($args['name']); ?>" 
            value="<?php echo esc_attr($value); ?>" 
            min="0"
            class="small-text"
        />
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * General settings section callback
     */
    public function general_settings_section_callback() {
        echo '<p>' . esc_html__('Configure general settings for the Quick Patch Manager plugin.', 'quick-patch-manager') . '</p>';
    }

    /**
     * Sanitize settings
     *
     * @param array $input Incoming settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $output = array();

        // Sanitize backup enabled
        $output['backup_enabled'] = isset($input['backup_enabled']) ? 1 : 0;

        // Sanitize notification email
        $output['notification_email'] = sanitize_email($input['notification_email']);
        if (!is_email($output['notification_email'])) {
            $output['notification_email'] = get_option('admin_email');
        }

        // Sanitize history retention
        $output['history_retention'] = absint($input['history_retention']);

        return $output;
    }

    /**
     * Get default plugin settings
     *
     * @return array Default settings
     */
    private function get_default_settings() {
        return array(
            'backup_enabled' => 1,
            'notification_email' => get_option('admin_email'),
            'history_retention' => 30
        );
    }
}
