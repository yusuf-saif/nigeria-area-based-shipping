<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_Blocks {

	const FIELD_ID = 'ngabs/area';
	const UPDATE_NAMESPACE = 'ngabs';

	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register_additional_field' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_update_callback' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( __CLASS__, 'register_integration' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	public static function register_additional_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) return;

		woocommerce_register_additional_checkout_field( array(
			'id'       => self::FIELD_ID,
			'label'    => __( 'Area', 'ngabs' ),
			'location' => 'address',
			'type'     => 'select',
			'required' => false,
			'options'  => array(
				array( 'value' => '', 'label' => __( 'Select an area…', 'ngabs' ) ),
			),
		) );

		add_action( 'woocommerce_validate_additional_field', array( __CLASS__, 'validate_additional_field' ), 10, 3 );
	}

	public static function validate_additional_field( $errors, $field_key, $field_value ) {
		if ( $field_key !== self::FIELD_ID ) return;
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) return;

		$country = strtoupper( (string) WC()->customer->get_shipping_country() );
		$state   = strtoupper( (string) WC()->customer->get_shipping_state() );
		if ( $country === '' ) $country = strtoupper( (string) WC()->customer->get_billing_country() );
		if ( $state === '' ) $state = strtoupper( (string) WC()->customer->get_billing_state() );

		if ( $country !== 'NG' || $state === '' ) return;
		if ( ! NGABS_DB::state_has_areas( $state ) ) return;

		if ( trim( (string) $field_value ) === '' ) {
			$errors->add( 'ngabs_area_required', __( 'Please select an Area for delivery.', 'ngabs' ) );
		}
	}

	public static function register_store_api_update_callback() {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) return;

		woocommerce_store_api_register_update_callback( array(
			'namespace' => self::UPDATE_NAMESPACE,
			'callback'  => function ( $data ) {
				if ( ! WC()->session ) return;

				$country = isset( $data['country'] ) ? strtoupper( sanitize_text_field( $data['country'] ) ) : '';
				$state   = isset( $data['state'] ) ? strtoupper( sanitize_text_field( $data['state'] ) ) : '';
				$area    = isset( $data['area'] ) ? sanitize_text_field( $data['area'] ) : '';

				if ( $country !== 'NG' ) {
					WC()->session->set( 'ngabs_state', '' );
					WC()->session->set( 'ngabs_area', '' );
					return;
				}

				WC()->session->set( 'ngabs_state', $state );

				if ( $state === '' || ! NGABS_DB::state_has_areas( $state ) ) {
					WC()->session->set( 'ngabs_area', '' );
					return;
				}

				WC()->session->set( 'ngabs_area', $area );
			},
		) );
	}

	public static function register_integration( $integration_registry ) {
		if ( ! interface_exists( '\\Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) ) return;
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
		$state = strtoupper( sanitize_text_field( (string) $request->get_param( 'state' ) ) );
		$rows  = $state ? NGABS_DB::list_areas( $state ) : array();

		$options = array(
			array( 'value' => '', 'label' => __( 'Select an area…', 'ngabs' ) ),
		);

		foreach ( $rows as $r ) {
			$options[] = array( 'value' => (string) $r['area_name'], 'label' => (string) $r['area_name'] );
		}

		return new WP_REST_Response( array( 'has_areas' => ! empty( $rows ), 'options' => $options ), 200 );
	}
}
