<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout Block integration (WooCommerce Blocks / Store API).
 *
 * We register a single Additional Checkout Field with id "ngabs/area" at location "address".
 * WooCommerce renders address fields in both billing and shipping address sections.
 *
 * When the Area changes, our JS calls wc.blocksCheckout.extensionCartUpdate(), which hits the
 * cart/extensions endpoint and triggers our registered update callback (namespace "ngabs").
 * The callback stores the selection in WooCommerce session so the shipping method can price correctly.
 */
class NGABS_Blocks {

	/** Single field id used in Checkout Block. */
	const FIELD_ID = 'ngabs/area';

	/** Namespace for Store API cart/extensions update callback. */
	const UPDATE_NAMESPACE = 'ngabs';

	/** @var bool */
	private static $fields_registered = false;

	/** @var bool */
	private static $update_callback_registered = false;

	public static function init() {
	// Blocks support is only needed when WooCommerce Blocks are loaded.
	// Hooking here ensures the Additional Checkout Fields API is available, and avoids
	// WP_DEBUG notices that can break Store API JSON responses.
	add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'on_blocks_loaded' ) );

		// If Blocks already loaded (some environments), run immediately.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::on_blocks_loaded();
		}

	// REST route used by Blocks JS to fetch Areas for a given NG state.
	add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

	// Dynamic validation (required only when the selected state has configured areas).
	add_action( 'woocommerce_validate_additional_field', array( __CLASS__, 'validate_additional_field' ), 10, 3 );
}

/**
 * Runs when WooCommerce Blocks are loaded.
 *
 * We register:
 * - the Additional Checkout Field (Area)
 * - the Store API update callback (cart/extensions)
 * - the JS integration script for the Checkout Block
 */
public static function on_blocks_loaded() {
	self::register_additional_fields();
	self::register_store_api_update_callback();

	// Enqueue our frontend Blocks script via the IntegrationInterface.
	add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );
}

	/**
	 * Register Additional Checkout Field for Checkout Block.
	 */
	public static function register_additional_fields() {
		if ( self::$fields_registered ) {
			return;
		}

		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		// Avoid "field already registered" notices (WP_DEBUG would break Store API JSON).
		if ( function_exists( 'woocommerce_get_additional_checkout_fields' ) ) {
			$existing = woocommerce_get_additional_checkout_fields();
			if ( isset( $existing['address'][ self::FIELD_ID ] ) || isset( $existing['contact'][ self::FIELD_ID ] ) || isset( $existing['order'][ self::FIELD_ID ] ) ) {
				self::$fields_registered = true;
				return;
			}
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::FIELD_ID,
				'label'    => __( 'Area', 'ngabs' ),
				'location' => 'address', // Valid: contact, address, order.
				'type'     => 'select',
				'required' => false, // Required conditionally via validate_additional_field().
				'options'  => array( array( 'value' => '', 'label' => __( 'Select an area…', 'ngabs' ) ) ),
			)
		);

		self::$fields_registered = true;
	}

	/**
	 * Validate the field: required only when Country=NG and the selected state has configured areas.
	 */
	public static function validate_additional_field( $errors, $field_key, $field_value ) {
		if ( $field_key !== self::FIELD_ID ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		$country = strtoupper( (string) WC()->customer->get_shipping_country() );
		$state   = (string) WC()->customer->get_shipping_state();

		// If shipping is empty, fall back to billing (common when "use same address").
		if ( $country === '' ) {
			$country = strtoupper( (string) WC()->customer->get_billing_country() );
		}
		if ( $state === '' ) {
			$state = (string) WC()->customer->get_billing_state();
		}

		$state = NGABS_States::normalize_to_code( $state );

		if ( $country === 'NG' && $state && NGABS_DB::state_has_areas( $state ) ) {
			if ( trim( (string) $field_value ) === '' ) {
				$errors->add( 'ngabs_area_required', __( 'Please select an Area for delivery.', 'ngabs' ) );
			}
		}
	}

	/**
	 * Register Store API update callback for cart/extensions endpoint.
	 *
	 * Client-side JS triggers this via wc.blocksCheckout.extensionCartUpdate().
	 */
	public static function register_store_api_update_callback() {
		if ( self::$update_callback_registered ) {
			return;
		}

		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::UPDATE_NAMESPACE,
				'callback'  => array( __CLASS__, 'handle_extension_update' ),
			)
		);

		self::$update_callback_registered = true;
	}

	/**
	 * Store API callback for cart/extensions updates.
	 *
	 * @param array $data Data passed from extensionCartUpdate({ namespace, data })
	 */
	public static function handle_extension_update( $data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$payload  = is_array( $data ) ? $data : array();
		$country  = isset( $payload['country'] ) ? strtoupper( sanitize_text_field( (string) $payload['country'] ) ) : '';
		$state_in = isset( $payload['state'] ) ? (string) $payload['state'] : '';
		$state    = NGABS_States::normalize_to_code( $state_in ) ?: '';
		$area     = isset( $payload['area'] ) ? NGABS_DB::normalize_area_name( sanitize_text_field( (string) $payload['area'] ) ) : '';

		if ( $country !== 'NG' ) {
			WC()->session->set( 'ngabs_state', '' );
			WC()->session->set( 'ngabs_area', '' );
			WC()->session->set( 'ngabs_shipping_area', '' );
			WC()->session->set( 'ngabs_billing_area', '' );
			return;
		}

		WC()->session->set( 'ngabs_state', $state );

		// Blocks field is shared; store in both for completeness.
		WC()->session->set( 'ngabs_shipping_area', $area );
		WC()->session->set( 'ngabs_billing_area', $area );

		if ( ! $state || ! NGABS_DB::state_has_areas( $state ) ) {
			WC()->session->set( 'ngabs_area', '' );
			return;
		}

		WC()->session->set( 'ngabs_area', $area );
	}

	/**
	 * Register the Blocks script integration (enqueue ngabs-blocks.js).
	 */
	public static function register_integration( $integration_registry ) {
		if ( class_exists( 'NGABS_Blocks_Integration' ) ) {
			$integration_registry->register( new NGABS_Blocks_Integration() );
		}
	}

	/**
	 * REST endpoint used by JS to fetch Areas for a state.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'ngabs/v1',
			'/areas',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_get_areas' ),
				'args'                => array(
					'state' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
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
