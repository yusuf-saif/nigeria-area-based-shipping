<?php
/**
 * Plugin Name: Nigeria Area-Based Shipping (WooCommerce)
 * Description: Nigeria-only WooCommerce shipping method that prices by State + Area, with admin UI, CSV import, setup wizard, and Classic + Checkout Block support.
 * Version:     1.4.0
 * Author:      NGABS Team
 * Text Domain: ngabs
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NGABS_VERSION', '1.4.0' );


/**
 * Load plugin textdomain at init (WP 6.7+ best practice).
 */
function ngabs_load_textdomain() {
	load_plugin_textdomain( 'ngabs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'ngabs_load_textdomain' );
define( 'NGABS_PLUGIN_FILE', __FILE__ );
define( 'NGABS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NGABS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare WooCommerce feature compatibility (HPOS / Custom Order Tables).
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs.php';

register_activation_hook( __FILE__, array( 'NGABS', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NGABS', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'NGABS', 'init' ) );
