<?php
if (!defined('ABSPATH')) {
	exit;
}

class NGABS_DB {

	private static $state_table;
	private static $areas_table;

	public static function init() {
		global $wpdb;
		self::$state_table = $wpdb->prefix . 'ngabs_state_fees';
		self::$areas_table = $wpdb->prefix . 'ngabs_areas';
	}

	public static function tables() {
		return array(
			'state' => self::$state_table,
			'areas' => self::$areas_table,
		);
	}

	public static function create_tables() {
		global $wpdb;

		self::init();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// We store fees as INTEGER KOBO to avoid float precision issues.
		// 100 kobo = 1 naira.
		$sql_state = "CREATE TABLE " . self::$state_table . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			country CHAR(2) NOT NULL DEFAULT 'NG',
			state_code VARCHAR(10) NOT NULL,
			default_fee_kobo BIGINT(20) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY country_state (country, state_code)
		) $charset_collate;";

		$sql_areas = "CREATE TABLE " . self::$areas_table . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			state_code VARCHAR(10) NOT NULL,
			area_name VARCHAR(191) NOT NULL,
			area_fee_kobo BIGINT(20) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY state_area (state_code, area_name),
			KEY state_code (state_code)
		) $charset_collate;";

		dbDelta($sql_state);
		dbDelta($sql_areas);
	}

	public static function seed_default_data() {
		// Preload Abuja (FCT) areas. Fee is NULL to force fallback to state default unless admin sets per-area fee.
		$fct_code = 'FC';
		$areas = array('Wuse', 'Maitama', 'Garki', 'Asokoro', 'Jabi', 'Utako', 'Gwarinpa', 'Kubwa', 'Lugbe', 'Apo', 'Jahi', 'Kado', 'Durumi', 'Katampe', 'Life Camp');

		foreach ($areas as $area) {
			self::upsert_area($fct_code, $area, null);
		}
	}

	/* ---------------------------
	 * Price parsing helpers
	 * ---------------------------
	 */

	/**
	 * Parse user-supplied price to integer kobo.
	 * Accepts:
	 *  - "1500"
	 *  - "1500.50"
	 *  - "1,500.50"
	 * Returns int kobo or null if empty.
	 * Returns WP_Error if invalid.
	 */
	public static function parse_price_to_kobo($value) {
		if ($value === null) {
			return null;
		}
		$value = is_string($value) ? trim($value) : $value;

		if ($value === '' || $value === false) {
			return null;
		}

		// Remove commas and spaces.
		$str = is_string($value) ? str_replace(array(',', ' '), '', $value) : (string) $value;

		// Strict numeric validation: digits with optional . and up to 2 decimals.
		if (!preg_match('/^\d+(\.\d{1,2})?$/', $str)) {
			return new WP_Error('ngabs_invalid_price', __('Invalid price. Use a number like 1500 or 1500.50.', 'ngabs'));
		}

		$parts = explode('.', $str);
		$naira = $parts[0];
		$dec = isset($parts[1]) ? $parts[1] : '0';

		if (strlen($dec) === 1) {
			$dec .= '0';
		}
		if (strlen($dec) === 0) {
			$dec = '00';
		}

		// Build kobo as integer without floats.
		$kobo = ((int) $naira * 100) + (int) $dec;
		return $kobo;
	}

	public static function format_kobo_to_naira($kobo) {
		$kobo = (int) $kobo;
		$naira = floor($kobo / 100);
		$dec = abs($kobo % 100);
		return sprintf('%d.%02d', $naira, $dec);
	}

	/* ---------------------------
	 * DAL methods (prepared SQL)
	 * ---------------------------
	 */

	public static function get_state_fee_kobo($state_code) {
		global $wpdb;

		self::init();
		$state_code = strtoupper((string) $state_code);

		$cache_key = 'state_fee_' . $state_code;
		$cached = wp_cache_get($cache_key, 'ngabs');
		if ($cached !== false) {
			return $cached; // may be null or int
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT default_fee_kobo FROM " . self::$state_table . " WHERE country = %s AND state_code = %s",
				'NG',
				$state_code
			),
			ARRAY_A
		);

		$fee = $row ? (int) $row['default_fee_kobo'] : null;
		wp_cache_set($cache_key, $fee, 'ngabs', 300);
		return $fee;
	}

	public static function set_state_fee_kobo($state_code, $fee_kobo) {
		global $wpdb;

		self::init();
		$state_code = strtoupper((string) $state_code);

		// If null, delete the row (treat as "unset").
		if ($fee_kobo === null) {
			$wpdb->delete(
				self::$state_table,
				array('country' => 'NG', 'state_code' => $state_code),
				array('%s', '%s')
			);
			wp_cache_delete('state_fee_' . $state_code, 'ngabs');
			return true;
		}

		$fee_kobo = (int) $fee_kobo;

		// Upsert.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::$state_table . " WHERE country = %s AND state_code = %s",
				'NG',
				$state_code
			)
		);

		if ($existing) {
			$wpdb->update(
				self::$state_table,
				array('default_fee_kobo' => $fee_kobo),
				array('id' => (int) $existing),
				array('%d'),
				array('%d')
			);
		} else {
			$wpdb->insert(
				self::$state_table,
				array(
					'country' => 'NG',
					'state_code' => $state_code,
					'default_fee_kobo' => $fee_kobo,
				),
				array('%s', '%s', '%d')
			);
		}

		wp_cache_delete('state_fee_' . $state_code, 'ngabs');
		return true;
	}

	public static function list_areas($state_code) {
		global $wpdb;

		self::init();
		$state_code = strtoupper((string) $state_code);

		$cache_key = 'areas_' . $state_code;
		$cached = wp_cache_get($cache_key, 'ngabs');
		if ($cached !== false) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, area_name, area_fee_kobo FROM " . self::$areas_table . " WHERE state_code = %s ORDER BY area_name ASC",
				$state_code
			),
			ARRAY_A
		);

		foreach ($rows as &$r) {
			$r['id'] = (int) $r['id'];
			$r['area_fee_kobo'] = ($r['area_fee_kobo'] === null) ? null : (int) $r['area_fee_kobo'];
		}

		wp_cache_set($cache_key, $rows, 'ngabs', 300);
		return $rows;
	}

	public static function state_has_areas($state_code) {
		global $wpdb;

		self::init();
		$state_code = strtoupper((string) $state_code);

		$cache_key = 'has_areas_' . $state_code;
		$cached = wp_cache_get($cache_key, 'ngabs');
		if ($cached !== false) {
			return (bool) $cached;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::$areas_table . " WHERE state_code = %s",
				$state_code
			)
		);

		$has = $count > 0;
		wp_cache_set($cache_key, $has ? 1 : 0, 'ngabs', 300);
		return $has;
	}

	public static function get_area_fee_kobo($state_code, $area_name) {
		global $wpdb;

		self::init();
		$state_code = strtoupper((string) $state_code);
		$area_name = (string) $area_name;

		$cache_key = 'area_fee_' . md5($state_code . '|' . $area_name);
		$cached = wp_cache_get($cache_key, 'ngabs');
		if ($cached !== false) {
			return $cached; // may be null or int
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT area_fee_kobo FROM " . self::$areas_table . " WHERE state_code = %s AND area_name = %s",
				$state_code,
				$area_name
			),
			ARRAY_A
		);

		$fee = $row ? ($row['area_fee_kobo'] === null ? null : (int) $row['area_fee_kobo']) : null;
		wp_cache_set($cache_key, $fee, 'ngabs', 300);
		return $fee;
	}

	public static function upsert_area($state_code, $area_name, $fee_kobo_nullable) {
		global $wpdb;

		self::init();
		$state_code = strtoupper((string) $state_code);
		$area_name = trim((string) $area_name);

		if ($area_name === '') {
			return new WP_Error('ngabs_invalid_area', __('Area name is required.', 'ngabs'));
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::$areas_table . " WHERE state_code = %s AND area_name = %s",
				$state_code,
				$area_name
			)
		);

		$data = array(
			'state_code' => $state_code,
			'area_name'  => $area_name,
			'area_fee_kobo' => ($fee_kobo_nullable === null) ? null : (int) $fee_kobo_nullable,
		);

		if ($existing) {
			$wpdb->update(
				self::$areas_table,
				array('area_fee_kobo' => $data['area_fee_kobo']),
				array('id' => (int) $existing),
				array($data['area_fee_kobo'] === null ? '%s' : '%d'),
				array('%d')
			);
		} else {
			$wpdb->insert(
				self::$areas_table,
				$data,
				array('%s', '%s', $data['area_fee_kobo'] === null ? '%s' : '%d')
			);
		}

		wp_cache_delete('areas_' . $state_code, 'ngabs');
		wp_cache_delete('has_areas_' . $state_code, 'ngabs');
		wp_cache_flush();

		return true;
	}

	public static function delete_area($id) {
		global $wpdb;

		self::init();
		$id = (int) $id;

		$row = $wpdb->get_row(
			$wpdb->prepare("SELECT state_code FROM " . self::$areas_table . " WHERE id = %d", $id),
			ARRAY_A
		);

		$wpdb->delete(self::$areas_table, array('id' => $id), array('%d'));

		if ($row && !empty($row['state_code'])) {
			$state_code = strtoupper($row['state_code']);
			wp_cache_delete('areas_' . $state_code, 'ngabs');
			wp_cache_delete('has_areas_' . $state_code, 'ngabs');
		}

		wp_cache_flush();
		return true;
	}
}
