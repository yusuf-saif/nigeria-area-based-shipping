<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id = 'ngabs_shipping';
		$this->instance_id = absint( $instance_id );
		$this->method_title = __( 'Nigeria Area-Based Shipping', 'ngabs' );
		$this->method_description = __( 'Prices shipping by Nigeria state and optional area.', 'ngabs' );
		$this->supports = array( 'shipping-zones', 'instance-settings' );
		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', __( 'Nigeria Shipping', 'ngabs' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'title' => array(
				'title'   => __( 'Method Title', 'ngabs' ),
				'type'    => 'text',
				'default' => __( 'Nigeria Shipping', 'ngabs' ),
			),
			'handling_fee' => array(
				'title'       => __( 'Handling Fee (₦)', 'ngabs' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Optional extra fee added to state/area fee.', 'ngabs' ),
			),
		);
	}

	public function process_admin_options() {
		parent::process_admin_options();

		$raw    = $this->get_option( 'handling_fee', '0' );
		$parsed = NGABS_DB::parse_price_to_kobo( $raw );

		$this->update_option(
			'handling_fee',
			is_wp_error( $parsed ) ? '0' : NGABS_DB::format_kobo_to_naira( (int) ( $parsed ?: 0 ) )
		);
	}

	public function is_available( $package ) {
		if ( ! parent::is_available( $package ) ) return false;

		$country = isset( $package['destination']['country'] ) ? strtoupper( (string) $package['destination']['country'] ) : '';
		return ( 'NG' === $country );
	}

	public function calculate_shipping( $package = array() ) {
		$dest    = isset( $package['destination'] ) ? $package['destination'] : array();
		$country = isset( $dest['country'] ) ? strtoupper( (string) $dest['country'] ) : '';
		$state   = isset( $dest['state'] ) ? strtoupper( (string) $dest['state'] ) : '';

		if ( $country !== 'NG' || $state === '' ) return;

		$area = '';
		if ( WC()->session ) {
			$area = (string) WC()->session->get( 'ngabs_area', '' );
		}

		$fee_kobo = null;

		if ( NGABS_DB::state_has_areas( $state ) && $area !== '' ) {
			$area_fee = NGABS_DB::get_area_fee_kobo( $state, $area );
			if ( $area_fee !== null ) $fee_kobo = (int) $area_fee;
		}

		if ( $fee_kobo === null ) {
			$state_fee = NGABS_DB::get_state_fee_kobo( $state );
			if ( $state_fee !== null ) $fee_kobo = (int) $state_fee;
		}

		// Safest default: no config => no rate (avoid accidental free shipping).
		if ( $fee_kobo === null ) {
			NGABS_Admin::maybe_flag_missing_config_notice( $state );
			return;
		}

		$handling_kobo = 0;
		$parsed = NGABS_DB::parse_price_to_kobo( $this->get_option( 'handling_fee', '0' ) );
		$handling_kobo = is_wp_error( $parsed ) ? 0 : (int) ( $parsed ?: 0 );

		$total = max( 0, (int) $fee_kobo + (int) $handling_kobo );

		$this->add_rate( array(
			'id'    => $this->get_rate_id(),
			'label' => $this->title,
			'cost'  => $total / 100,
		) );
	}
}
