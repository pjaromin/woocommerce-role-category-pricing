<?php
/**
 * Plugin Name: WooCommerce Role Category Pricing
 * Description: Role-based category-specific pricing discounts, compatible with WooCommerce Wholesale Prices plugin.
 * Version: 1.0.0
 * Author: Patrick Jaromin
 * License: GPL-2.0+
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: woocommerce-role-category-pricing
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WRCP_VERSION', '1.0.0');
define('WRCP_PLUGIN_FILE', __FILE__);
define('WRCP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WRCP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WRCP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load required files
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-bootstrap.php';
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-role-manager.php';
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-admin-settings.php';
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-pricing-engine.php';
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-frontend-display.php';
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-cart-integration.php';
require_once WRCP_PLUGIN_PATH . 'includes/class-wrcp-wwp-compatibility.php';

// Initialize the plugin
function wrcp_init() {
    if (class_exists('WRCP_Bootstrap')) {
        try {
            WRCP_Bootstrap::get_instance();
        } catch (Error $e) {
            error_log('WRCP Error during initialization: ' . $e->getMessage());
        }
    } else {
        error_log('WRCP Error: WRCP_Bootstrap class not found');
    }
}
add_action('plugins_loaded', 'wrcp_init', 10);

// Activation hook
register_activation_hook(__FILE__, 'wrcp_activate');
function wrcp_activate() {
    if (class_exists('WRCP_Bootstrap')) {
        WRCP_Bootstrap::activate();
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wrcp_deactivate');
function wrcp_deactivate() {
    if (class_exists('WRCP_Bootstrap')) {
        WRCP_Bootstrap::deactivate();
    }
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'wrcp_uninstall');
function wrcp_uninstall() {
    if (class_exists('WRCP_Bootstrap')) {
        WRCP_Bootstrap::uninstall();
    }
}