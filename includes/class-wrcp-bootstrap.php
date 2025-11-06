<?php
/**
 * Bootstrap class for WooCommerce Role Category Pricing plugin
 *
 * @package WRCP
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main bootstrap class for the plugin
 */
class WRCP_Bootstrap {
    
    /**
     * Single instance of the class
     *
     * @var WRCP_Bootstrap
     */
    private static $instance = null;
    
    /**
     * Plugin version
     *
     * @var string
     */
    private $version;
    
    /**
     * Minimum required WooCommerce version
     *
     * @var string
     */
    private $min_wc_version = '5.0';
    
    /**
     * Minimum required WordPress version
     *
     * @var string
     */
    private $min_wp_version = '5.0';
    
    /**
     * Get single instance of the class
     *
     * @return WRCP_Bootstrap
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->version = WRCP_VERSION;
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Check dependencies
        if (!$this->check_dependencies()) {
            return;
        }
        
        // Load text domain
        add_action('init', array($this, 'load_textdomain'));
        
        // Initialize plugin components
        add_action('init', array($this, 'init_components'), 10);
        
        // Add admin notices for dependency issues
        add_action('admin_notices', array($this, 'admin_notices'));
    }
    
    /**
     * Check plugin dependencies
     *
     * @return bool
     */
    public function check_dependencies() {
        $errors = array();
        
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), $this->min_wp_version, '<')) {
            $errors[] = sprintf(
                __('WooCommerce Role Category Pricing requires WordPress %s or higher.', 'woocommerce-role-category-pricing'),
                $this->min_wp_version
            );
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            $errors[] = __('WooCommerce Role Category Pricing requires WooCommerce to be installed and active.', 'woocommerce-role-category-pricing');
        } else {
            // Check WooCommerce version
            if (version_compare(WC()->version, $this->min_wc_version, '<')) {
                $errors[] = sprintf(
                    __('WooCommerce Role Category Pricing requires WooCommerce %s or higher.', 'woocommerce-role-category-pricing'),
                    $this->min_wc_version
                );
            }
        }
        
        // Store errors for admin notices
        if (!empty($errors)) {
            update_option('wrcp_dependency_errors', $errors);
            return false;
        }
        
        // Clear any previous errors
        delete_option('wrcp_dependency_errors');
        return true;
    }
    
    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Only initialize if dependencies are met
        if (!$this->check_dependencies()) {
            return;
        }
        
        // Initialize core components one by one to isolate issues
        try {
            WRCP_Role_Manager::get_instance();
        } catch (Error $e) {
            error_log('WRCP Error initializing Role Manager: ' . $e->getMessage());
            return;
        }
        
        try {
            WRCP_Admin_Settings::get_instance();
        } catch (Error $e) {
            error_log('WRCP Error initializing Admin Settings: ' . $e->getMessage());
            return;
        }
        
        try {
            WRCP_Frontend_Display::get_instance();
        } catch (Error $e) {
            error_log('WRCP Error initializing Frontend Display: ' . $e->getMessage());
            return;
        }
        
        try {
            WRCP_Cart_Integration::get_instance();
        } catch (Error $e) {
            error_log('WRCP Error initializing Cart Integration: ' . $e->getMessage());
            return;
        }
        
        try {
            // Initialize WWP compatibility layer
            $compatibility = WRCP_WWP_Compatibility::get_instance();
        } catch (Error $e) {
            error_log('WRCP Error initializing WWP Compatibility: ' . $e->getMessage());
            return;
        }
        
        // Force refresh compatibility on first load to clear any old caches
        if (get_transient('wrcp_force_refresh') !== false) {
            $compatibility->refresh_compatibility();
            delete_transient('wrcp_force_refresh');
        }
        
        // Ensure Educator role is set up with default settings for testing
        add_action('init', array($this, 'setup_educator_role_for_testing'), 5);
        
        // Add a simple debug shortcode for frontend testing
        add_shortcode('wrcp_status', array($this, 'wrcp_status_shortcode'));
    }
    
    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        // Load plugin text domain
        $loaded = load_plugin_textdomain(
            'woocommerce-role-category-pricing',
            false,
            dirname(WRCP_PLUGIN_BASENAME) . '/languages'
        );
        
        // Log if text domain loading failed (for debugging)
        if (!$loaded && defined('WP_DEBUG') && WP_DEBUG) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->debug(
                    'Failed to load text domain for woocommerce-role-category-pricing',
                    array('source' => 'wrcp-i18n')
                );
            }
        }
        
        // Set up RTL support
        $this->setup_rtl_support();
    }
    
    /**
     * Set up RTL (Right-to-Left) language support
     */
    private function setup_rtl_support() {
        // Check if current language is RTL
        if (is_rtl()) {
            // Add RTL-specific hooks
            add_action('admin_enqueue_scripts', array($this, 'enqueue_rtl_styles'), 20);
            
            // Add RTL body class for admin pages
            add_filter('admin_body_class', array($this, 'add_rtl_admin_body_class'));
        }
    }
    
    /**
     * Enqueue RTL styles for admin pages
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_rtl_styles($hook) {
        // Only load on WRCP admin pages
        if (strpos($hook, 'wrcp-settings') === false) {
            return;
        }
        
        // Enqueue RTL admin styles
        wp_enqueue_style(
            'wrcp-admin-styles-rtl',
            WRCP_PLUGIN_URL . 'admin/css/admin-styles-rtl.css',
            array('wrcp-admin-styles'),
            WRCP_VERSION
        );
    }
    
    /**
     * Add RTL class to admin body for better styling
     *
     * @param string $classes Current body classes
     * @return string Modified body classes
     */
    public function add_rtl_admin_body_class($classes) {
        $classes .= ' wrcp-rtl';
        return $classes;
    }
    
    /**
     * Display admin notices for dependency errors
     */
    public function admin_notices() {
        $errors = get_option('wrcp_dependency_errors', array());
        
        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
        }
        
        // Show WRCP status for current user (admin only)
        if (current_user_can('manage_options') && is_user_logged_in()) {
            $current_user_id = get_current_user_id();
            $user = get_user_by('id', $current_user_id);
            
            if ($user) {
                $settings = get_option('wrcp_settings', array());
                
                // Show debug info for any admin user
                echo '<div class="notice notice-info"><p><strong>WRCP Debug:</strong> ';
                echo 'User roles: ' . implode(', ', $user->roles) . ' | ';
                echo 'Settings exist: ' . (empty($settings) ? 'No' : 'Yes') . ' | ';
                
                if (in_array('educator', $user->roles)) {
                    $educator_enabled = isset($settings['enabled_roles']['educator']['enabled']) && $settings['enabled_roles']['educator']['enabled'];
                    echo 'Educator enabled: ' . ($educator_enabled ? 'Yes' : 'No') . ' | ';
                    
                    if ($educator_enabled) {
                        echo 'Base discount: ' . (isset($settings['enabled_roles']['educator']['base_discount']) ? $settings['enabled_roles']['educator']['base_discount'] : '0') . '%';
                    }
                } else {
                    echo 'Not an Educator user';
                }
                
                echo '</p></div>';
            }
        }
    }
    
    /**
     * Plugin activation handler
     */
    public static function activate() {
        // Check for existing installation and handle migration
        $existing_version = get_option('wrcp_version', false);
        $current_version = WRCP_VERSION;
        
        if ($existing_version && version_compare($existing_version, $current_version, '<')) {
            // Handle migration from older version
            self::migrate_plugin_data($existing_version, $current_version);
        }
        
        // Set plugin version
        update_option('wrcp_version', $current_version);
        
        // Initialize default settings
        $default_settings = array(
            'enabled_roles' => array(),
            'custom_roles' => array(),
            'plugin_version' => $current_version,
            'activation_date' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            'last_migration' => $existing_version ? $existing_version : 'fresh_install'
        );
        
        // Only add default settings if they don't exist
        if (!get_option('wrcp_settings')) {
            update_option('wrcp_settings', $default_settings);
        } else {
            // Update existing settings with new version info
            $existing_settings = get_option('wrcp_settings', array());
            $existing_settings['plugin_version'] = $current_version;
            $existing_settings['last_migration'] = $existing_version ? $existing_version : 'fresh_install';
            update_option('wrcp_settings', $existing_settings);
        }
        
        // Create any necessary database tables (none needed for this plugin currently)
        self::create_database_tables();
        
        // Set default capabilities for existing roles if needed
        self::setup_default_capabilities();
        
        // Clear any cached data
        wp_cache_flush();
        
        // Set activation flag for admin notices
        if (function_exists('set_transient')) {
            set_transient('wrcp_activation_notice', true, 30);
        }
    }
    
    /**
     * Plugin deactivation handler
     */
    public static function deactivate() {
        // Clear any cached data
        wp_cache_flush();
        
        // Clear scheduled events if any
        wp_clear_scheduled_hook('wrcp_cleanup_transients');
        
        // Clear transients
        self::clear_plugin_transients();
        
        // Remove temporary admin notices
        if (function_exists('delete_transient')) {
            delete_transient('wrcp_activation_notice');
            delete_transient('wrcp_wwp_was_active');
        }
        
        // Log deactivation for debugging
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('WRCP plugin deactivated', array('source' => 'wrcp-activation'));
        }
    }
    
    /**
     * Plugin uninstall handler
     */
    public static function uninstall() {
        // Get settings before deletion for cleanup
        $settings = get_option('wrcp_settings', array());
        
        // Remove custom roles created by the plugin
        if (isset($settings['custom_roles'])) {
            foreach ($settings['custom_roles'] as $role_key => $role_data) {
                if (isset($role_data['created_by_plugin']) && $role_data['created_by_plugin']) {
                    remove_role($role_key);
                }
            }
        }
        
        // Remove all plugin options
        delete_option('wrcp_settings');
        delete_option('wrcp_version');
        delete_option('wrcp_dependency_errors');
        
        // Remove any backup settings
        delete_option('wrcp_settings_backup');
        delete_option('wrcp_import_validation_errors');
        
        // Clear all transients
        self::clear_all_plugin_transients();
        
        // Remove any scheduled events
        wp_clear_scheduled_hook('wrcp_cleanup_transients');
        wp_clear_scheduled_hook('wrcp_daily_maintenance');
        
        // Drop any custom database tables (none currently used)
        self::drop_database_tables();
        
        // Clear cache
        wp_cache_flush();
        
        // Log uninstall for debugging
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('WRCP plugin uninstalled and data cleaned up', array('source' => 'wrcp-activation'));
        }
    }
    
    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version() {
        return $this->version;
    }
    
    /**
     * Check if WooCommerce Wholesale Prices plugin is active
     *
     * @return bool
     */
    public function is_wwp_active() {
        return class_exists('WooCommerceWholeSalePrices');
    }
    
    /**
     * Get WWP plugin version if active
     *
     * @return string|false WWP version or false if not active
     */
    public function get_wwp_version() {
        if (!$this->is_wwp_active()) {
            return false;
        }
        
        // Try multiple version detection methods
        
        // Method 1: Check for WWP_VERSION constant
        if (defined('WWP_VERSION')) {
            return WWP_VERSION;
        }
        
        // Method 2: Check for WWPP_VERSION constant (alternative)
        if (defined('WWPP_VERSION')) {
            return WWPP_VERSION;
        }
        
        // Method 3: Try to get version from plugin data
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Try multiple possible plugin paths
        $possible_paths = array(
            WP_PLUGIN_DIR . '/woocommerce-wholesale-prices/woocommerce-wholesale-prices.php',
            WP_PLUGIN_DIR . '/woocommerce-wholesale-prices-premium/woocommerce-wholesale-prices-premium.php',
            WP_PLUGIN_DIR . '/wholesale-prices/wholesale-prices.php'
        );
        
        foreach ($possible_paths as $plugin_file) {
            if (file_exists($plugin_file)) {
                $plugin_data = get_plugin_data($plugin_file);
                if (!empty($plugin_data['Version'])) {
                    return $plugin_data['Version'];
                }
            }
        }
        
        // Method 4: If we can't detect version but WWP is active, return a default
        return '1.0.0'; // Assume compatible version
    }
    
    /**
     * Check WWP version compatibility
     *
     * @param string $min_version Minimum required version
     * @return bool True if compatible
     */
    public function is_wwp_version_compatible($min_version = '1.0.0') {
        $wwp_version = $this->get_wwp_version();
        
        if (!$wwp_version) {
            return false;
        }
        
        return version_compare($wwp_version, $min_version, '>=');
    }
    
    /**
     * Check if WWP wholesale roles class is available
     *
     * @return bool True if WWP_Wholesale_Roles class exists
     */
    public function has_wwp_roles_class() {
        return class_exists('WWP_Wholesale_Roles');
    }
    
    /**
     * Get WWP wholesale roles instance if available
     *
     * @return object|false WWP_Wholesale_Roles instance or false
     */
    public function get_wwp_roles_instance() {
        if (!$this->has_wwp_roles_class()) {
            return false;
        }
        
        if (method_exists('WWP_Wholesale_Roles', 'getInstance')) {
            return WWP_Wholesale_Roles::getInstance();
        }
        
        if (method_exists('WWP_Wholesale_Roles', 'get_instance')) {
            return WWP_Wholesale_Roles::get_instance();
        }
        
        return false;
    }
    
    /**
     * Check if user has WWP wholesale role using WWP methods
     *
     * @param int $user_id User ID to check
     * @return bool|string False if no wholesale role, role key if has wholesale role
     */
    public function get_user_wwp_wholesale_role($user_id = null) {
        if (!$this->is_wwp_active()) {
            return false;
        }
        
        if (empty($user_id)) {
            $user_id = get_current_user_id();
        }
        
        if (empty($user_id)) {
            return false;
        }
        
        $wwp_roles = $this->get_wwp_roles_instance();
        if (!$wwp_roles) {
            return false;
        }
        
        // Try getUserWholesaleRole method (most common)
        if (method_exists($wwp_roles, 'getUserWholesaleRole')) {
            $wholesale_role = $wwp_roles->getUserWholesaleRole($user_id);
            return !empty($wholesale_role) ? $wholesale_role : false;
        }
        
        // Try get_user_wholesale_role method (alternative)
        if (method_exists($wwp_roles, 'get_user_wholesale_role')) {
            $wholesale_role = $wwp_roles->get_user_wholesale_role($user_id);
            return !empty($wholesale_role) ? $wholesale_role : false;
        }
        
        return false;
    }
    
    /**
     * Get all WWP wholesale role keys
     *
     * @return array Array of wholesale role keys
     */
    public function get_wwp_wholesale_role_keys() {
        if (!$this->is_wwp_active()) {
            return array();
        }
        
        $wwp_roles = $this->get_wwp_roles_instance();
        if (!$wwp_roles) {
            return array();
        }
        
        // Try getAllRegisteredWholesaleRoles method
        if (method_exists($wwp_roles, 'getAllRegisteredWholesaleRoles')) {
            $roles = $wwp_roles->getAllRegisteredWholesaleRoles();
            return is_array($roles) ? array_keys($roles) : array();
        }
        
        // Try get_all_wholesale_roles method
        if (method_exists($wwp_roles, 'get_all_wholesale_roles')) {
            $roles = $wwp_roles->get_all_wholesale_roles();
            return is_array($roles) ? array_keys($roles) : array();
        }
        
        // Fallback to common wholesale role names
        return array('wholesale_customer', 'wwp_wholesale_customer');
    }
    
    /**
     * Check if WWP price filter is active for current context
     *
     * @return bool True if WWP is filtering prices
     */
    public function is_wwp_price_filter_active() {
        if (!$this->is_wwp_active()) {
            return false;
        }
        
        // Check if current user has wholesale role
        $wholesale_role = $this->get_user_wwp_wholesale_role();
        if (!$wholesale_role) {
            return false;
        }
        
        // Check multiple possible WWP price filter hooks
        $wwp_filter_classes = array(
            'WWP_Wholesale_Price_Wholesale_Page',
            'WWP_Wholesale_Prices',
            'WooCommerceWholeSalePrices'
        );
        
        foreach ($wwp_filter_classes as $class_name) {
            if (has_filter('woocommerce_get_price_html', array($class_name, 'wholesale_price_html_filter'))) {
                return true;
            }
        }
        
        // Check for any WWP-related filters on price hooks
        global $wp_filter;
        if (isset($wp_filter['woocommerce_get_price_html'])) {
            foreach ($wp_filter['woocommerce_get_price_html']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if (is_array($callback['function']) && 
                        is_object($callback['function'][0]) && 
                        strpos(get_class($callback['function'][0]), 'WWP') !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get WWP hook priority for price filters
     *
     * @return int Hook priority used by WWP
     */
    public function get_wwp_hook_priority() {
        // WWP typically uses priority 10 for price filters
        return 10;
    }
    
    /**
     * Get recommended WRCP hook priority to run after WWP
     *
     * @return int Recommended priority for WRCP hooks
     */
    public function get_wrcp_hook_priority() {
        if (!$this->is_wwp_active()) {
            return 10; // Default priority when WWP is not active
        }
        
        // Detect actual WWP hook priority dynamically
        $detected_priority = $this->detect_wwp_hook_priority();
        if ($detected_priority !== false) {
            return $detected_priority + 5;
        }
        
        // Fallback: Run after WWP (priority 10) with some buffer
        return $this->get_wwp_hook_priority() + 5;
    }
    
    /**
     * Detect actual WWP hook priority by examining registered filters
     *
     * @return int|false Detected priority or false if not found
     */
    public function detect_wwp_hook_priority() {
        global $wp_filter;
        
        if (!isset($wp_filter['woocommerce_get_price_html'])) {
            return false;
        }
        
        $wwp_classes = array('WWP_Wholesale_Price_Wholesale_Page', 'WWP_Wholesale_Prices', 'WooCommerceWholeSalePrices');
        
        foreach ($wp_filter['woocommerce_get_price_html']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && is_object($callback['function'][0])) {
                    $class_name = get_class($callback['function'][0]);
                    if (in_array($class_name, $wwp_classes) || strpos($class_name, 'WWP') !== false) {
                        return intval($priority);
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check WWP plugin compatibility and version requirements
     *
     * @return array Compatibility status and details
     */
    public function check_wwp_compatibility() {
        $compatibility = array(
            'is_active' => $this->is_wwp_active(),
            'version' => $this->get_wwp_version(),
            'is_compatible' => false,
            'has_roles_class' => $this->has_wwp_roles_class(),
            'detected_priority' => $this->detect_wwp_hook_priority(),
            'wholesale_roles' => $this->get_wwp_wholesale_role_keys(),
            'issues' => array()
        );
        
        if (!$compatibility['is_active']) {
            $compatibility['issues'][] = 'WWP plugin is not active';
            return $compatibility;
        }
        
        // Be more forgiving about version detection
        if ($compatibility['version']) {
            $compatibility['is_compatible'] = $this->is_wwp_version_compatible('1.0.0');
        } else {
            // If we can't detect version but WWP is active, assume it's compatible
            $compatibility['is_compatible'] = true;
            $compatibility['version'] = 'unknown';
        }
        
        // Only report critical issues, not warnings
        if (!$compatibility['has_roles_class']) {
            // This is not critical - we can work without it
        }
        
        if ($compatibility['detected_priority'] === false) {
            // This is not critical - we'll use default priorities
        }
        
        if (empty($compatibility['wholesale_roles'])) {
            // This is not critical - we'll use fallback role detection
        }
        
        return $compatibility;
    }
    

    
    /**
     * Set up Educator role with default WRCP settings for testing
     */
    public function setup_educator_role_for_testing() {
        $settings = get_option('wrcp_settings', array());
        
        // Ensure enabled_roles array exists
        if (!isset($settings['enabled_roles'])) {
            $settings['enabled_roles'] = array();
        }
        
        // Set up Educator role if not already configured
        if (!isset($settings['enabled_roles']['educator'])) {
            $settings['enabled_roles']['educator'] = array(
                'enabled' => true,
                'base_discount' => 10.0, // 10% base discount for testing
                'shipping_methods' => array(),
                'category_discounts' => array()
            );
            
            update_option('wrcp_settings', $settings);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WRCP: Set up Educator role with 10% base discount for testing');
            }
        }
    }
    
    /**
     * Add debug banner to footer (if needed)
     */
    public function add_debug_banner() {
        // This method was being called by WordPress but didn't exist
        // Adding it to prevent the fatal error
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            echo '<!-- WRCP Debug: Plugin loaded successfully -->';
        }
    }
    
    /**
     * Shortcode to display WRCP status for current user
     */
    public function wrcp_status_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>Please log in to see WRCP status.</p>';
        }
        
        $current_user = wp_get_current_user();
        $settings = get_option('wrcp_settings', array());
        
        $output = '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin: 10px 0;">';
        $output .= '<h4>WRCP Status</h4>';
        $output .= '<p><strong>User:</strong> ' . esc_html($current_user->user_login) . '</p>';
        $output .= '<p><strong>Roles:</strong> ' . esc_html(implode(', ', $current_user->roles)) . '</p>';
        
        if (in_array('educator', $current_user->roles)) {
            $educator_config = isset($settings['enabled_roles']['educator']) ? $settings['enabled_roles']['educator'] : null;
            
            if ($educator_config && isset($educator_config['enabled']) && $educator_config['enabled']) {
                $output .= '<p><strong>Educator Role:</strong> ✅ Enabled</p>';
                $output .= '<p><strong>Base Discount:</strong> ' . (isset($educator_config['base_discount']) ? $educator_config['base_discount'] : '0') . '%</p>';
            } else {
                $output .= '<p><strong>Educator Role:</strong> ❌ Not enabled in WRCP</p>';
            }
        }
        
        // Check WWP compatibility
        if (class_exists('WRCP_WWP_Compatibility')) {
            $compatibility = WRCP_WWP_Compatibility::get_instance();
            $should_run = $compatibility->should_wrcp_run();
            $output .= '<p><strong>WRCP Should Run:</strong> ' . ($should_run ? '✅ Yes' : '❌ No') . '</p>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    

    

    
    /**
     * Implement graceful degradation if WWP is deactivated
     *
     * @return bool True if degradation was successful
     */
    public function handle_wwp_deactivation() {
        // Check if WWP was previously active but is now inactive
        $was_wwp_active = get_transient('wrcp_wwp_was_active');
        $is_wwp_active = $this->is_wwp_active();
        
        if ($was_wwp_active && !$is_wwp_active) {
            // WWP was deactivated, implement fallback logic
            $this->log_wwp_status_change('deactivated');
            
            // Reset hook priorities to default
            $this->reset_hook_priorities();
            
            // Clear WWP-related transients
            $this->clear_wwp_transients();
            
            // Update wholesale roles list to fallback values
            $this->update_wholesale_roles_fallback();
            
            delete_transient('wrcp_wwp_was_active');
            return true;
        }
        
        // Update WWP status for next check
        if ($is_wwp_active) {
            set_transient('wrcp_wwp_was_active', true, DAY_IN_SECONDS);
        }
        
        return false;
    }
    
    /**
     * Reset hook priorities to default values
     */
    private function reset_hook_priorities() {
        // Remove existing WRCP hooks
        remove_filter('woocommerce_get_price_html', array('WRCP_Frontend_Display', 'modify_price_html'));
        
        // Re-add with default priority
        if (class_exists('WRCP_Frontend_Display')) {
            $frontend_display = WRCP_Frontend_Display::get_instance();
            add_filter('woocommerce_get_price_html', array($frontend_display, 'modify_price_html'), 10, 2);
        }
    }
    
    /**
     * Clear WWP-related transients and cached data
     */
    private function clear_wwp_transients() {
        global $wpdb;
        
        // Clear WRCP transients related to WWP
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wrcp_wwp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wrcp_wwp_%'");
        
        // Clear specific WWP-related caches
        wp_cache_delete('wrcp_wwp_roles', 'wrcp');
        wp_cache_delete('wrcp_wwp_compatibility', 'wrcp');
    }
    
    /**
     * Update wholesale roles list to fallback values when WWP is deactivated
     */
    private function update_wholesale_roles_fallback() {
        // Use common wholesale role names as fallback
        $fallback_roles = array('wholesale_customer', 'wwp_wholesale_customer');
        
        // Update role manager with fallback roles
        if (class_exists('WRCP_Role_Manager')) {
            $role_manager = WRCP_Role_Manager::get_instance();
            // This will be handled by the role manager's update method
        }
    }
    
    /**
     * Log WWP status changes for debugging
     *
     * @param string $status Status change (activated, deactivated, version_changed)
     */
    private function log_wwp_status_change($status) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info(
                sprintf('WWP status changed: %s', $status),
                array('source' => 'wrcp-wwp-compatibility')
            );
        }
    }
    
    /**
     * Get minimum WooCommerce version requirement
     *
     * @return string
     */
    public function get_min_wc_version() {
        return $this->min_wc_version;
    }
    
    /**
     * Get minimum WordPress version requirement
     *
     * @return string
     */
    public function get_min_wp_version() {
        return $this->min_wp_version;
    }
    
    /**
     * Handle plugin data migration between versions
     *
     * @param string $from_version Previous version
     * @param string $to_version Current version
     */
    private static function migrate_plugin_data($from_version, $to_version) {
        // Log migration start
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info(
                sprintf('Starting WRCP migration from %s to %s', $from_version, $to_version),
                array('source' => 'wrcp-migration')
            );
        }
        
        // Create backup of current settings before migration
        $current_settings = get_option('wrcp_settings', array());
        update_option('wrcp_settings_backup', $current_settings);
        
        // Version-specific migrations
        if (version_compare($from_version, '1.0.0', '<')) {
            self::migrate_to_1_0_0();
        }
        
        // Future version migrations can be added here
        // if (version_compare($from_version, '1.1.0', '<')) {
        //     self::migrate_to_1_1_0();
        // }
        
        // Update migration timestamp
        $current_settings = get_option('wrcp_settings', array());
        $current_settings['last_migration_date'] = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        $current_settings['migration_from_version'] = $from_version;
        update_option('wrcp_settings', $current_settings);
        
        // Log migration completion
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info(
                sprintf('WRCP migration completed from %s to %s', $from_version, $to_version),
                array('source' => 'wrcp-migration')
            );
        }
    }
    
    /**
     * Migration to version 1.0.0
     */
    private static function migrate_to_1_0_0() {
        // This is the initial version, so no migration needed
        // Future versions will implement specific migration logic here
        
        $settings = get_option('wrcp_settings', array());
        
        // Ensure all required keys exist
        $default_keys = array(
            'enabled_roles' => array(),
            'custom_roles' => array(),
            'plugin_version' => '1.0.0'
        );
        
        foreach ($default_keys as $key => $default_value) {
            if (!isset($settings[$key])) {
                $settings[$key] = $default_value;
            }
        }
        
        update_option('wrcp_settings', $settings);
    }
    
    /**
     * Create database tables if needed
     */
    private static function create_database_tables() {
        // Currently, this plugin uses WordPress options for storage
        // No custom tables are needed, but this method is here for future use
        
        global $wpdb;
        
        // Example of how to create tables if needed in the future:
        /*
        $table_name = $wpdb->prefix . 'wrcp_pricing_cache';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            discount_percentage decimal(5,2) NOT NULL,
            calculated_price decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_product (user_id, product_id),
            KEY expires (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        */
    }
    
    /**
     * Drop custom database tables on uninstall
     */
    private static function drop_database_tables() {
        // Currently no custom tables to drop
        // This method is here for future use if custom tables are added
        
        global $wpdb;
        
        // Example of how to drop tables if they exist:
        /*
        $table_name = $wpdb->prefix . 'wrcp_pricing_cache';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        */
    }
    
    /**
     * Setup default capabilities for existing roles
     */
    private static function setup_default_capabilities() {
        // Add any default capabilities needed for the plugin
        // Currently, the plugin uses existing WordPress/WooCommerce capabilities
        
        // Example of adding custom capabilities:
        /*
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_wrcp_settings');
        }
        
        $role = get_role('shop_manager');
        if ($role) {
            $role->add_cap('manage_wrcp_settings');
        }
        */
    }
    
    /**
     * Clear plugin-specific transients
     */
    private static function clear_plugin_transients() {
        global $wpdb;
        
        // Clear WRCP-specific transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wrcp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wrcp_%'");
        
        // Clear specific known transients
        delete_transient('wrcp_wwp_compatibility');
        delete_transient('wrcp_role_cache');
        delete_transient('wrcp_category_cache');
    }
    
    /**
     * Clear all plugin transients including backups
     */
    private static function clear_all_plugin_transients() {
        global $wpdb;
        
        // Clear all WRCP transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wrcp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wrcp_%'");
        
        // Clear site transients as well
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_wrcp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_timeout_wrcp_%'");
    }
    
    /**
     * Check if plugin needs migration
     *
     * @return bool True if migration is needed
     */
    public static function needs_migration() {
        $current_version = WRCP_VERSION;
        $installed_version = get_option('wrcp_version', false);
        
        if (!$installed_version) {
            return false; // Fresh install
        }
        
        return version_compare($installed_version, $current_version, '<');
    }
    
    /**
     * Get plugin installation status
     *
     * @return array Installation status information
     */
    public static function get_installation_status() {
        $installed_version = get_option('wrcp_version', false);
        $settings = get_option('wrcp_settings', array());
        
        return array(
            'is_installed' => !empty($installed_version),
            'installed_version' => $installed_version,
            'current_version' => WRCP_VERSION,
            'needs_migration' => self::needs_migration(),
            'activation_date' => isset($settings['activation_date']) ? $settings['activation_date'] : null,
            'last_migration' => isset($settings['last_migration']) ? $settings['last_migration'] : null,
            'has_settings' => !empty($settings),
            'has_custom_roles' => !empty($settings['custom_roles'])
        );
    }
}