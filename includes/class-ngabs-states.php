<?php
if (!defined('ABSPATH')) {
	exit;
}

class NGABS_States {

	/**
	 * Nigeria state codes as typically used by WooCommerce for NG.
	 * (WooCommerce uses ISO-ish subdivision codes; FC is commonly used for FCT.)
	 */
	public static function all() {
		return array(
			'AB' => 'Abia',
			'AD' => 'Adamawa',
			'AK' => 'Akwa Ibom',
			'AN' => 'Anambra',
			'BA' => 'Bauchi',
			'BY' => 'Bayelsa',
			'BE' => 'Benue',
			'BO' => 'Borno',
			'CR' => 'Cross River',
			'DE' => 'Delta',
			'EB' => 'Ebonyi',
			'ED' => 'Edo',
			'EK' => 'Ekiti',
			'EN' => 'Enugu',
			'FC' => 'FCT (Abuja)',
			'GO' => 'Gombe',
			'IM' => 'Imo',
			'JI' => 'Jigawa',
			'KD' => 'Kaduna',
			'KN' => 'Kano',
			'KT' => 'Katsina',
			'KE' => 'Kebbi',
			'KO' => 'Kogi',
			'KW' => 'Kwara',
			'LA' => 'Lagos',
			'NA' => 'Nasarawa',
			'NI' => 'Niger',
			'OG' => 'Ogun',
			'ON' => 'Ondo',
			'OS' => 'Osun',
			'OY' => 'Oyo',
			'PL' => 'Plateau',
			'RI' => 'Rivers',
			'SO' => 'Sokoto',
			'TA' => 'Taraba',
			'YO' => 'Yobe',
			'ZA' => 'Zamfara',
		);
	}

	/**
	 * Normalize a state input (code or common name) to a valid state code.
	 * Used heavily in CSV import and admin inputs.
	 */
	public static function normalize_to_code($state_input) {
		$state_input = is_string($state_input) ? trim($state_input) : '';
		if ($state_input === '') {
			return null;
		}

		$state_input_upper = strtoupper($state_input);
		$states = self::all();

		// If already a valid code.
		if (isset($states[$state_input_upper])) {
			return $state_input_upper;
		}

		// Normalize name.
		$needle = self::normalize_name($state_input);

		// Map common variants.
		$aliases = array(
			'FCT' => 'FC',
			'ABUJA' => 'FC',
			'FEDERALCAPITALTERRITORY' => 'FC',
			'FEDERALCAPITALTERRITORYABUJA' => 'FC',
		);
		if (isset($aliases[$needle])) {
			return $aliases[$needle];
		}

		// Find by label match.
		foreach ($states as $code => $label) {
			$label_norm = self::normalize_name($label);
			if ($needle === $label_norm) {
				return $code;
			}
		}

		return null;
	}

	private static function normalize_name($name) {
		$name = strtoupper((string) $name);
		// Remove non-letters/numbers.
		$name = preg_replace('/[^A-Z0-9]/', '', $name);
		return $name ?: '';
	}
}
