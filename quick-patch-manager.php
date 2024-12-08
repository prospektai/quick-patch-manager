<?php
/**
 * Plugin Name: Quick Patch Manager
 * Plugin URI: https://example.com/quick-patch-manager
 * Description: A comprehensive WordPress plugin for managing and patching other plugins safely.
 * Version: 1.0.1
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: quick-patch-manager
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * 
 * @package QuickPatchManager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('QPM_VERSION', '1.0.1');
define('QPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QPM_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once QPM_PLUGIN_DIR . 'includes/class-patch-database.php';
require_once QPM_PLUGIN_DIR . 'includes/class-patch-manager.php';
require_once QPM_PLUGIN_DIR . 'includes/class-patch-editor.php';
require_once QPM_PLUGIN_DIR . 'includes/class-patch-history.php';
require_once QPM_PLUGIN_DIR . 'includes/class-file-structure.php';
require_once QPM_PLUGIN_DIR . 'admin/class-admin-menu.php';
require_once QPM_PLUGIN_DIR . 'admin/class-admin-page.php';

/**
 * Main plugin class
 */
class Quick_Patch_Manager {
    /**
     * @var QPM_Patch_Database Database handler
     */
    private $database;

    /**
     * @var QPM_Admin_Menu Admin menu handler
     */
    private $admin_menu;

    /**
     * @var QPM_Admin_Page Admin page handler
     */
    private $admin_page;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize plugin components
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Plugin activation and deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Load translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Check for database upgrades
        add_action('plugins_loaded', array($this, 'check_version'));

        // Initialize admin components
        add_action('init', array($this, 'init_admin_components'));
    }

    /**
     * Check plugin version and run upgrades if necessary
     */
    public function check_version() {
        $current_version = get_option('qpm_version', '1.0.0');
        
        if (version_compare($current_version, QPM_VERSION, '<')) {
            // Run upgrade routines
            $this->database = new QPM_Patch_Database();
            $this->database->create_tables(); // This will run the upgrade checks
            
            // Update version in database
            update_option('qpm_version', QPM_VERSION);
        }
    }

    /**
     * Plugin activation routine
     */
    public function activate() {
        // Create custom database tables
        $this->database = new QPM_Patch_Database();
        $this->database->create_tables();

        // Set up initial plugin options
        $default_settings = array(
            'backup_enabled' => 1,
            'notification_email' => get_option('admin_email'),
            'history_retention' => 30
        );
        add_option('qpm_settings', $default_settings);
        add_option('qpm_version', QPM_VERSION);

        // Clear any existing error messages
        delete_transient('qpm_db_upgrade_error');
    }

    /**
     * Plugin deactivation routine
     */
    public function deactivate() {
        // Optional cleanup
        // Uncomment if you want to remove data on deactivation
        // delete_option('qpm_settings');
        // delete_option('qpm_version');
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'quick-patch-manager', 
            false, 
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Initialize admin components
     */
    public function init_admin_components() {
        // Only load admin components in admin area
        if (is_admin()) {
            $this->admin_menu = new QPM_Admin_Menu();
            $this->admin_page = new QPM_Admin_Page();

            // Add admin notice for database upgrade errors
            add_action('admin_notices', array($this, 'show_upgrade_notices'));
        }
    }

    /**
     * Show admin notices for database upgrade errors
     */
    public function show_upgrade_notices() {
        $error = get_transient('qpm_db_upgrade_error');
        if ($error) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . esc_html($error) . '</p>';
            echo '</div>';
            delete_transient('qpm_db_upgrade_error');
        }
    }

    /**
     * Initialize plugin
     *
     * @return Quick_Patch_Manager
     */
    public static function init() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }
}

// Initialize the plugin
Quick_Patch_Manager::init();
