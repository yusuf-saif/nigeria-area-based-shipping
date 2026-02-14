<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

if ( ! class_exists( 'NGABS_Blocks_Integration' ) ) {

class NGABS_Blocks_Integration implements IntegrationInterface {

	public function get_name() {
		return 'ngabs';
	}

	public function initialize() {
		wp_register_script(
			'ngabs-blocks',
			NGABS_PLUGIN_URL . 'assets/js/ngabs-blocks.js',
			array( 'wc-blocks-checkout', 'wp-data', 'wp-api-fetch' ),
			NGABS_VERSION,
			true
		);

		wp_localize_script( 'ngabs-blocks', 'ngabsBlocksData', array(
			'rest_url'  => esc_url_raw( rest_url( 'ngabs/v1/areas' ) ),
			'namespace' => NGABS_Blocks::UPDATE_NAMESPACE,
		) );
	}

	public function get_script_handles() {
		return array( 'ngabs-blocks' );
	}

	public function get_editor_script_handles() {
		return array();
	}

	public function get_script_data() {
		return array();
	}
}

}