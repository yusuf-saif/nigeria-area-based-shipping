<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_Importer {

	public static function import_csv( $tmp_path ) {
		if ( ! file_exists( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return new WP_Error( 'ngabs_csv_unreadable', __( 'CSV file could not be read.', 'ngabs' ) );
		}

		$h = fopen( $tmp_path, 'r' );
		if ( ! $h ) return new WP_Error( 'ngabs_csv_open_failed', __( 'Failed to open CSV file.', 'ngabs' ) );

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();

		$header = fgetcsv( $h );
		if ( ! $header ) { fclose( $h ); return new WP_Error( 'ngabs_csv_empty', __( 'CSV is empty.', 'ngabs' ) ); }

		$header = array_map( function ( $x ) { return strtolower( trim( (string) $x ) ); }, $header );

		$idx_state = array_search( 'state', $header, true );
		$idx_area  = array_search( 'area', $header, true );
		$idx_price = array_search( 'price', $header, true );

		if ( $idx_state === false || $idx_area === false || $idx_price === false ) {
			$idx_state = 0; $idx_area = 1; $idx_price = 2;
			rewind( $h );
		}

		$rownum = 1;

		while ( ( $row = fgetcsv( $h ) ) !== false ) {
			$rownum++;

			$state_raw = isset( $row[ $idx_state ] ) ? trim( (string) $row[ $idx_state ] ) : '';
			$area_raw  = isset( $row[ $idx_area ] ) ? trim( (string) $row[ $idx_area ] ) : '';
			$price_raw = isset( $row[ $idx_price ] ) ? trim( (string) $row[ $idx_price ] ) : '';

			if ( $state_raw === '' && $area_raw === '' && $price_raw === '' ) continue;

			$state_code = NGABS_States::normalize_to_code( $state_raw );
			if ( ! $state_code ) {
				$skipped++;
				$errors[] = sprintf( __( 'Row %d: invalid State "%s".', 'ngabs' ), $rownum, $state_raw );
				continue;
			}

			if ( $area_raw === '' ) {
				$skipped++;
				$errors[] = sprintf( __( 'Row %d: Area is required.', 'ngabs' ), $rownum );
				continue;
			}

			$fee_kobo = null;
			if ( $price_raw !== '' ) {
				$parsed = NGABS_DB::parse_price_to_kobo( $price_raw );
				if ( is_wp_error( $parsed ) ) {
					$skipped++;
					$errors[] = sprintf( __( 'Row %d: %s', 'ngabs' ), $rownum, $parsed->get_error_message() );
					continue;
				}
				$fee_kobo = (int) $parsed;
			}

			$existing = false;
			foreach ( NGABS_DB::list_areas( $state_code ) as $a ) {
				if ( (string) $a['area_name'] === (string) $area_raw ) { $existing = true; break; }
			}

			$res = NGABS_DB::upsert_area( $state_code, $area_raw, $fee_kobo );
			if ( is_wp_error( $res ) ) {
				$skipped++;
				$errors[] = sprintf( __( 'Row %d: %s', 'ngabs' ), $rownum, $res->get_error_message() );
				continue;
			}

			if ( $existing ) $updated++; else $created++;
		}

		fclose( $h );

		return array(
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}
}
