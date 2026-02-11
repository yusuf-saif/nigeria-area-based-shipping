<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_Checkout {

	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_area_field' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_area_to_session' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_area' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_area_to_order_meta' ), 10, 2 );

		add_action( 'wp_ajax_ngabs_get_areas', array( __CLASS__, 'ajax_get_areas' ) );
		add_action( 'wp_ajax_nopriv_ngabs_get_areas', array( __CLASS__, 'ajax_get_areas' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_assets' ) );
	}

	public static function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

		// If Checkout Block is present, don't enqueue the classic jQuery handler.
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) return;

		wp_enqueue_script(
			'ngabs-checkout-area',
			NGABS_PLUGIN_URL . 'assets/js/checkout-area.js',
			array( 'jquery' ),
			NGABS_VERSION,
			true
		);

		wp_localize_script( 'ngabs-checkout-area', 'NGABS', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'ngabs_checkout_nonce' ),
		) );
	}

	public static function add_area_field( $fields ) {
	$field = array(
		'type'     => 'select',
		'label'    => __( 'Area', 'ngabs' ),
		'required' => false,
		'class'    => array( 'form-row-wide' ),
		'priority' => 85,
		'options'  => array( '' => __( 'Select an area…', 'ngabs' ) ),
		'clear'    => true,
	);

	$fields['billing']['billing_ngabs_area']   = $field;
	$fields['shipping']['shipping_ngabs_area'] = $field;

	return $fields;
}

	public static function capture_area_to_session( $posted_data ) {
	if ( ! WC()->session ) return;

	parse_str( (string) $posted_data, $data );

	$billing_country  = isset( $data['billing_country'] ) ? strtoupper( sanitize_text_field( $data['billing_country'] ) ) : '';
	$billing_state_in = isset( $data['billing_state'] ) ? (string) $data['billing_state'] : '';
	$billing_state    = NGABS_States::normalize_to_code( $billing_state_in ) ?: '';
	$billing_area     = isset( $data['billing_ngabs_area'] ) ? sanitize_text_field( (string) $data['billing_ngabs_area'] ) : '';

	$shipping_country  = isset( $data['shipping_country'] ) ? strtoupper( sanitize_text_field( $data['shipping_country'] ) ) : '';
	$shipping_state_in = isset( $data['shipping_state'] ) ? (string) $data['shipping_state'] : '';
	$shipping_state    = NGABS_States::normalize_to_code( $shipping_state_in ) ?: '';
	$shipping_area     = isset( $data['shipping_ngabs_area'] ) ? sanitize_text_field( (string) $data['shipping_ngabs_area'] ) : '';

	$ship_to_diff = ! empty( $data['ship_to_different_address'] );

	WC()->session->set( 'ngabs_billing_state',  $billing_country === 'NG' ? $billing_state : '' );
	WC()->session->set( 'ngabs_billing_area',   $billing_country === 'NG' ? $billing_area  : '' );
	WC()->session->set( 'ngabs_shipping_state', $shipping_country === 'NG' ? $shipping_state : '' );
	WC()->session->set( 'ngabs_shipping_area',  $shipping_country === 'NG' ? $shipping_area  : '' );

	$effective_country = $ship_to_diff ? $shipping_country : $billing_country;
	$effective_state   = $ship_to_diff ? $shipping_state   : $billing_state;
	$effective_area    = $ship_to_diff ? $shipping_area    : $billing_area;

	if ( $effective_country !== 'NG' ) {
		WC()->session->set( 'ngabs_state', '' );
		WC()->session->set( 'ngabs_area', '' );
		return;
	}

	WC()->session->set( 'ngabs_state', $effective_state );

	if ( $effective_state && NGABS_DB::state_has_areas( $effective_state ) ) {
		WC()->session->set( 'ngabs_area', $effective_area );
	} else {
		WC()->session->set( 'ngabs_area', '' );
	}
}

	public static function validate_area( $data, $errors ) {
	$ship_to_diff = ! empty( $_POST['ship_to_different_address'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	$effective_country  = $ship_to_diff ? ( $_POST['shipping_country'] ?? '' ) : ( $_POST['billing_country'] ?? '' ); // phpcs:ignore
	$effective_state_in = $ship_to_diff ? ( $_POST['shipping_state'] ?? '' ) : ( $_POST['billing_state'] ?? '' ); // phpcs:ignore
	$effective_area     = $ship_to_diff ? ( $_POST['shipping_ngabs_area'] ?? '' ) : ( $_POST['billing_ngabs_area'] ?? '' ); // phpcs:ignore

	$country = strtoupper( sanitize_text_field( (string) $effective_country ) );
	$state   = NGABS_States::normalize_to_code( (string) $effective_state_in );

	if ( $country === 'NG' && $state && NGABS_DB::state_has_areas( $state ) ) {
		if ( trim( sanitize_text_field( (string) $effective_area ) ) === '' ) {
			$errors->add( 'ngabs_area_required', __( 'Please select an Area for delivery.', 'ngabs' ) );
		}
	}

	// Billing must also be required when billing state has areas.
	$billing_country = strtoupper( sanitize_text_field( (string) ( $_POST['billing_country'] ?? '' ) ) ); // phpcs:ignore
	$billing_state   = NGABS_States::normalize_to_code( (string) ( $_POST['billing_state'] ?? '' ) ); // phpcs:ignore
	$billing_area    = sanitize_text_field( (string) ( $_POST['billing_ngabs_area'] ?? '' ) ); // phpcs:ignore

	if ( $billing_country === 'NG' && $billing_state && NGABS_DB::state_has_areas( $billing_state ) && trim( $billing_area ) === '' ) {
		$errors->add( 'ngabs_billing_area_required', __( 'Please select a Billing Area.', 'ngabs' ) );
	}
}

	public static function save_area_to_order_meta( $order, $data ) {
	$billing_area  = isset( $_POST['billing_ngabs_area'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['billing_ngabs_area'] ) ) : ''; // phpcs:ignore
	$shipping_area = isset( $_POST['shipping_ngabs_area'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['shipping_ngabs_area'] ) ) : ''; // phpcs:ignore

	$billing_state_in  = isset( $_POST['billing_state'] ) ? (string) wp_unslash( $_POST['billing_state'] ) : ''; // phpcs:ignore
	$shipping_state_in = isset( $_POST['shipping_state'] ) ? (string) wp_unslash( $_POST['shipping_state'] ) : ''; // phpcs:ignore

	$billing_state  = NGABS_States::normalize_to_code( $billing_state_in ) ?: '';
	$shipping_state = NGABS_States::normalize_to_code( $shipping_state_in ) ?: '';

	if ( $billing_state )  $order->update_meta_data( '_ngabs_billing_state', $billing_state );
	if ( $billing_area )   $order->update_meta_data( '_ngabs_billing_area', $billing_area );
	if ( $shipping_state ) $order->update_meta_data( '_ngabs_shipping_state', $shipping_state );
	if ( $shipping_area )  $order->update_meta_data( '_ngabs_shipping_area', $shipping_area );

	// Back-compat: effective shipping pricing selection.
	if ( WC()->session ) {
		$eff_state = (string) WC()->session->get( 'ngabs_state', '' );
		$eff_area  = (string) WC()->session->get( 'ngabs_area', '' );
		if ( $eff_state ) $order->update_meta_data( '_ngabs_state', $eff_state );
		if ( $eff_area )  $order->update_meta_data( '_ngabs_area', $eff_area );
	}
}

	public static function ajax_get_areas() {
		check_ajax_referer( 'ngabs_checkout_nonce', 'nonce' );

		$country = isset( $_POST['country'] ) ? strtoupper( wc_clean( wp_unslash( $_POST['country'] ) ) ) : '';
		$state   = isset( $_POST['state'] ) ? strtoupper( wc_clean( wp_unslash( $_POST['state'] ) ) ) : '';

		if ( $country !== 'NG' || $state === '' ) {
			wp_send_json_success( array( 'has_areas' => false, 'options' => array() ) );
		}

		$rows = NGABS_DB::list_areas( $state );
		$options = array();

		foreach ( $rows as $r ) {
			$options[] = array(
				'value' => (string) $r['area_name'],
				'label' => (string) $r['area_name'],
			);
		}

		wp_send_json_success( array( 'has_areas' => ! empty( $options ), 'options' => $options ) );
	}
}
