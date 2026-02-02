<?php
/**
 * Plugin Name: Nigeria Area-Based Shipping (WooCommerce)
 * Description: Adds a Nigeria-only WooCommerce shipping method with State + Area pricing (custom tables, admin CRUD, CSV import, instant recalculation).
 * Version: 1.0.0
 * Author: Saifur-Rahman Yusuf
 * Text Domain: ngabs
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NGABS_VERSION', '1.0.0');
define('NGABS_PLUGIN_FILE', __FILE__);
define('NGABS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NGABS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once NGABS_PLUGIN_DIR . 'includes/class-ngabs.php';

register_activation_hook(__FILE__, array('NGABS', 'activate'));
register_deactivation_hook(__FILE__, array('NGABS', 'deactivate'));

add_action('plugins_loaded', array('NGABS', 'init'));
