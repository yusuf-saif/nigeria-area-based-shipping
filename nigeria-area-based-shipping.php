<?php
/**
 * Plugin Name: Nigeria Area-Based Shipping (WooCommerce)
 * Description: Nigeria-only WooCommerce shipping method that prices by State + Area, with admin UI, CSV import, setup wizard, and Classic + Checkout Block support.
 * Version: 1.6.1
 * Author:      NGABS Team
 * Text Domain: ngabs
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NGABS_VERSION', '1.6.1' );


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
 * Plugin quick links on the Plugins screen.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$settings_url = admin_url( 'admin.php?page=ngabs-nigeria-shipping' );
	$links[]      = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'ngabs' ) . '</a>';
	return $links;
} );


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


/**
 * Initialize the plugin after WooCommerce is fully initialized.
 *
 * This avoids WP 6.7+ notices about loading the WooCommerce textdomain too early
 * and ensures Blocks/Store API functions are available.
 */
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WooCommerce' ) ) {
		add_action( 'woocommerce_init', array( 'NGABS', 'init' ) );
	} else {
		add_action( 'admin_notices', array( 'NGABS', 'notice_wc_required' ) );
	}
}, 20 );
