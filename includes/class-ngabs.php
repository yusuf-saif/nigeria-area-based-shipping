<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Main plugin orchestrator.
 */
class NGABS {

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_wc_required' ) );
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

		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_shipping_method' ) );
	}

	public static function register_shipping_method( $methods ) {
		$methods['ngabs_shipping'] = 'NGABS_Shipping_Method';
		return $methods;
	}

	public static function activate() {
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-db.php';
		NGABS_DB::create_tables();

		add_option( 'ngabs_do_activation_redirect', 1, '', false );
	}

	public static function deactivate() {
		// Do not delete data.
	}

	public static function notice_wc_required() {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Nigeria Area-Based Shipping requires WooCommerce to be installed and active.', 'ngabs' ) .
		'</p></div>';
	}
}
