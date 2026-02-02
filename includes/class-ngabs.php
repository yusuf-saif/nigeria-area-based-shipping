<?php
if (!defined('ABSPATH')) {
	exit;
}

class NGABS {

	/**
	 * Boot plugin after WooCommerce is loaded.
	 */
	public static function init() {
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', array(__CLASS__, 'notice_wc_required'));
			return;
		}

		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-db.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-states.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-shipping-method.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-checkout.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-admin.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-importer.php';

		NGABS_DB::init();
		NGABS_Checkout::init();
		NGABS_Admin::init();

		// Register shipping method with WooCommerce.
		add_filter('woocommerce_shipping_methods', array(__CLASS__, 'register_shipping_method'));
	}

	public static function register_shipping_method($methods) {
		$methods['ngabs_shipping'] = 'NGABS_Shipping_Method';
		return $methods;
	}

	public static function activate() {
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-db.php';
		require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs-states.php';

		NGABS_DB::create_tables();
		NGABS_DB::seed_default_data();
	}

	public static function deactivate() {
		// Do NOT delete data on deactivation by design.
	}

	public static function notice_wc_required() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__('Nigeria Area-Based Shipping requires WooCommerce to be installed and active.', 'ngabs');
		echo '</p></div>';
	}
}
