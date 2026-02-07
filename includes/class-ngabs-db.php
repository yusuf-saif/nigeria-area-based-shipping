<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_DB {

	private static $state_table;
	private static $areas_table;

	public static function init() {
		global $wpdb;
		self::$state_table = $wpdb->prefix . 'ngabs_state_fees';
		self::$areas_table = $wpdb->prefix . 'ngabs_areas';
	}

	private static function cache_ver() {
		$v = (int) get_option( 'ngabs_cache_ver', 1 );
		return max( 1, $v );
	}

	private static function bump_cache_ver() {
		update_option( 'ngabs_cache_ver', self::cache_ver() + 1, false );
	}

	public static function create_tables() {
		global $wpdb;
		self::init();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql_state = "CREATE TABLE " . self::$state_table . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			country CHAR(2) NOT NULL DEFAULT 'NG',
			state_code VARCHAR(10) NOT NULL,
			default_fee_kobo BIGINT(20) NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY country_state (country, state_code)
		) $charset;";

		$sql_areas = "CREATE TABLE " . self::$areas_table . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			state_code VARCHAR(10) NOT NULL,
			area_name VARCHAR(191) NOT NULL,
			area_fee_kobo BIGINT(20) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY state_area (state_code, area_name),
			KEY state_code (state_code)
		) $charset;";

		dbDelta( $sql_state );
		dbDelta( $sql_areas );

		if ( ! get_option( 'ngabs_cache_ver' ) ) {
			add_option( 'ngabs_cache_ver', 1, '', false );
		}
	}

	/** Price parsing (kobo integer) to avoid float rounding. */
	public static function parse_price_to_kobo( $value ) {
		if ( $value === null ) return null;

		$value = is_string( $value ) ? trim( $value ) : (string) $value;
		if ( $value === '' ) return null;

		$str = str_replace( array( ',', ' ' ), '', $value );
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $str ) ) {
			return new WP_Error( 'ngabs_invalid_price', __( 'Invalid price. Use a number like 1500 or 1500.50.', 'ngabs' ) );
		}

		$parts = explode( '.', $str );
		$naira = $parts[0];
		$dec   = isset( $parts[1] ) ? $parts[1] : '0';

		if ( strlen( $dec ) === 1 ) $dec .= '0';
		if ( strlen( $dec ) === 0 ) $dec = '00';

		return ( (int) $naira * 100 ) + (int) $dec;
	}

	public static function format_kobo_to_naira( $kobo ) {
		$kobo  = (int) $kobo;
		$naira = (int) floor( $kobo / 100 );
		$dec   = abs( $kobo % 100 );
		return sprintf( '%d.%02d', $naira, $dec );
	}

	public static function get_state_fee_kobo( $state_code ) {
		global $wpdb;
		self::init();

		$state_code = strtoupper( (string) $state_code );

		$ver = self::cache_ver();
		$key = "state_fee_{$ver}_{$state_code}";
		$cached = wp_cache_get( $key, 'ngabs' );
		if ( $cached !== false ) return $cached;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT default_fee_kobo FROM " . self::$state_table . " WHERE country=%s AND state_code=%s",
				'NG',
				$state_code
			),
			ARRAY_A
		);

		$fee = $row ? (int) $row['default_fee_kobo'] : null;
		wp_cache_set( $key, $fee, 'ngabs', 300 );
		return $fee;
	}

	public static function set_state_fee_kobo( $state_code, $fee_kobo ) {
		global $wpdb;
		self::init();

		$state_code = strtoupper( (string) $state_code );

		if ( $fee_kobo === null ) {
			$wpdb->delete(
				self::$state_table,
				array( 'country' => 'NG', 'state_code' => $state_code ),
				array( '%s', '%s' )
			);
			self::bump_cache_ver();
			return true;
		}

		$fee_kobo = (int) $fee_kobo;

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::$state_table . " WHERE country=%s AND state_code=%s",
				'NG',
				$state_code
			)
		);

		if ( $existing ) {
			$wpdb->update(
				self::$state_table,
				array( 'default_fee_kobo' => $fee_kobo ),
				array( 'id' => (int) $existing ),
				array( '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				self::$state_table,
				array(
					'country'          => 'NG',
					'state_code'       => $state_code,
					'default_fee_kobo' => $fee_kobo,
				),
				array( '%s', '%s', '%d' )
			);
		}

		self::bump_cache_ver();
		return true;
	}

	public static function list_areas( $state_code ) {
		global $wpdb;
		self::init();

		$state_code = strtoupper( (string) $state_code );

		$ver = self::cache_ver();
		$key = "areas_{$ver}_{$state_code}";
		$cached = wp_cache_get( $key, 'ngabs' );
		if ( $cached !== false ) return $cached;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, area_name, area_fee_kobo FROM " . self::$areas_table . " WHERE state_code=%s ORDER BY area_name ASC",
				$state_code
			),
			ARRAY_A
		);

		foreach ( $rows as &$r ) {
			$r['id'] = (int) $r['id'];
			$r['area_fee_kobo'] = ( $r['area_fee_kobo'] === null ) ? null : (int) $r['area_fee_kobo'];
		}
		unset( $r );

		wp_cache_set( $key, $rows, 'ngabs', 300 );
		return $rows;
	}

	public static function state_has_areas( $state_code ) {
		return ! empty( self::list_areas( $state_code ) );
	}

	public static function get_area_fee_kobo( $state_code, $area_name ) {
		global $wpdb;
		self::init();

		$state_code = strtoupper( (string) $state_code );
		$area_name  = (string) $area_name;

		$ver = self::cache_ver();
		$key = 'area_fee_' . $ver . '_' . md5( $state_code . '|' . $area_name );
		$cached = wp_cache_get( $key, 'ngabs' );
		if ( $cached !== false ) return $cached;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT area_fee_kobo FROM " . self::$areas_table . " WHERE state_code=%s AND area_name=%s",
				$state_code,
				$area_name
			),
			ARRAY_A
		);

		$fee = $row ? ( $row['area_fee_kobo'] === null ? null : (int) $row['area_fee_kobo'] ) : null;
		wp_cache_set( $key, $fee, 'ngabs', 300 );
		return $fee;
	}

	public static function upsert_area( $state_code, $area_name, $fee_kobo_nullable ) {
		global $wpdb;
		self::init();

		$state_code = strtoupper( (string) $state_code );
		$area_name  = trim( (string) $area_name );
		if ( $area_name === '' ) return new WP_Error( 'ngabs_invalid_area', __( 'Area name is required.', 'ngabs' ) );

		$fee_val = ( $fee_kobo_nullable === null ) ? null : (int) $fee_kobo_nullable;

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::$areas_table . " WHERE state_code=%s AND area_name=%s",
				$state_code,
				$area_name
			)
		);

		if ( $existing ) {
			$wpdb->update(
				self::$areas_table,
				array( 'area_fee_kobo' => $fee_val ),
				array( 'id' => (int) $existing ),
				array( $fee_val === null ? '%s' : '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				self::$areas_table,
				array(
					'state_code'     => $state_code,
					'area_name'      => $area_name,
					'area_fee_kobo'  => $fee_val,
				),
				array( '%s', '%s', $fee_val === null ? '%s' : '%d' )
			);
		}

		self::bump_cache_ver();
		return true;
	}

	public static function get_area_by_id( $id ) {
		global $wpdb;
		self::init();

		$id = (int) $id;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id,state_code,area_name,area_fee_kobo FROM " . self::$areas_table . " WHERE id=%d",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) return null;

		$row['id'] = (int) $row['id'];
		$row['state_code'] = strtoupper( (string) $row['state_code'] );
		$row['area_fee_kobo'] = ( $row['area_fee_kobo'] === null ) ? null : (int) $row['area_fee_kobo'];
		return $row;
	}

	public static function update_area_by_id( $id, $state_code, $area_name, $fee_kobo_nullable ) {
		global $wpdb;
		self::init();

		$id = (int) $id;
		$state_code = strtoupper( (string) $state_code );
		$area_name  = trim( (string) $area_name );
		if ( $id <= 0 || $area_name === '' ) return new WP_Error( 'ngabs_invalid_area', __( 'Valid Area ID and name are required.', 'ngabs' ) );

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::$areas_table . " WHERE state_code=%s AND area_name=%s AND id<>%d",
				$state_code,
				$area_name,
				$id
			)
		);

		if ( $exists ) return new WP_Error( 'ngabs_duplicate_area', __( 'An area with this name already exists for that state.', 'ngabs' ) );

		$fee_val = ( $fee_kobo_nullable === null ) ? null : (int) $fee_kobo_nullable;

		$wpdb->update(
			self::$areas_table,
			array(
				'state_code'    => $state_code,
				'area_name'     => $area_name,
				'area_fee_kobo' => $fee_val,
			),
			array( 'id' => $id ),
			array( '%s', '%s', $fee_val === null ? '%s' : '%d' ),
			array( '%d' )
		);

		self::bump_cache_ver();
		return true;
	}

	public static function delete_area( $id ) {
		global $wpdb;
		self::init();

		$wpdb->delete( self::$areas_table, array( 'id' => (int) $id ), array( '%d' ) );
		self::bump_cache_ver();
		return true;
	}

	public static function seed_areas_for_state( $state_code, $areas ) {
		$state_code = strtoupper( (string) $state_code );
		if ( ! is_array( $areas ) ) return;

		foreach ( $areas as $name ) {
			self::upsert_area( $state_code, (string) $name, null );
		}
	}
}
