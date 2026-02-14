<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin orchestrator.
 *
 * Responsible for:
 * - loading required classes
 * - registering WooCommerce hooks/filters
 * - activation tasks (DB tables + seed data)
 */
class NGABS {

	/**
	 * Initialize the plugin when WooCommerce is ready.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-states.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-db.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-shipping-method.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-checkout.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-admin.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-importer.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-blocks.php';
		require_once NGABS_PLUGIN_DIR . 'includes/blocks/class-ngabs-blocks-integration.php';

		NGABS_DB::init();
		NGABS_Admin::init();
		NGABS_Checkout::init();
		NGABS_Blocks::init();

		// Register the shipping method so it can be enabled per Shipping Zone.
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_shipping_method' ) );

		// Include the selected NG State/Area in the package hash so WooCommerce recalculates rates
		// when the customer changes Area (otherwise rates can be served from session cache).
		add_filter( 'woocommerce_shipping_package_hash', array( __CLASS__, 'filter_shipping_package_hash' ), 10, 2 );

		// Hide WooCommerce shipping debug output for customers (it can confuse shoppers).
		add_filter( 'woocommerce_shipping_debug_mode', array( __CLASS__, 'disable_frontend_shipping_debug_mode' ) );
	}

	/**
	 * Add our method to the list of available shipping methods.
	 *
	 * @param array $methods Existing methods keyed by method id.
	 * @return array
	 */
	public static function register_shipping_method( $methods ) {
		$methods['ngabs_shipping'] = 'NGABS_Shipping_Method';
		return $methods;
	}

	/**
	 * Activation hook: create tables + seed data.
	 */
	public static function activate() {
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-db.php';
		NGABS_DB::create_tables();

		// Used to redirect to the setup wizard after activation.
		add_option( 'ngabs_do_activation_redirect', 1, '', false );
	}

	/**
	 * Deactivation hook.
	 *
	 * We intentionally do NOT delete data on deactivation.
	 */
	public static function deactivate() {
		// Do not delete data.
	}

	/**
	 * Disable WooCommerce Shipping Debug Mode output on the frontend.
	 *
	 * Some environments enable this via settings/tools which causes a visible banner like
	 * "Customer matched zone …" on checkout/cart. It's useful for admins, but confusing for shoppers.
	 *
	 * @param bool $enabled Current debug mode state.
	 * @return bool
	 */
	public static function disable_frontend_shipping_debug_mode( $enabled ) {
		return is_admin() ? $enabled : false;
	}

	/**
	 * Filter the shipping package hash to include the selected NGABS State/Area.
	 *
	 * WooCommerce caches rates per package hash; without this, changing Area may not refresh rates.
	 *
	 * @param string $hash    Existing hash.
	 * @param array  $package Shipping package data.
	 * @return string
	 */
	public static function filter_shipping_package_hash( $hash, $package ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return $hash;
		}

		$state = (string) WC()->session->get( 'ngabs_state', '' );
		$area  = (string) WC()->session->get( 'ngabs_area', '' );

		// If there's no NGABS selection, keep the original hash.
		if ( $state === '' && $area === '' ) {
			return $hash;
		}

		// Hash the existing hash + our selection to invalidate cached shipping rates when Area changes.
		return md5( $hash . '|' . $state . '|' . $area );
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public static function notice_wc_required() {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Nigeria Area-Based Shipping requires WooCommerce to be installed and active.', 'ngabs' ) .
		'</p></div>';
	}
}
