<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_States {

	public static function all() {
		return array(
			'AB' => 'Abia','AD' => 'Adamawa','AK' => 'Akwa Ibom','AN' => 'Anambra','BA' => 'Bauchi','BY' => 'Bayelsa',
			'BE' => 'Benue','BO' => 'Borno','CR' => 'Cross River','DE' => 'Delta','EB' => 'Ebonyi','ED' => 'Edo',
			'EK' => 'Ekiti','EN' => 'Enugu','FC' => 'FCT (Abuja)','GO' => 'Gombe','IM' => 'Imo','JI' => 'Jigawa',
			'KD' => 'Kaduna','KN' => 'Kano','KT' => 'Katsina','KE' => 'Kebbi','KO' => 'Kogi','KW' => 'Kwara',
			'LA' => 'Lagos','NA' => 'Nasarawa','NI' => 'Niger','OG' => 'Ogun','ON' => 'Ondo','OS' => 'Osun',
			'OY' => 'Oyo','PL' => 'Plateau','RI' => 'Rivers','SO' => 'Sokoto','TA' => 'Taraba','YO' => 'Yobe','ZA' => 'Zamfara',
		);
	}

	public static function normalize_to_code( $state_input ) {
		$state_input = is_string( $state_input ) ? trim( $state_input ) : '';
		if ( $state_input === '' ) return null;

		$states = self::all();
		$upper  = strtoupper( $state_input );

		if ( isset( $states[ $upper ] ) ) return $upper;

		$needle = self::normalize_name( $state_input );

		$aliases = array(
			'FCT' => 'FC',
			'ABUJA' => 'FC',
			'FEDERALCAPITALTERRITORY' => 'FC',
			'FEDERALCAPITALTERRITORYABUJA' => 'FC',
		);
		if ( isset( $aliases[ $needle ] ) ) return $aliases[ $needle ];

		foreach ( $states as $code => $label ) {
			if ( $needle === self::normalize_name( $label ) ) return $code;
		}

		return null;
	}

	private static function normalize_name( $name ) {
		$name = strtoupper( (string) $name );
		$name = preg_replace( '/[^A-Z0-9]/', '', $name );
		return $name ?: '';
	}
}
