<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Checkout Blocks integration (WooCommerce Store API / Additional Checkout Fields).
 *
 * Key design points:
 * - Blocks checkout does NOT use classic update_checkout AJAX.
 * - We register additional checkout fields (billing + shipping) and keep them dynamic via JS.
 * - JS watches country/state changes, fetches Areas via REST, and pushes extension data.
 * - Update callback stores values in WooCommerce session so the shipping method can price correctly.
 */
class NGABS_Blocks {

	const FIELD_BILLING_ID  = 'ngabs/billing_area';
	const FIELD_SHIPPING_ID = 'ngabs/shipping_area';

	/**
	 * Namespace used in Store API extension data.
	 * JS pushes payload to this namespace and WooCommerce calls our update callback.
	 */
	const UPDATE_NAMESPACE = 'ngabs';

	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register_additional_fields' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_update_callback' ) );

		// Enqueue Block assets via Blocks integration registry.
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );

		// REST route used by Blocks JS to fetch Areas by state.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_additional_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) return;

		// Billing Area
		woocommerce_register_additional_checkout_field( array(
			'id'       => self::FIELD_BILLING_ID,
			'label'    => __( 'Area', 'ngabs' ),
			'location' => 'billing',
			'type'     => 'select',
			'required' => false, // dynamically required via validation when the selected state has areas.
			'options'  => array(
				array( 'value' => '', 'label' => __( 'Select an area…', 'ngabs' ) ),
			),
		) );

		// Shipping Area
		woocommerce_register_additional_checkout_field( array(
			'id'       => self::FIELD_SHIPPING_ID,
			'label'    => __( 'Area', 'ngabs' ),
			'location' => 'shipping',
			'type'     => 'select',
			'required' => false, // dynamically required via validation when the selected state has areas.
			'options'  => array(
				array( 'value' => '', 'label' => __( 'Select an area…', 'ngabs' ) ),
			),
		) );

		add_action( 'woocommerce_validate_additional_field', array( __CLASS__, 'validate_additional_field' ), 10, 3 );
	}

	public static function validate_additional_field( $errors, $field_key, $field_value ) {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) return;

		if ( $field_key === self::FIELD_BILLING_ID ) {
			$country = strtoupper( (string) WC()->customer->get_billing_country() );
			$state   = NGABS_States::normalize_to_code( (string) WC()->customer->get_billing_state() );
			if ( $country === 'NG' && $state && NGABS_DB::state_has_areas( $state ) && trim( (string) $field_value ) === '' ) {
				$errors->add( 'ngabs_billing_area_required', __( 'Please select a Billing Area.', 'ngabs' ) );
			}
			return;
		}

		if ( $field_key === self::FIELD_SHIPPING_ID ) {
			$country = strtoupper( (string) WC()->customer->get_shipping_country() );
			$state   = NGABS_States::normalize_to_code( (string) WC()->customer->get_shipping_state() );

			// If shipping is empty (common when "use same address"), fall back to billing.
			if ( $country === '' ) $country = strtoupper( (string) WC()->customer->get_billing_country() );
			if ( $state === '' ) $state = NGABS_States::normalize_to_code( (string) WC()->customer->get_billing_state() );

			if ( $country === 'NG' && $state && NGABS_DB::state_has_areas( $state ) && trim( (string) $field_value ) === '' ) {
				$errors->add( 'ngabs_shipping_area_required', __( 'Please select an Area for delivery.', 'ngabs' ) );
			}
		}
	}

	public static function register_store_api_update_callback() {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) return;

		woocommerce_store_api_register_update_callback( array(
			'namespace' => self::UPDATE_NAMESPACE,
			'callback'  => function ( $data ) {
				if ( ! WC()->session ) return;

				$country  = isset( $data['country'] ) ? strtoupper( sanitize_text_field( (string) $data['country'] ) ) : '';
				$state_in = isset( $data['state'] ) ? (string) $data['state'] : '';
				$state    = NGABS_States::normalize_to_code( $state_in ) ?: '';

				$billing_area  = isset( $data['billing_area'] ) ? sanitize_text_field( (string) $data['billing_area'] ) : '';
				$shipping_area = isset( $data['shipping_area'] ) ? sanitize_text_field( (string) $data['shipping_area'] ) : '';

				if ( $country !== 'NG' ) {
					WC()->session->set( 'ngabs_state', '' );
					WC()->session->set( 'ngabs_area', '' );
					WC()->session->set( 'ngabs_billing_area', '' );
					WC()->session->set( 'ngabs_shipping_area', '' );
					return;
				}

				WC()->session->set( 'ngabs_state', $state );
				WC()->session->set( 'ngabs_billing_area', $billing_area );
				WC()->session->set( 'ngabs_shipping_area', $shipping_area );

				// Effective area for pricing: shipping first, then billing.
				$effective_area = $shipping_area !== '' ? $shipping_area : $billing_area;

				if ( $state === '' || ! NGABS_DB::state_has_areas( $state ) ) {
					WC()->session->set( 'ngabs_area', '' );
					return;
				}

				WC()->session->set( 'ngabs_area', $effective_area );
			},
		) );
	}

	public static function register_integration( $integration_registry ) {
		if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) return;
		$integration_registry->register( new NGABS_Blocks_Integration() );
	}

	public static function register_rest_routes() {
		register_rest_route( 'ngabs/v1', '/areas', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'rest_get_areas' ),
			'args'                => array(
				'state' => array( 'required' => true, 'type' => 'string' ),
			),
		) );
	}

	public static function rest_get_areas( WP_REST_Request $request ) {
		$state_in = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$state    = NGABS_States::normalize_to_code( $state_in ) ?: '';
		$rows     = $state ? NGABS_DB::list_areas( $state ) : array();

		$options = array(
			array( 'value' => '', 'label' => __( 'Select an area…', 'ngabs' ) ),
		);

		foreach ( $rows as $r ) {
			$options[] = array(
				'value' => (string) $r['area_name'],
				'label' => (string) $r['area_name'],
			);
		}

		return new WP_REST_Response(
			array(
				'has_areas' => ! empty( $rows ),
				'options'   => $options,
			),
			200
		);
	}
}

/**
 * Blocks integration wrapper used to enqueue scripts/styles on block checkout.
 */
class NGABS_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	public function get_name() {
		return 'ngabs';
	}

	public function initialize() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets() {
		// Only enqueue on checkout/cart blocks pages.
		if ( function_exists( 'has_block' ) ) {
			$is_checkout = is_checkout() && has_block( 'woocommerce/checkout', get_post() );
			$is_cart     = is_cart() && has_block( 'woocommerce/cart', get_post() );
			if ( ! $is_checkout && ! $is_cart ) return;
		}

		wp_enqueue_script(
			'ngabs-blocks',
			NGABS_PLUGIN_URL . 'assets/js/ngabs-blocks.js',
			array( 'wp-data', 'wp-api-fetch' ),
			NGABS_VERSION,
			true
		);

		wp_localize_script(
			'ngabs-blocks',
			'ngabsBlocksData',
			array(
				'namespace' => NGABS_Blocks::UPDATE_NAMESPACE,
				'rest_url'  => rest_url( 'ngabs/v1/areas' ),
			)
		);
	}
}
