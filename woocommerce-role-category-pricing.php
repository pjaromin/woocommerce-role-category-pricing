<?php
/**
 * Plugin Name: Role & Category Pricing
 * Description: General-purpose WooCommerce role & category-based discounts. Enable existing roles, set % discounts globally or by category. Shows like sale prices.
 * Version: 0.5.1
 * Author: Patrick Jaromin
 * License: GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: role-cat-pricing
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Role_Category_Pricing {
    const OPTION_KEY = 'rolecat_discounts';
    const NONCE_KEY  = 'rolecat_nonce';
    const VERSION    = '0.5.1';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'handle_settings_save' ) );

        add_filter( 'woocommerce_product_get_price', array( $this, 'filter_product_price' ), 9999, 2 );
        add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_product_sale_price' ), 9999, 2 );
        add_filter( 'woocommerce_variation_prices', array( $this, 'filter_variation_prices' ), 9999, 3 );
        add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 9999, 2 );
        add_filter( 'woocommerce_cart_item_price', array( $this, 'filter_cart_item_price_html' ), 9999, 3 );
    }

    public function on_activate() {
        if ( ! get_option( self::OPTION_KEY ) ) {
            $default = array(
                'roles' => array(),
                'all'   => array(),
                'cats'  => array(),
            );
            add_option( self::OPTION_KEY, $default );
        }
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Role Discounts', 'role-cat-pricing' ),
            __( 'Role Discounts', 'role-cat-pricing' ),
            'manage_woocommerce',
            'role-cat-pricing',
            array( $this, 'render_settings_page' )
        );
    }

    private function get_option() {
        $opt = get_option( self::OPTION_KEY );
        if ( ! is_array( $opt ) ) $opt = array();
        $opt += array( 'roles' => array(), 'all' => array(), 'cats' => array() );
        return $opt;
    }

    private function save_option( $opt ) {
        update_option( self::OPTION_KEY, $opt );
    }

    public function handle_settings_save() {
        if ( ! is_admin() ) return;
        if ( empty( $_POST['rolecat_action'] ) || $_POST['rolecat_action'] !== 'save' ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        if ( ! isset( $_POST[ self::NONCE_KEY ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_KEY ], 'rolecat_save' ) ) return;

        $opt = $this->get_option();

        $enabled_roles = array();
        $all_roles = wp_roles()->roles;
        foreach ( $all_roles as $slug => $data ) {
            $enabled_roles[ $slug ] = ! empty( $_POST['roles_enabled'][ $slug ] ) ? 1 : 0;
        }
        $opt['roles'] = $enabled_roles;

        $opt['all'] = array();
        if ( ! empty( $_POST['all_pct'] ) && is_array( $_POST['all_pct'] ) ) {
            foreach ( $_POST['all_pct'] as $role => $pct ) {
                $pct = is_numeric( $pct ) ? max( 0, min( 100, floatval( $pct ) ) ) : 0;
                if ( ! empty( $enabled_roles[ $role ] ) ) {
                    $opt['all'][ $role ] = $pct;
                }
            }
        }

        $opt['cats'] = array();
        if ( ! empty( $_POST['cats'] ) && is_array( $_POST['cats'] ) ) {
            foreach ( $_POST['cats'] as $term_id => $row ) {
                $term_id = intval( $term_id );
                $opt['cats'][ $term_id ] = array();
                foreach ( $row as $role => $pct ) {
                    $pct = is_numeric( $pct ) ? max( 0, min( 100, floatval( $pct ) ) ) : '';
                    if ( $pct !== '' && ! empty( $enabled_roles[ $role ] ) ) {
                        $opt['cats'][ $term_id ][ $role ] = $pct;
                    }
                }
                if ( empty( $opt['cats'][ $term_id ] ) ) unset( $opt['cats'][ $term_id ] );
            }
        }

        $this->save_option( $opt );
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Role Discounts:</strong> Settings saved.</p></div>';
        } );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        $opt = $this->get_option();
        $all_roles = wp_roles()->roles;

        $cats = get_terms( array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $cats ) ) $cats = array();

        echo '<div class="wrap">';
        echo '<h1>Role & Category Discounts</h1>';
        echo '<p>Enable one or more existing WordPress roles to receive role-based discounts. Set default discounts by role, and optionally override by product category. Discounts apply to regular price (not sale).</p>';
        echo '<form method="post">';
        wp_nonce_field( 'rolecat_save', self::NONCE_KEY );
        echo '<input type="hidden" name="rolecat_action" value="save" />';

        echo '<h2>1) Enable roles</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Role</th><th>Enabled</th></tr></thead><tbody>';
        foreach ( $all_roles as $slug => $data ) {
            $checked = ! empty( $opt['roles'][ $slug ] ) ? 'checked' : '';
            echo '<tr>';
            echo '<td>'. esc_html( translate_user_role( $data['name'] ) ) .' <code>'. esc_html( $slug ) .'</code></td>';
            echo '<td><input type="checkbox" name="roles_enabled['. esc_attr( $slug ) .']" value="1" '. $checked .' /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">2) Default discount by role (All Categories)</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Role</th><th>Discount %</th></tr></thead><tbody>';
        foreach ( $all_roles as $slug => $data ) {
            if ( empty( $opt['roles'][ $slug ] ) ) continue;
            $val = isset( $opt['all'][ $slug ] ) ? $opt['all'][ $slug ] : '';
            echo '<tr>';
            echo '<td>'. esc_html( translate_user_role( $data['name'] ) ) .' <code>'. esc_html( $slug ) .'</code></td>';
            echo '<td><input type="number" step="0.01" min="0" max="100" name="all_pct['. esc_attr( $slug ) .']" value="'. esc_attr( $val ) .'" /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">3) Per-category overrides</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Category</th>';
        foreach ( $all_roles as $slug => $data ) {
            if ( empty( $opt['roles'][ $slug ] ) ) continue;
            echo '<th>'. esc_html( translate_user_role( $data['name'] ) ) .'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $cats as $cat ) {
            echo '<tr>';
            echo '<td>'. esc_html( $cat->name ) .' (ID '. intval( $cat->term_id ) .')</td>';
            foreach ( $all_roles as $slug => $data ) {
                if ( empty( $opt['roles'][ $slug ] ) ) continue;
                $val = isset( $opt['cats'][ $cat->term_id ][ $slug ] ) ? $opt['cats'][ $cat->term_id ][ $slug ] : '';
                echo '<td><input type="number" step="0.01" min="0" max="100" name="cats['. intval( $cat->term_id ) .']['. esc_attr( $slug ) .']" value="'. esc_attr( $val ) .'" /></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">Save Settings</button></p>';
        echo '</form>';
        echo '</div>';
    }

    private function get_user_best_discount_for_product( $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) return 0;
        if ( ! is_user_logged_in() ) return 0;

        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        if ( empty( $user_roles ) ) return 0;

        $opt = $this->get_option();
        $enabled_roles = isset( $opt['roles'] ) ? $opt['roles'] : array();
        $best = 0.0;

        $term_ids = array();
        $terms = get_the_terms( $product->get_id(), 'product_cat' );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $t ) {
                $term_ids[] = intval( $t->term_id );
                $anc = get_ancestors( $t->term_id, 'product_cat' );
                foreach ( $anc as $a ) $term_ids[] = intval( $a );
            }
        }
        $term_ids = array_unique( $term_ids );

        foreach ( $user_roles as $role ) {
            if ( empty( $enabled_roles[ $role ] ) ) continue;
            $role_pct = 0.0;
            if ( ! empty( $opt['cats'] ) ) {
                foreach ( (array) $term_ids as $tid ) {
                    if ( isset( $opt['cats'][ $tid ][ $role ] ) ) {
                        $role_pct = max( $role_pct, floatval( $opt['cats'][ $tid ][ $role ] ) );
                    }
                }
            }
            if ( isset( $opt['all'][ $role ] ) ) {
                $role_pct = max( $role_pct, floatval( $opt['all'][ $role ] ) );
            }
            $best = max( $best, $role_pct );
        }

        return $best;
    }

    private function get_effective_price( $product ) {
        $reg = (float) $product->get_regular_price();
        if ( $reg <= 0 ) return array( 'price' => $product->get_price(), 'is_discount' => false );

        $sale = $product->get_sale_price();
        $sale = ( $sale !== '' ) ? (float) $sale : null;

        $pct  = $this->get_user_best_discount_for_product( $product );
        if ( $pct <= 0 ) return array( 'price' => $product->get_price(), 'is_discount' => false );

        $disc_price = $reg * ( 1 - ( $pct / 100 ) );
        $best = ( $sale !== null && $sale > 0 ) ? min( $disc_price, $sale ) : $disc_price;

        return array( 'price' => wc_format_decimal( $best, wc_get_price_decimals() ), 'is_discount' => true );
    }

    public function filter_product_price( $price, $product ) {
        $eff = $this->get_effective_price( $product );
        return $eff['price'];
    }

    public function filter_product_sale_price( $sale_price, $product ) {
        $pct = $this->get_user_best_discount_for_product( $product );
        if ( $pct <= 0 ) return $sale_price;

        $reg = (float) $product->get_regular_price();
        if ( $reg <= 0 ) return $sale_price;

        $disc_price = $reg * ( 1 - ( $pct / 100 ) );
        if ( $sale_price !== '' && $sale_price !== null ) {
            $sale_price = min( (float) $sale_price, $disc_price );
        } else {
            $sale_price = $disc_price;
        }
        return wc_format_decimal( $sale_price, wc_get_price_decimals() );
    }

    public function filter_variation_prices( $prices_array, $product, $include_taxes ) {
        if ( is_admin() && ! wp_doing_ajax() ) return $prices_array;

        foreach ( $prices_array['regular_price'] as $var_id => $reg ) {
            $variation = wc_get_product( $var_id );
            $pct = $this->get_user_best_discount_for_product( $variation );
            if ( $pct > 0 && $reg > 0 ) {
                $disc = floatval( $reg ) * ( 1 - ( $pct / 100 ) );
                $sale = isset( $prices_array['sale_price'][ $var_id ] ) && $prices_array['sale_price'][ $var_id ] !== ''
                    ? floatval( $prices_array['sale_price'][ $var_id ] ) : null;
                $final = ( $sale !== null ) ? min( $disc, $sale ) : $disc;

                $prices_array['price'][ $var_id ] = wc_format_decimal( $final, wc_get_price_decimals() );
                $prices_array['sale_price'][ $var_id ] = wc_format_decimal( $final, wc_get_price_decimals() );
            }
        }
        return $prices_array;
    }

    public function filter_price_html( $price_html, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) return $price_html;

        $pct = $this->get_user_best_discount_for_product( $product );
        if ( $pct <= 0 ) return $price_html;

        $reg = (float) $product->get_regular_price();
        if ( $reg <= 0 ) return $price_html;

        $eff = $this->get_effective_price( $product );
        $final = (float) $eff['price'];

        if ( $final >= $reg ) return $price_html;

        $formatted = wc_format_sale_price(
            wc_get_price_to_display( $product, array( 'price' => $reg ) ),
            wc_get_price_to_display( $product, array( 'price' => $final ) )
        );
        $formatted .= sprintf( ' <small class="rolecat-note">%s -%s%%</small>',
            esc_html__( 'Role discount', 'role-cat-pricing' ),
            esc_html( wc_format_decimal( $pct ) )
        );
        return $formatted;
    }

    public function filter_cart_item_price_html( $price_html, $cart_item, $cart_item_key ) {
        if ( empty( $cart_item['data'] ) || ! is_a( $cart_item['data'], 'WC_Product' ) ) return $price_html;
        $product = $cart_item['data'];
        $pct = $this->get_user_best_discount_for_product( $product );
        if ( $pct <= 0 ) return $price_html;
        return $price_html . ' <small class="rolecat-note">-' . esc_html( wc_format_decimal( $pct ) ) . '%</small>';
    }
}

new Role_Category_Pricing();
