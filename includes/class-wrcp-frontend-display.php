<?php
/**
 * Frontend Display class for WooCommerce Role Category Pricing plugin
 *
 * Handles frontend price display modifications including price HTML filtering,
 * wholesale customer detection, and role-based pricing eligibility checking.
 *
 * @package WRCP
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles frontend price display modifications for WRCP
 */
class WRCP_Frontend_Display {
    
    /**
     * Single instance of the class
     *
     * @var WRCP_Frontend_Display
     */
    private static $instance = null;
    
    /**
     * Role manager instance
     *
     * @var WRCP_Role_Manager
     */
    private $role_manager;
    
    /**
     * Pricing engine instance
     *
     * @var WRCP_Pricing_Engine
     */
    private $pricing_engine;
    
    /**
     * Get single instance of the class
     *
     * @return WRCP_Frontend_Display
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
        $this->role_manager = WRCP_Role_Manager::get_instance();
        $this->pricing_engine = new WRCP_Pricing_Engine();
        
        $this->init();
    }
    
    /**
     * Initialize the frontend display hooks
     */
    private function init() {
        // Get appropriate hook priority based on WWP compatibility
        $hook_priority = $this->get_hook_priority();
        
        // Only add hooks if WooCommerce is fully loaded
        if (function_exists('WC') && class_exists('WooCommerce')) {
            // Hook into WooCommerce price HTML filter with appropriate priority
            add_filter('woocommerce_get_price_html', array($this, 'modify_price_html'), $hook_priority, 2);
            
            // Hook for variable product price display
            add_filter('woocommerce_variable_price_html', array($this, 'modify_variable_price_html'), $hook_priority, 2);
            
            // Hook for variation price display
            add_filter('woocommerce_variation_price_html', array($this, 'modify_variation_price_html'), $hook_priority, 2);
            
            // Add a very late hook to override WWP pricing for non-wholesale roles
            add_filter('woocommerce_get_price_html', array($this, 'override_wwp_pricing'), 999, 2);
        }
        
        // Hook for variation data to include WRCP pricing
        add_filter('woocommerce_available_variation', array($this, 'add_variation_price_data'), 10, 3);
        
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // AJAX handlers for dynamic pricing
        add_action('wp_ajax_wrcp_get_variation_price', array($this, 'ajax_get_variation_price'));
        add_action('wp_ajax_nopriv_wrcp_get_variation_price', array($this, 'ajax_get_variation_price'));
        
        // Output dynamic script for variable products
        add_action('woocommerce_single_product_summary', array($this, 'output_variable_product_script'), 25);
    }
    
    /**
     * Get appropriate hook priority for WWP compatibility
     *
     * @return int Hook priority
     */
    private function get_hook_priority() {
        $bootstrap = WRCP_Bootstrap::get_instance();
        
        // Handle WWP deactivation gracefully
        $bootstrap->handle_wwp_deactivation();
        
        if ($bootstrap->is_wwp_active()) {
            // Use a much higher priority to ensure WRCP runs after WWP
            $priority = $bootstrap->get_wrcp_hook_priority();
            
            // Force a minimum priority of 50 to ensure we run after WWP
            $priority = max($priority, 50);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WRCP: Using hook priority ' . $priority . ' for WWP compatibility');
            }
            
            return $priority;
        }
        
        // Default priority if WWP is not active
        return 10;
    }
    
    /**
     * Main price HTML modification filter
     *
     * @param string $price_html Original price HTML
     * @param WC_Product $product Product object
     * @return string Modified price HTML
     */
    public function modify_price_html($price_html, $product) {
        // Skip if not a valid product
        if (!$product || !is_a($product, 'WC_Product')) {
            return $price_html;
        }
        
        // Skip if user doesn't qualify for WRCP pricing
        if (!$this->should_modify_pricing()) {
            return $price_html;
        }
        
        // Get current user's applicable roles
        $user_roles = $this->get_current_user_applicable_roles();
        if (empty($user_roles)) {
            return $price_html;
        }
        
        // Handle different product types
        if ($product->is_type('variable')) {
            return $this->handle_variable_product_pricing($product, $user_roles, $price_html);
        } else {
            return $this->handle_simple_product_pricing($product, $user_roles, $price_html);
        }
    }
    
    /**
     * Modify variable product price HTML
     *
     * @param string $price_html Original price HTML
     * @param WC_Product_Variable $product Variable product object
     * @return string Modified price HTML
     */
    public function modify_variable_price_html($price_html, $product) {
        return $this->modify_price_html($price_html, $product);
    }
    
    /**
     * Modify variation price HTML
     *
     * @param string $price_html Original price HTML
     * @param WC_Product_Variation $product Variation product object
     * @return string Modified price HTML
     */
    public function modify_variation_price_html($price_html, $product) {
        return $this->modify_price_html($price_html, $product);
    }
    
    /**
     * Check if pricing should be modified for current user
     *
     * @return bool True if pricing should be modified
     */
    public function should_modify_pricing() {
        // Skip if user is not logged in
        if (!is_user_logged_in()) {
            return false;
        }
        
        $current_user_id = get_current_user_id();
        
        // Use WWP compatibility layer to determine if WRCP should run
        if (class_exists('WRCP_WWP_Compatibility')) {
            $compatibility = WRCP_WWP_Compatibility::get_instance();
            if (!$compatibility->should_wrcp_run()) {
                return false;
            }
        }
        
        // Check if user has any WRCP-enabled roles
        $applicable_roles = $this->get_current_user_applicable_roles();
        
        // Apply compatibility filter
        $should_modify = !empty($applicable_roles);
        return apply_filters('wrcp_should_modify_pricing', $should_modify, $current_user_id);
    }
    
    /**
     * Override WWP pricing for non-wholesale roles (very late hook)
     *
     * @param string $price_html Price HTML from WWP
     * @param WC_Product $product Product object
     * @return string Modified price HTML
     */
    public function override_wwp_pricing($price_html, $product) {
        // Add debug info for logged-in users
        if (is_user_logged_in() && current_user_can('read')) {
            $debug_info = '';
            $current_user = wp_get_current_user();
            
            $debug_info .= '<!-- WRCP Debug: ';
            $debug_info .= 'User: ' . $current_user->user_login . ' ';
            $debug_info .= 'Roles: ' . implode(', ', $current_user->roles) . ' ';
            $debug_info .= 'Should modify: ' . ($this->should_modify_pricing() ? 'Yes' : 'No') . ' ';
            
            $user_roles = $this->get_current_user_applicable_roles();
            $debug_info .= 'Applicable roles: ' . implode(', ', $user_roles) . ' ';
            
            if (!empty($user_roles)) {
                $discount = $this->pricing_engine->calculate_discount($product->get_id(), $user_roles);
                $debug_info .= 'Discount: ' . $discount . '% ';
            }
            
            $debug_info .= '-->';
            
            // Add debug info to price HTML
            $price_html = $debug_info . $price_html;
        }
        
        // Only run if we should modify pricing
        if (!$this->should_modify_pricing()) {
            return $price_html;
        }
        
        // Get current user's applicable roles
        $user_roles = $this->get_current_user_applicable_roles();
        if (empty($user_roles)) {
            return $price_html;
        }
        
        // Calculate WRCP pricing
        $original_price = $product->get_price();
        if (!$original_price) {
            return $price_html;
        }
        
        $discount_percentage = $this->pricing_engine->calculate_discount($product->get_id(), $user_roles);
        
        if ($discount_percentage > 0) {
            $discounted_price = $this->pricing_engine->apply_discount_to_price($original_price, $discount_percentage);
            
            // Create WRCP price HTML
            $role_name = ucwords(str_replace('_', ' ', $user_roles[0]));
            $wrcp_price_html = sprintf(
                '<del>%s</del> <ins>%s</ins><br><small>%s Price</small>',
                wc_price($original_price),
                wc_price($discounted_price),
                esc_html($role_name)
            );
            
            return $wrcp_price_html;
        }
        
        return $price_html;
    }
    
    /**
     * Check if user is a wholesale customer (WWP compatibility)
     *
     * @param int $user_id User ID
     * @return bool True if user is wholesale customer
     */
    private function is_wholesale_customer($user_id) {
        // Use role manager's wholesale customer detection
        return $this->role_manager->is_wholesale_customer($user_id);
    }
    
    /**
     * Get current user's applicable roles for WRCP
     *
     * @return array Array of role keys
     */
    private function get_current_user_applicable_roles() {
        if (!is_user_logged_in()) {
            return array();
        }
        
        $current_user_id = get_current_user_id();
        return $this->role_manager->get_user_applicable_roles($current_user_id);
    }
    
    /**
     * Handle simple product pricing display
     *
     * @param WC_Product $product Product object
     * @param array $user_roles User roles
     * @param string $original_price_html Original price HTML
     * @return string Modified price HTML
     */
    private function handle_simple_product_pricing($product, $user_roles, $original_price_html) {
        // Get original price
        $original_price = $product->get_price();
        if (empty($original_price) || $original_price <= 0) {
            return $original_price_html;
        }
        
        // Calculate discounted price
        $discounted_price = $this->pricing_engine->calculate_discounted_price(
            $product->get_id(),
            $user_roles,
            $original_price
        );
        
        // If no discount applied, return original
        if ($discounted_price >= $original_price) {
            return $original_price_html;
        }
        
        // Get role name for display
        $role_name = $this->get_primary_role_name($user_roles);
        
        // Format role-specific price HTML
        return $this->format_role_price_html($original_price, $discounted_price, $role_name);
    }
    
    /**
     * Handle variable product pricing display
     *
     * @param WC_Product_Variable $product Variable product object
     * @param array $user_roles User roles
     * @param string $original_price_html Original price HTML
     * @return string Modified price HTML
     */
    private function handle_variable_product_pricing($product, $user_roles, $original_price_html) {
        // Calculate discounted price range
        $price_range = $this->pricing_engine->calculate_variable_product_price_range($product, $user_roles);
        
        if (empty($price_range['min']) && empty($price_range['max'])) {
            return $original_price_html;
        }
        
        // Get original price range
        $original_min = $product->get_variation_price('min');
        $original_max = $product->get_variation_price('max');
        
        // Check if any discount is applied
        if ($price_range['min'] >= $original_min && $price_range['max'] >= $original_max) {
            return $original_price_html;
        }
        
        // Get role name for display
        $role_name = $this->get_primary_role_name($user_roles);
        
        // Format variable product price HTML
        return $this->format_variable_product_price_html(
            $original_min,
            $original_max,
            $price_range['min'],
            $price_range['max'],
            $role_name
        );
    }
    
    /**
     * Format role-specific price HTML for simple products
     *
     * @param float $original_price Original price
     * @param float $discounted_price Discounted price
     * @param string $role_name Role name for display
     * @return string Formatted price HTML
     */
    private function format_role_price_html($original_price, $discounted_price, $role_name) {
        // Validate prices
        if (!is_numeric($original_price) || !is_numeric($discounted_price) || $original_price <= 0) {
            return '';
        }
        
        // If no discount, return empty (let original price show)
        if ($discounted_price >= $original_price) {
            return '';
        }
        
        $original_formatted = wc_price($original_price);
        $discounted_formatted = wc_price($discounted_price);
        $role_display = $this->format_role_display_name($role_name);
        
        // Calculate savings for additional context
        $savings_amount = $original_price - $discounted_price;
        $savings_percentage = ($savings_amount / $original_price) * 100;
        
        return sprintf(
            '<span class="wrcp-price-container" data-role="%s" data-savings="%.2f">
                <del class="wrcp-original-price" aria-label="%s">%s</del> 
                <ins class="wrcp-discounted-price" aria-label="%s">%s</ins>
                <br><small class="wrcp-role-label">%s Price</small>
                <small class="wrcp-savings-info" title="You save %s (%.1f%%)">Save %s</small>
            </span>',
            esc_attr($role_name),
            $savings_percentage,
            esc_attr__('Regular price', 'woocommerce-role-category-pricing'),
            $original_formatted,
            esc_attr(sprintf(__('%s price', 'woocommerce-role-category-pricing'), $role_display)),
            $discounted_formatted,
            $role_display,
            wc_price($savings_amount),
            $savings_percentage,
            wc_price($savings_amount)
        );
    }
    
    /**
     * Format variable product price HTML with role pricing
     *
     * @param float $original_min Original minimum price
     * @param float $original_max Original maximum price
     * @param float $discounted_min Discounted minimum price
     * @param float $discounted_max Discounted maximum price
     * @param string $role_name Role name for display
     * @return string Formatted price HTML
     */
    private function format_variable_product_price_html($original_min, $original_max, $discounted_min, $discounted_max, $role_name) {
        // Validate prices
        if (!is_numeric($original_min) || !is_numeric($original_max) || 
            !is_numeric($discounted_min) || !is_numeric($discounted_max)) {
            return '';
        }
        
        // If no discount, return empty
        if ($discounted_min >= $original_min && $discounted_max >= $original_max) {
            return '';
        }
        
        $role_display = $this->format_role_display_name($role_name);
        
        // Format original price range
        if ($original_min === $original_max) {
            $original_range = wc_price($original_min);
            $original_label = esc_attr__('Regular price', 'woocommerce-role-category-pricing');
        } else {
            $original_range = wc_format_price_range($original_min, $original_max);
            $original_label = esc_attr__('Regular price range', 'woocommerce-role-category-pricing');
        }
        
        // Format discounted price range
        if ($discounted_min === $discounted_max) {
            $discounted_range = wc_price($discounted_min);
            $discounted_label = esc_attr(sprintf(__('%s price', 'woocommerce-role-category-pricing'), $role_display));
        } else {
            $discounted_range = wc_format_price_range($discounted_min, $discounted_max);
            $discounted_label = esc_attr(sprintf(__('%s price range', 'woocommerce-role-category-pricing'), $role_display));
        }
        
        // Calculate average savings for display
        $avg_original = ($original_min + $original_max) / 2;
        $avg_discounted = ($discounted_min + $discounted_max) / 2;
        $avg_savings = $avg_original - $avg_discounted;
        $avg_savings_percentage = ($avg_savings / $avg_original) * 100;
        
        return sprintf(
            '<span class="wrcp-price-container wrcp-variable-price" data-role="%s" data-avg-savings="%.2f">
                <del class="wrcp-original-price" aria-label="%s">%s</del> 
                <ins class="wrcp-discounted-price" aria-label="%s">%s</ins>
                <br><small class="wrcp-role-label">%s Price</small>
                <small class="wrcp-savings-info" title="Average savings: %s (%.1f%%)">Up to %s off</small>
            </span>',
            esc_attr($role_name),
            $avg_savings_percentage,
            $original_label,
            $original_range,
            $discounted_label,
            $discounted_range,
            $role_display,
            wc_price($avg_savings),
            $avg_savings_percentage,
            wc_price($original_max - $discounted_min)
        );
    }
    
    /**
     * Get primary role name for display purposes
     *
     * @param array $user_roles Array of user roles
     * @return string Primary role name
     */
    private function get_primary_role_name($user_roles) {
        if (empty($user_roles)) {
            return 'Member';
        }
        
        // Return the first role (could be enhanced to prioritize certain roles)
        return $user_roles[0];
    }
    
    /**
     * Format role name for display
     *
     * @param string $role_name Raw role name
     * @return string Formatted role display name
     */
    private function format_role_display_name($role_name) {
        if (empty($role_name)) {
            return __('Member', 'woocommerce-role-category-pricing');
        }
        
        // Check if it's a custom role with display name
        $custom_roles = $this->role_manager->get_custom_roles();
        if (isset($custom_roles[$role_name]['display_name'])) {
            return esc_html($custom_roles[$role_name]['display_name']);
        }
        
        // Get WordPress role display name
        global $wp_roles;
        if (isset($wp_roles) && isset($wp_roles->role_names[$role_name])) {
            return esc_html(translate_user_role($wp_roles->role_names[$role_name]));
        }
        
        // Fallback: format the role key
        return esc_html(ucwords(str_replace('_', ' ', $role_name)));
    }
    
    /**
     * Enqueue frontend assets (styles and scripts)
     */
    public function enqueue_frontend_assets() {
        // Only enqueue on WooCommerce pages
        if (!is_woocommerce() && !is_cart() && !is_checkout() && !is_account_page()) {
            return;
        }
        
        // Check if user qualifies for WRCP pricing
        if (!$this->should_modify_pricing()) {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'wrcp-frontend-styles',
            WRCP_PLUGIN_URL . 'assets/css/frontend-styles.css',
            array(),
            WRCP_VERSION
        );
        
        // Enqueue scripts for variable product functionality
        if (is_product()) {
            wp_enqueue_script(
                'wrcp-frontend-scripts',
                WRCP_PLUGIN_URL . 'assets/js/frontend-scripts.js',
                array('jquery', 'wc-add-to-cart-variation'),
                WRCP_VERSION,
                true
            );
            
            // Localize script with AJAX parameters
            wp_localize_script('wrcp-frontend-scripts', 'wrcp_frontend_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wrcp_frontend_nonce'),
                'debug' => defined('WP_DEBUG') && WP_DEBUG
            ));
        }
    }
    
    /**
     * Check if WooCommerce Wholesale Prices plugin is active and user has wholesale role
     *
     * @param int $user_id User ID to check
     * @return bool True if user has wholesale role via WWP
     */
    private function has_wwp_wholesale_role($user_id) {
        $bootstrap = WRCP_Bootstrap::get_instance();
        
        if (!$bootstrap->is_wwp_active()) {
            return false;
        }
        
        $wholesale_role = $bootstrap->get_user_wwp_wholesale_role($user_id);
        return !empty($wholesale_role);
    }
    
    /**
     * Get discount percentage for display purposes
     *
     * @param int $product_id Product ID
     * @param array $user_roles User roles
     * @return float Discount percentage
     */
    public function get_discount_percentage($product_id, $user_roles) {
        return $this->pricing_engine->calculate_discount($product_id, $user_roles);
    }
    
    /**
     * Check if product has role-based pricing
     *
     * @param int $product_id Product ID
     * @param array $user_roles User roles
     * @return bool True if product has role-based pricing
     */
    public function has_role_pricing($product_id, $user_roles) {
        $discount = $this->pricing_engine->calculate_discount($product_id, $user_roles);
        return $discount > 0;
    }
    
    /**
     * Get formatted discount percentage for display
     *
     * @param int $product_id Product ID
     * @param array $user_roles User roles
     * @return string Formatted discount percentage
     */
    public function get_formatted_discount_percentage($product_id, $user_roles) {
        $discount = $this->get_discount_percentage($product_id, $user_roles);
        
        if ($discount <= 0) {
            return '';
        }
        
        return sprintf('-%s%%', number_format($discount, 1));
    }
    
    /**
     * Get consistent price HTML structure for all product types
     *
     * @param string $original_price_html Original price HTML
     * @param string $discounted_price_html Discounted price HTML
     * @param string $role_name Role name
     * @param array $additional_data Additional data for the price container
     * @return string Formatted price HTML
     */
    private function get_consistent_price_structure($original_price_html, $discounted_price_html, $role_name, $additional_data = array()) {
        $role_display = $this->format_role_display_name($role_name);
        
        // Build data attributes
        $data_attrs = array(
            'data-role="' . esc_attr($role_name) . '"'
        );
        
        if (isset($additional_data['savings_percentage'])) {
            $data_attrs[] = 'data-savings="' . esc_attr($additional_data['savings_percentage']) . '"';
        }
        
        // Build additional info
        $additional_info = '';
        if (isset($additional_data['savings_amount']) && isset($additional_data['savings_percentage'])) {
            $additional_info = sprintf(
                '<small class="wrcp-savings-info" title="You save %s (%.1f%%)">Save %s</small>',
                wc_price($additional_data['savings_amount']),
                $additional_data['savings_percentage'],
                wc_price($additional_data['savings_amount'])
            );
        }
        
        return sprintf(
            '<span class="wrcp-price-container" %s>
                <del class="wrcp-original-price" aria-label="%s">%s</del> 
                <ins class="wrcp-discounted-price" aria-label="%s">%s</ins>
                <br><small class="wrcp-role-label">%s Price</small>
                %s
            </span>',
            implode(' ', $data_attrs),
            esc_attr__('Regular price', 'woocommerce-role-category-pricing'),
            $original_price_html,
            esc_attr(sprintf(__('%s price', 'woocommerce-role-category-pricing'), $role_display)),
            $discounted_price_html,
            $role_display,
            $additional_info
        );
    }
    
    /**
     * Apply consistent formatting to price HTML
     *
     * @param WC_Product $product Product object
     * @param array $user_roles User roles
     * @param string $context Context (shop, single, widget, etc.)
     * @return string Formatted price HTML or empty string
     */
    public function apply_consistent_formatting($product, $user_roles, $context = 'shop') {
        if (!$product || empty($user_roles)) {
            return '';
        }
        
        // Handle different product types with consistent formatting
        if ($product->is_type('variable')) {
            return $this->handle_variable_product_pricing($product, $user_roles, '');
        } elseif ($product->is_type('variation')) {
            return $this->handle_variation_pricing($product, $user_roles);
        } else {
            return $this->handle_simple_product_pricing($product, $user_roles, '');
        }
    }
    
    /**
     * Handle variation pricing specifically
     *
     * @param WC_Product_Variation $variation Variation product
     * @param array $user_roles User roles
     * @return string Formatted price HTML
     */
    private function handle_variation_pricing($variation, $user_roles) {
        $original_price = $variation->get_price();
        if (empty($original_price) || $original_price <= 0) {
            return '';
        }
        
        // Use parent product ID for category lookup
        $parent_id = $variation->get_parent_id();
        $discounted_price = $this->pricing_engine->calculate_discounted_price(
            $parent_id,
            $user_roles,
            $original_price
        );
        
        // If no discount applied, return empty
        if ($discounted_price >= $original_price) {
            return '';
        }
        
        $role_name = $this->get_primary_role_name($user_roles);
        return $this->format_role_price_html($original_price, $discounted_price, $role_name);
    }
    
    /**
     * Add WRCP price data to variation data for JavaScript
     *
     * @param array $variation_data Variation data
     * @param WC_Product $product Parent product
     * @param WC_Product_Variation $variation Variation product
     * @return array Modified variation data
     */
    public function add_variation_price_data($variation_data, $product, $variation) {
        // Skip if user doesn't qualify for WRCP pricing
        if (!$this->should_modify_pricing()) {
            return $variation_data;
        }
        
        $user_roles = $this->get_current_user_applicable_roles();
        if (empty($user_roles)) {
            return $variation_data;
        }
        
        // Calculate WRCP pricing for this variation
        $original_price = $variation->get_price();
        if ($original_price <= 0) {
            return $variation_data;
        }
        
        $discounted_price = $this->pricing_engine->calculate_discounted_price(
            $product->get_id(),
            $user_roles,
            $original_price
        );
        
        // If no discount, don't add WRCP data
        if ($discounted_price >= $original_price) {
            return $variation_data;
        }
        
        $role_name = $this->get_primary_role_name($user_roles);
        $role_display = $this->format_role_display_name($role_name);
        
        // Add WRCP price HTML to variation data
        $variation_data['wrcp_price_html'] = $this->format_role_price_html(
            $original_price,
            $discounted_price,
            $role_name
        );
        
        // Add additional WRCP data for JavaScript
        $variation_data['wrcp_data'] = array(
            'has_discount' => true,
            'original_price' => $original_price,
            'discounted_price' => $discounted_price,
            'role' => $role_name,
            'role_display' => $role_display,
            'savings_amount' => $original_price - $discounted_price,
            'savings_percentage' => (($original_price - $discounted_price) / $original_price) * 100
        );
        
        return $variation_data;
    }
    
    /**
     * AJAX handler to get variation price data
     */
    public function ajax_get_variation_price() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wrcp_frontend_nonce')) {
            wp_send_json_error(__('Security check failed.', 'woocommerce-role-category-pricing'));
        }
        
        $variation_id = intval($_POST['variation_id']);
        if (!$variation_id) {
            wp_send_json_error(__('Invalid variation ID.', 'woocommerce-role-category-pricing'));
        }
        
        // Check if user qualifies for WRCP pricing
        if (!$this->should_modify_pricing()) {
            wp_send_json_error(__('User not eligible for role pricing.', 'woocommerce-role-category-pricing'));
        }
        
        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            wp_send_json_error(__('Variation not found.', 'woocommerce-role-category-pricing'));
        }
        
        $user_roles = $this->get_current_user_applicable_roles();
        $original_price = $variation->get_price();
        
        if ($original_price <= 0) {
            wp_send_json_error(__('Invalid variation price.', 'woocommerce-role-category-pricing'));
        }
        
        // Calculate discounted price
        $parent_id = $variation->get_parent_id();
        $discounted_price = $this->pricing_engine->calculate_discounted_price(
            $parent_id,
            $user_roles,
            $original_price
        );
        
        $role_name = $this->get_primary_role_name($user_roles);
        $role_display = $this->format_role_display_name($role_name);
        
        $response_data = array(
            'has_discount' => $discounted_price < $original_price,
            'original_price' => $original_price,
            'discounted_price' => $discounted_price,
            'original_price_html' => wc_price($original_price),
            'discounted_price_html' => wc_price($discounted_price),
            'role' => $role_name,
            'role_display' => $role_display
        );
        
        if ($response_data['has_discount']) {
            $savings_amount = $original_price - $discounted_price;
            $savings_percentage = ($savings_amount / $original_price) * 100;
            
            $response_data['savings_amount'] = $savings_amount;
            $response_data['savings_percentage'] = $savings_percentage;
            $response_data['savings_html'] = sprintf('Save %s', wc_price($savings_amount));
            $response_data['price_html'] = $this->format_role_price_html($original_price, $discounted_price, $role_name);
        }
        
        wp_send_json_success($response_data);
    }
    
    /**
     * Handle dynamic price updates for variable products
     *
     * @param WC_Product_Variable $product Variable product
     * @return string JavaScript for dynamic updates
     */
    public function get_variable_product_script($product) {
        if (!$this->should_modify_pricing()) {
            return '';
        }
        
        $user_roles = $this->get_current_user_applicable_roles();
        if (empty($user_roles)) {
            return '';
        }
        
        // Get all variations with WRCP pricing
        $variations = $product->get_available_variations();
        $wrcp_variations = array();
        
        foreach ($variations as $variation_data) {
            $variation_id = $variation_data['variation_id'];
            $variation = wc_get_product($variation_id);
            
            if (!$variation) {
                continue;
            }
            
            $original_price = $variation->get_price();
            if ($original_price <= 0) {
                continue;
            }
            
            $discounted_price = $this->pricing_engine->calculate_discounted_price(
                $product->get_id(),
                $user_roles,
                $original_price
            );
            
            if ($discounted_price < $original_price) {
                $role_name = $this->get_primary_role_name($user_roles);
                
                $wrcp_variations[$variation_id] = array(
                    'original_price' => $original_price,
                    'discounted_price' => $discounted_price,
                    'price_html' => $this->format_role_price_html($original_price, $discounted_price, $role_name)
                );
            }
        }
        
        if (empty($wrcp_variations)) {
            return '';
        }
        
        // Return JavaScript to handle dynamic updates
        return sprintf(
            '<script type="text/javascript">
                var wrcp_variation_prices = %s;
                jQuery(document).ready(function($) {
                    $("form.variations_form").on("found_variation", function(event, variation) {
                        if (wrcp_variation_prices[variation.variation_id]) {
                            var wrcp_data = wrcp_variation_prices[variation.variation_id];
                            $(this).closest(".product").find(".price").html(wrcp_data.price_html);
                        }
                    });
                });
            </script>',
            wp_json_encode($wrcp_variations)
        );
    }
    
    /**
     * Output dynamic script for variable products on single product pages
     */
    public function output_variable_product_script() {
        global $product;
        
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        
        echo $this->get_variable_product_script($product);
    }
    
    /**
     * Ensure proper price display for each variation
     *
     * @param WC_Product_Variation $variation Variation product
     * @param array $user_roles User roles
     * @return array Variation price data
     */
    public function get_variation_price_data($variation, $user_roles) {
        if (!$variation || !$variation->is_type('variation')) {
            return array();
        }
        
        $original_price = $variation->get_price();
        if ($original_price <= 0) {
            return array();
        }
        
        $parent_id = $variation->get_parent_id();
        $discounted_price = $this->pricing_engine->calculate_discounted_price(
            $parent_id,
            $user_roles,
            $original_price
        );
        
        $role_name = $this->get_primary_role_name($user_roles);
        
        return array(
            'variation_id' => $variation->get_id(),
            'original_price' => $original_price,
            'discounted_price' => $discounted_price,
            'has_discount' => $discounted_price < $original_price,
            'role_name' => $role_name,
            'price_html' => $discounted_price < $original_price 
                ? $this->format_role_price_html($original_price, $discounted_price, $role_name)
                : wc_price($original_price)
        );
    }
    
    /**
     * Override price ranges with discounted values for variable products
     *
     * @param WC_Product_Variable $product Variable product
     * @param array $user_roles User roles
     * @return array Price range data
     */
    public function get_discounted_price_range($product, $user_roles) {
        if (!$product || !$product->is_type('variable')) {
            return array();
        }
        
        $variations = $product->get_available_variations();
        $discounted_prices = array();
        $original_prices = array();
        
        foreach ($variations as $variation_data) {
            $variation = wc_get_product($variation_data['variation_id']);
            if (!$variation) {
                continue;
            }
            
            $original_price = $variation->get_price();
            if ($original_price <= 0) {
                continue;
            }
            
            $discounted_price = $this->pricing_engine->calculate_discounted_price(
                $product->get_id(),
                $user_roles,
                $original_price
            );
            
            $original_prices[] = $original_price;
            $discounted_prices[] = $discounted_price;
        }
        
        if (empty($discounted_prices)) {
            return array();
        }
        
        return array(
            'original_min' => min($original_prices),
            'original_max' => max($original_prices),
            'discounted_min' => min($discounted_prices),
            'discounted_max' => max($discounted_prices),
            'has_discount' => min($discounted_prices) < min($original_prices) || max($discounted_prices) < max($original_prices)
        );
    }
    
    /**
     * Debug method to check pricing eligibility
     *
     * @return array Debug information
     */
    public function debug_pricing_eligibility() {
        if (!current_user_can('manage_options')) {
            return array();
        }
        
        $current_user_id = get_current_user_id();
        
        return array(
            'is_logged_in' => is_user_logged_in(),
            'user_id' => $current_user_id,
            'is_wholesale_customer' => $this->is_wholesale_customer($current_user_id),
            'applicable_roles' => $this->get_current_user_applicable_roles(),
            'should_modify_pricing' => $this->should_modify_pricing(),
            'wwp_active' => class_exists('WooCommerceWholeSalePrices'),
            'has_wwp_role' => $this->has_wwp_wholesale_role($current_user_id)
        );
    }
}