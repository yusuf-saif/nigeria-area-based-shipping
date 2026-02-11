<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NGABS_Admin {

	const MENU_SLUG = 'ngabs-nigeria-shipping';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		add_action( 'admin_post_ngabs_save_state_fees', array( __CLASS__, 'handle_save_state_fees' ) );
		add_action( 'admin_post_ngabs_save_area', array( __CLASS__, 'handle_save_area' ) );
		add_action( 'admin_post_ngabs_delete_area', array( __CLASS__, 'handle_delete_area' ) );
		add_action( 'admin_post_ngabs_import_csv', array( __CLASS__, 'handle_import_csv' ) );
		add_action( 'admin_post_ngabs_run_wizard', array( __CLASS__, 'handle_wizard' ) );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) return;
		wp_enqueue_style( 'ngabs-admin', NGABS_PLUGIN_URL . 'assets/css/admin.css', array(), NGABS_VERSION );

		wp_enqueue_script( 'ngabs-admin-areas', NGABS_PLUGIN_URL . 'assets/js/admin-areas.js', array( 'jquery' ), NGABS_VERSION, true );
		wp_localize_script( 'ngabs-admin-areas', 'ngabsAdmin', array( 'confirm_delete' => __( 'Delete this area?', 'ngabs' ) ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'ngabs',
			__( 'Nigeria Shipping', 'ngabs' ),
			__( 'Nigeria Shipping', 'ngabs' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function maybe_redirect_to_wizard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		if ( ! is_admin() ) return;

		$do = (int) get_option( 'ngabs_do_activation_redirect', 0 );
		if ( ! $do ) return;

		delete_option( 'ngabs_do_activation_redirect' );
		if ( is_network_admin() ) return;

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=wizard' ) );
		exit;
	}

	private static function flash( $msg, $type = 'success' ) {
		set_transient( 'ngabs_admin_notice', array( 'type' => $type, 'msg' => $msg ), 60 );
	}

	public static function admin_notices() {
		$notice = get_transient( 'ngabs_admin_notice' );
		if ( $notice && is_array( $notice ) ) {
			delete_transient( 'ngabs_admin_notice' );
			$type = isset( $notice['type'] ) ? $notice['type'] : 'success';
			$msg  = isset( $notice['msg'] ) ? $notice['msg'] : '';
			if ( $msg ) {
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
			}
		}
	}

	public static function maybe_flag_missing_config_notice( $state_code ) {
		if ( is_admin() ) return;
		set_transient( 'ngabs_missing_config', strtoupper( (string) $state_code ), 5 * MINUTE_IN_SECONDS );
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wizard';
		$allowed = array( 'wizard', 'state-fees', 'areas', 'import' );
		return in_array( $tab, $allowed, true ) ? $tab : 'wizard';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;

		$tab = self::current_tab();
		$tabs = array(
			'wizard' => __( 'Setup Wizard', 'ngabs' ),
			'state-fees' => __( 'State Fees', 'ngabs' ),
			'areas' => __( 'Areas', 'ngabs' ),
			'import' => __( 'Import (CSV)', 'ngabs' ),
		);

		echo '<div class="wrap ngabs-wrap">';
		echo '<h1>' . esc_html__( 'Nigeria Area-Based Shipping', 'ngabs' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $key );
			$cls = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'state-fees':
				self::render_state_fees();
				break;
			case 'areas':
				self::render_areas();
				break;
			case 'import':
				self::render_import();
				break;
			case 'wizard':
			default:
				self::render_wizard();
				break;
		}

		echo '</div>';
	}

	// ======================
	// Wizard
	// ======================

	private static function ensure_nigeria_zone_and_method() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) return;

		$zone_id = null;

		// Find an existing zone that targets NG country.
		$zones = WC_Shipping_Zones::get_zones();
		foreach ( $zones as $z ) {
			if ( empty( $z['zone_locations'] ) ) continue;
			foreach ( $z['zone_locations'] as $loc ) {
				if ( isset( $loc->type, $loc->code ) && $loc->type === 'country' && strtoupper( (string) $loc->code ) === 'NG' ) {
					$zone_id = (int) $z['id'];
					break 2;
				}
			}
		}

		// If not found, create it.
		if ( ! $zone_id ) {
			$zone = new WC_Shipping_Zone();
			$zone->set_zone_name( 'Nigeria' );
			$zone->add_location( 'NG', 'country' );
			$zone->save();
			$zone_id = $zone->get_id();
		}

		if ( ! $zone_id ) return;

		$zone    = new WC_Shipping_Zone( $zone_id );
		$methods = $zone->get_shipping_methods( true );

		// If method already exists in zone, ensure enabled via instance settings.
		foreach ( $methods as $m ) {
			if ( isset( $m->id ) && $m->id === 'ngabs_shipping' ) {
				self::enable_zone_method_instance( (int) $m->instance_id, 'ngabs_shipping' );
				return;
			}
		}

		// Otherwise add the method to the zone and enable it.
		$instance_id = $zone->add_shipping_method( 'ngabs_shipping' );
		if ( $instance_id ) {
			self::enable_zone_method_instance( (int) $instance_id, 'ngabs_shipping' );
		}
	}

	/**
	 * Enable a shipping method instance in a zone.
	 *
	 * We do this by updating the method instance settings option directly:
	 * - WooCommerce stores per-instance settings at: woocommerce_{method_id}_{instance_id}_settings
	 * - The 'enabled' key controls activation for that instance.
	 *
	 * This avoids calling non-existent save() methods across WooCommerce versions.
	 */
	private static function enable_zone_method_instance( $instance_id, $method_id ) {
		$instance_id = (int) $instance_id;
		$method_id   = sanitize_key( $method_id );
		if ( $instance_id <= 0 || $method_id === '' ) return;

		$option_key = 'woocommerce_' . $method_id . '_' . $instance_id . '_settings';
		$settings   = get_option( $option_key, array() );

		if ( ! is_array( $settings ) ) $settings = array();
		$settings['enabled'] = 'yes';

		// Keep a sane default title if not set.
		if ( empty( $settings['title'] ) ) {
			$settings['title'] = __( 'Nigeria Shipping', 'ngabs' );
		}

		update_option( $option_key, $settings );
	}


	public static function render_wizard() {
		$done = (int) get_option( 'ngabs_setup_done', 0 );

		echo '<div class="ngabs-card">';
		echo '<h2>' . esc_html__( '1-minute Setup Wizard', 'ngabs' ) . '</h2>';
		echo '<p>' . esc_html__( 'This will preload starter data and create a Nigeria shipping zone with the NGABS method enabled.', 'ngabs' ) . '</p>';

		if ( $done ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Setup is complete. You can re-run the wizard anytime.', 'ngabs' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ngabs_run_wizard" />';
		wp_nonce_field( 'ngabs_run_wizard', 'ngabs_nonce' );

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Preload Dataset', 'ngabs' ) . '</th><td>';
		echo '<label><input type="checkbox" name="seed_fct" value="1" checked> ' . esc_html__( 'Abuja (FCT) starter areas', 'ngabs' ) . '</label><br>';
		echo '<label><input type="checkbox" name="seed_lagos" value="1" checked> ' . esc_html__( 'Lagos starter areas', 'ngabs' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Quick Fees (optional)', 'ngabs' ) . '</th><td>';
		echo '<label>' . esc_html__( 'FCT default fee (₦)', 'ngabs' ) . ' <input type="text" name="fee_fc" value="2000" class="regular-text" /></label><br><br>';
		echo '<label>' . esc_html__( 'Lagos default fee (₦)', 'ngabs' ) . ' <input type="text" name="fee_la" value="2500" class="regular-text" /></label><br><br>';
		echo '<label>' . esc_html__( 'Other states default fee (₦)', 'ngabs' ) . ' <input type="text" name="fee_other" value="" class="regular-text" placeholder="leave blank" /></label>';
		echo '<p class="description">' . esc_html__( 'Area fees remain empty (fallback to state default) unless you set them later.', 'ngabs' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( $done ? __( 'Re-run Wizard', 'ngabs' ) : __( 'Run Wizard', 'ngabs' ) );
		echo '</form>';
		echo '</div>';

		$flag = get_transient( 'ngabs_missing_config' );
		if ( $flag ) {
			echo '<div class="notice notice-warning inline"><p>' . sprintf(
				esc_html__( 'Checkout attempted a state with no configured fee: %s. Configure it under State Fees.', 'ngabs' ),
				esc_html( $flag )
			) . '</p></div>';
		}
	}

	public static function handle_wizard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'ngabs_run_wizard', 'ngabs_nonce' );

		$seed_fct   = ! empty( $_POST['seed_fct'] );
		$seed_lagos = ! empty( $_POST['seed_lagos'] );

		$fee_fc_raw    = isset( $_POST['fee_fc'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_fc'] ) ) : '';
		$fee_la_raw    = isset( $_POST['fee_la'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_la'] ) ) : '';
		$fee_other_raw = isset( $_POST['fee_other'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_other'] ) ) : '';

		$fee_fc    = NGABS_DB::parse_price_to_kobo( $fee_fc_raw );
		$fee_la    = NGABS_DB::parse_price_to_kobo( $fee_la_raw );
		$fee_other = NGABS_DB::parse_price_to_kobo( $fee_other_raw );

		if ( ! is_wp_error( $fee_fc ) && $fee_fc !== null ) NGABS_DB::set_state_fee_kobo( 'FC', (int) $fee_fc );
		if ( ! is_wp_error( $fee_la ) && $fee_la !== null ) NGABS_DB::set_state_fee_kobo( 'LA', (int) $fee_la );

		if ( ! is_wp_error( $fee_other ) && $fee_other !== null ) {
			foreach ( array_keys( NGABS_States::all() ) as $code ) {
				if ( $code === 'FC' || $code === 'LA' ) continue;
				NGABS_DB::set_state_fee_kobo( $code, (int) $fee_other );
			}
		}

		if ( $seed_fct ) {
			$fct = array( 'Wuse','Maitama','Garki','Asokoro','Jabi','Utako','Gwarinpa','Kubwa','Lugbe','Apo','Jahi','Kado','Durumi','Katampe','Life Camp' );
			NGABS_DB::seed_areas_for_state( 'FC', $fct );
		}
		if ( $seed_lagos ) {
			$lagos = array( 'Ikeja','Lekki','Victoria Island','Ikoyi','Surulere','Yaba','Ajah','Maryland','Gbagada','Alimosho','Agege','Festac','Ojo','Apapa','Ogba' );
			NGABS_DB::seed_areas_for_state( 'LA', $lagos );
		}

		self::ensure_nigeria_zone_and_method();

		update_option( 'ngabs_setup_done', 1, false );

		self::flash( __( 'Wizard completed. Nigeria zone verified, method enabled, and starter data loaded.', 'ngabs' ), 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=state-fees' ) );
		exit;
	}

	// ======================
	// State Fees
	// ======================

	private static function render_state_fees() {
		$states = NGABS_States::all();

		echo '<div class="ngabs-card">';
		echo '<h2>' . esc_html__( 'State Default Fees', 'ngabs' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ngabs_save_state_fees" />';
		wp_nonce_field( 'ngabs_save_state_fees', 'ngabs_nonce' );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'State', 'ngabs' ) . '</th><th>' . esc_html__( 'Code', 'ngabs' ) . '</th><th>' . esc_html__( 'Default Fee (₦)', 'ngabs' ) . '</th></tr></thead><tbody>';

		foreach ( $states as $code => $label ) {
			$fee_kobo  = NGABS_DB::get_state_fee_kobo( $code );
			$fee_naira = ( $fee_kobo === null ) ? '' : NGABS_DB::format_kobo_to_naira( (int) $fee_kobo );

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td><code>' . esc_html( $code ) . '</code></td>';
			echo '<td><input type="text" name="fees[' . esc_attr( $code ) . ']" value="' . esc_attr( $fee_naira ) . '" class="regular-text" placeholder="e.g. 2500 or 2500.50" /></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Save State Fees', 'ngabs' ) );
		echo '</form>';
		echo '</div>';
	}

	public static function handle_save_state_fees() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'ngabs_save_state_fees', 'ngabs_nonce' );

		$fees = isset( $_POST['fees'] ) && is_array( $_POST['fees'] ) ? (array) $_POST['fees'] : array();

		$errors = array();

		foreach ( $fees as $code => $raw ) {
			$code = strtoupper( sanitize_text_field( $code ) );
			$raw  = sanitize_text_field( wp_unslash( $raw ) );

			if ( $raw === '' ) {
				NGABS_DB::set_state_fee_kobo( $code, null );
				continue;
			}

			$kobo = NGABS_DB::parse_price_to_kobo( $raw );
			if ( is_wp_error( $kobo ) ) {
				$errors[] = sprintf( __( '%s: %s', 'ngabs' ), $code, $kobo->get_error_message() );
				continue;
			}

			NGABS_DB::set_state_fee_kobo( $code, (int) $kobo );
		}

		if ( $errors ) {
			self::flash( __( 'Some fees were not saved:', 'ngabs' ) . '<br>' . implode( '<br>', array_map( 'esc_html', $errors ) ), 'error' );
		} else {
			self::flash( __( 'State fees saved.', 'ngabs' ), 'success' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=state-fees' ) );
		exit;
	}

	// ======================
	// Areas CRUD
	// ======================

	private static function render_areas() {
		$states = NGABS_States::all();
		$state = isset( $_GET['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) : 'FC';
		if ( ! isset( $states[ $state ] ) ) $state = 'FC';

		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? NGABS_DB::get_area_by_id( $edit_id ) : null;

		echo '<div class="ngabs-card">';
		echo '<h2>' . esc_html__( 'Areas per State', 'ngabs' ) . '</h2>';

		echo '<form method="get" action="">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="areas" />';
		echo '<label>' . esc_html__( 'Select State:', 'ngabs' ) . ' ';
		echo '<select name="state">';
		foreach ( $states as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $state, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'View', 'ngabs' ), 'secondary', '', false );
		echo '</form>';

		$rows = NGABS_DB::list_areas( $state );

		echo '<hr>';

		echo '<h3>' . ( $editing ? esc_html__( 'Edit Area', 'ngabs' ) : esc_html__( 'Add Area', 'ngabs' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ngabs_save_area" />';
		wp_nonce_field( 'ngabs_save_area', 'ngabs_nonce' );
		echo '<input type="hidden" name="state_code" value="' . esc_attr( $state ) . '" />';
		if ( $editing ) echo '<input type="hidden" name="area_id" value="' . esc_attr( (int) $editing['id'] ) . '" />';

		$area_name = $editing ? (string) $editing['area_name'] : '';
		$fee_naira = '';
		if ( $editing && $editing['area_fee_kobo'] !== null ) $fee_naira = NGABS_DB::format_kobo_to_naira( (int) $editing['area_fee_kobo'] );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Area Name', 'ngabs' ) . '</th><td><input type="text" name="area_name" value="' . esc_attr( $area_name ) . '" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Area Fee (₦)', 'ngabs' ) . '</th><td><input type="text" name="area_fee" value="' . esc_attr( $fee_naira ) . '" class="regular-text" placeholder="leave blank to fallback to state fee" /></td></tr>';
		echo '</tbody></table>';

		submit_button( $editing ? __( 'Update Area', 'ngabs' ) : __( 'Add Area', 'ngabs' ) );

		if ( $editing ) {
			$cancel = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=areas&state=' . $state );
			echo ' <a class="button" href="' . esc_url( $cancel ) . '">' . esc_html__( 'Cancel', 'ngabs' ) . '</a>';
		}

		echo '</form>';
		echo '<hr>';

		echo '<h3>' . sprintf( esc_html__( 'Areas in %s', 'ngabs' ), esc_html( $states[ $state ] ) ) . '</h3>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No areas found for this state. Shipping will fall back to state default.', 'ngabs' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Area', 'ngabs' ) . '</th><th>' . esc_html__( 'Fee (₦)', 'ngabs' ) . '</th><th>' . esc_html__( 'Actions', 'ngabs' ) . '</th></tr></thead><tbody>';

		$confirm_msg = esc_js( __( 'Delete this area?', 'ngabs' ) );

		foreach ( $rows as $r ) {
			$fee = ( $r['area_fee_kobo'] === null )
				? '<em>' . esc_html__( 'Fallback to state', 'ngabs' ) . '</em>'
				: esc_html( NGABS_DB::format_kobo_to_naira( (int) $r['area_fee_kobo'] ) );

			$edit_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=areas&state=' . $state . '&edit=' . (int) $r['id'] );
			$del_url  = wp_nonce_url(
				admin_url( 'admin-post.php?action=ngabs_delete_area&area_id=' . (int) $r['id'] . '&state=' . $state ),
				'ngabs_delete_area',
				'ngabs_nonce'
			);

			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['area_name'] ) . '</td>';
			echo '<td>' . $fee . '</td>';
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'ngabs' ) . '</a> ';
			// IMPORTANT: Use esc_js() + single quotes in confirm to avoid PHP string termination bugs.
			echo '<a class="button button-small button-link-delete" href="' . esc_url( $del_url ) . '" onclick="return confirm(&quot;' . esc_attr( $confirm_msg ) . '&quot;)">' . esc_html__( 'Delete', 'ngabs' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	public static function handle_save_area() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'ngabs_save_area', 'ngabs_nonce' );

		$state = isset( $_POST['state_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state_code'] ) ) ) : '';
		$area_name = isset( $_POST['area_name'] ) ? sanitize_text_field( wp_unslash( $_POST['area_name'] ) ) : '';
		$fee_raw   = isset( $_POST['area_fee'] ) ? sanitize_text_field( wp_unslash( $_POST['area_fee'] ) ) : '';
		$area_id   = isset( $_POST['area_id'] ) ? absint( $_POST['area_id'] ) : 0;

		$fee_kobo = null;
		if ( $fee_raw !== '' ) {
			$parsed = NGABS_DB::parse_price_to_kobo( $fee_raw );
			if ( is_wp_error( $parsed ) ) {
				self::flash( esc_html( $parsed->get_error_message() ), 'error' );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=areas&state=' . $state ) );
				exit;
			}
			$fee_kobo = (int) $parsed;
		}

		$res = $area_id
			? NGABS_DB::update_area_by_id( $area_id, $state, $area_name, $fee_kobo )
			: NGABS_DB::upsert_area( $state, $area_name, $fee_kobo );

		if ( is_wp_error( $res ) ) {
			self::flash( esc_html( $res->get_error_message() ), 'error' );
		} else {
			self::flash( __( 'Area saved.', 'ngabs' ), 'success' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=areas&state=' . $state ) );
		exit;
	}

	public static function handle_delete_area() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'ngabs_delete_area', 'ngabs_nonce' );

		$area_id = isset( $_GET['area_id'] ) ? absint( $_GET['area_id'] ) : 0;
		$state = isset( $_GET['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) : 'FC';

		if ( $area_id ) NGABS_DB::delete_area( $area_id );

		self::flash( __( 'Area deleted.', 'ngabs' ), 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=areas&state=' . $state ) );
		exit;
	}

	// ======================
	// Import
	// ======================

	private static function render_import() {
		echo '<div class="ngabs-card">';
		echo '<h2>' . esc_html__( 'CSV Import', 'ngabs' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload a CSV with headers: State | Area | Price', 'ngabs' ) . '</p>';
		echo '<p><code>State,Area,Price</code></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="ngabs_import_csv" />';
		wp_nonce_field( 'ngabs_import_csv', 'ngabs_nonce' );

		echo '<input type="file" name="csv_file" accept=".csv,text/csv" required />';
		submit_button( __( 'Import CSV', 'ngabs' ) );
		echo '</form>';
		echo '</div>';
	}

	public static function handle_import_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'ngabs_import_csv', 'ngabs_nonce' );

		if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
			self::flash( __( 'No file uploaded.', 'ngabs' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=import' ) );
			exit;
		}

		$res = NGABS_Importer::import_csv( $_FILES['csv_file']['tmp_name'] );

		if ( is_wp_error( $res ) ) {
			self::flash( esc_html( $res->get_error_message() ), 'error' );
		} else {
			$msg = sprintf(
				__( 'Import complete. Created: %d, Updated: %d, Skipped: %d', 'ngabs' ),
				(int) $res['created'],
				(int) $res['updated'],
				(int) $res['skipped']
			);

			if ( ! empty( $res['errors'] ) ) {
				$msg .= '<br><br><strong>' . esc_html__( 'Issues:', 'ngabs' ) . '</strong><br>' .
					implode( '<br>', array_map( 'esc_html', array_slice( $res['errors'], 0, 20 ) ) );
				if ( count( $res['errors'] ) > 20 ) $msg .= '<br>…';
				self::flash( $msg, 'warning' );
			} else {
				self::flash( $msg, 'success' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=import' ) );
		exit;
	}
}