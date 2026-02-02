<?php
if (!defined('ABSPATH')) {
	exit;
}

class NGABS_Admin {

	const MENU_SLUG = 'ngabs-shipping';

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'register_menu'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
		add_action('admin_notices', array(__CLASS__, 'maybe_show_missing_config_notice'));
	}

	public static function register_menu() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__('Nigeria Shipping', 'ngabs'),
			__('Nigeria Shipping', 'ngabs'),
			'manage_woocommerce',
			self::MENU_SLUG,
			array(__CLASS__, 'render_page')
		);
	}

	public static function enqueue_admin_assets($hook) {
		if (strpos((string) $hook, 'woocommerce_page_' . self::MENU_SLUG) === false) {
			return;
		}
		wp_enqueue_style('ngabs-admin', NGABS_PLUGIN_URL . 'assets/css/admin.css', array(), NGABS_VERSION);
	}

	public static function maybe_flag_missing_config_notice($state_code) {
		if (!is_admin()) {
			set_transient('ngabs_missing_config_state', strtoupper((string) $state_code), 10 * MINUTE_IN_SECONDS);
		}
	}

	public static function maybe_show_missing_config_notice() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$state = get_transient('ngabs_missing_config_state');
		if (!$state) {
			return;
		}

		delete_transient('ngabs_missing_config_state');

		$states = NGABS_States::all();
		$label = isset($states[$state]) ? $states[$state] : $state;

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(sprintf(
			__('Nigeria Area-Based Shipping: No shipping fee configured for %s. Set a State default fee or Area fee in WooCommerce → Nigeria Shipping.', 'ngabs'),
			$label
		));
		echo '</p></div>';
	}

	public static function render_page() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'ngabs'));
		}

		$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'state-fees';
		$tab = in_array($tab, array('state-fees', 'areas', 'import'), true) ? $tab : 'state-fees';

		echo '<div class="wrap ngabs-wrap">';
		echo '<h1>' . esc_html__('Nigeria Area-Based Shipping', 'ngabs') . '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		self::tab_link('state-fees', __('State Fees', 'ngabs'), $tab);
		self::tab_link('areas', __('Areas', 'ngabs'), $tab);
		self::tab_link('import', __('CSV Import', 'ngabs'), $tab);
		echo '</nav>';

		if ($tab === 'state-fees') {
			self::handle_state_fees_post();
			self::render_state_fees();
		} elseif ($tab === 'areas') {
			self::handle_areas_post();
			self::render_areas();
		} else {
			self::handle_import_post();
			self::render_import();
		}

		echo '</div>';
	}

	private static function tab_link($tab, $label, $active_tab) {
		$url = admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab);
		$class = 'nav-tab' . ($tab === $active_tab ? ' nav-tab-active' : '');
		echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
	}

	private static function handle_state_fees_post() {
		if (!isset($_POST['ngabs_action']) || $_POST['ngabs_action'] !== 'save_state_fees') {
			return;
		}
		check_admin_referer('ngabs_save_state_fees', 'ngabs_nonce');

		$states = NGABS_States::all();
		$fees = isset($_POST['state_fee']) ? (array) $_POST['state_fee'] : array();

		$errors = array();
		foreach ($states as $code => $label) {
			$raw = isset($fees[$code]) ? wp_unslash($fees[$code]) : '';
			$raw = is_string($raw) ? trim($raw) : '';

			if ($raw === '') {
				NGABS_DB::set_state_fee_kobo($code, null);
				continue;
			}

			$parsed = NGABS_DB::parse_price_to_kobo($raw);
			if (is_wp_error($parsed)) {
				$errors[] = sprintf('%s: %s', $label, $parsed->get_error_message());
				continue;
			}

			NGABS_DB::set_state_fee_kobo($code, (int) $parsed);
		}

		if (!empty($errors)) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__('Some fees were not saved:', 'ngabs') . '</strong></p><ul>';
			foreach ($errors as $e) {
				echo '<li>' . esc_html($e) . '</li>';
			}
			echo '</ul></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html__('State fees saved.', 'ngabs') . '</p></div>';
		}
	}

	private static function render_state_fees() {
		$states = NGABS_States::all();

		echo '<h2>' . esc_html__('State Default Shipping Fees', 'ngabs') . '</h2>';
		echo '<p class="description">' . esc_html__('Enter amounts in naira (e.g. 1500 or 1500.50). Stored internally as kobo for accuracy. Leave blank to unset.', 'ngabs') . '</p>';

		echo '<form method="post">';
		wp_nonce_field('ngabs_save_state_fees', 'ngabs_nonce');
		echo '<input type="hidden" name="ngabs_action" value="save_state_fees" />';

		echo '<table class="widefat striped ngabs-table">';
		echo '<thead><tr><th>' . esc_html__('State', 'ngabs') . '</th><th>' . esc_html__('Default Fee (₦)', 'ngabs') . '</th></tr></thead>';
		echo '<tbody>';

		foreach ($states as $code => $label) {
			$fee_kobo = NGABS_DB::get_state_fee_kobo($code);
			$value = ($fee_kobo === null) ? '' : NGABS_DB::format_kobo_to_naira($fee_kobo);

			echo '<tr>';
			echo '<td><strong>' . esc_html($label) . '</strong> <code>' . esc_html($code) . '</code></td>';
			echo '<td><input type="text" name="state_fee[' . esc_attr($code) . ']" value="' . esc_attr($value) . '" class="regular-text" placeholder="e.g. 1500.00" /></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		submit_button(__('Save State Fees', 'ngabs'));
		echo '</form>';
	}

	private static function handle_areas_post() {
		if (empty($_POST['ngabs_action'])) {
			return;
		}

		$action = sanitize_key($_POST['ngabs_action']);

		if ($action === 'add_area') {
			check_admin_referer('ngabs_add_area', 'ngabs_nonce');

			$state = isset($_POST['state_code']) ? sanitize_text_field(wp_unslash($_POST['state_code'])) : '';
			$state = strtoupper($state);

			$area = isset($_POST['area_name']) ? sanitize_text_field(wp_unslash($_POST['area_name'])) : '';
			$price_raw = isset($_POST['area_fee']) ? wp_unslash($_POST['area_fee']) : '';
			$price_raw = is_string($price_raw) ? trim($price_raw) : '';

			$fee_kobo = null;
			if ($price_raw !== '') {
				$parsed = NGABS_DB::parse_price_to_kobo($price_raw);
				if (is_wp_error($parsed)) {
					echo '<div class="notice notice-error"><p>' . esc_html($parsed->get_error_message()) . '</p></div>';
					return;
				}
				$fee_kobo = (int) $parsed;
			}

			$res = NGABS_DB::upsert_area($state, $area, $fee_kobo);
			if (is_wp_error($res)) {
				echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
				return;
			}

			echo '<div class="notice notice-success"><p>' . esc_html__('Area saved.', 'ngabs') . '</p></div>';
			return;
		}

		if ($action === 'delete_area') {
			check_admin_referer('ngabs_delete_area', 'ngabs_nonce');

			$id = isset($_POST['area_id']) ? absint($_POST['area_id']) : 0;
			if ($id) {
				NGABS_DB::delete_area($id);
				echo '<div class="notice notice-success"><p>' . esc_html__('Area deleted.', 'ngabs') . '</p></div>';
			}
			return;
		}
	}

	private static function render_areas() {
		$states = NGABS_States::all();

		$selected_state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : 'FC';
		$selected_state = strtoupper($selected_state);
		if (!isset($states[$selected_state])) {
			$selected_state = 'FC';
		}

		$areas = NGABS_DB::list_areas($selected_state);

		echo '<h2>' . esc_html__('Areas Per State', 'ngabs') . '</h2>';
		echo '<p class="description">' . esc_html__('Add areas for each state. If an Area fee is blank, checkout will fall back to the State default fee.', 'ngabs') . '</p>';

		echo '<form method="get" class="ngabs-inline-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '" />';
		echo '<input type="hidden" name="tab" value="areas" />';
		echo '<label for="ngabs_state_select"><strong>' . esc_html__('Select State:', 'ngabs') . '</strong></label> ';
		echo '<select id="ngabs_state_select" name="state">';
		foreach ($states as $code => $label) {
			echo '<option value="' . esc_attr($code) . '" ' . selected($code, $selected_state, false) . '>' . esc_html($label) . ' (' . esc_html($code) . ')</option>';
		}
		echo '</select> ';
		submit_button(__('Load', 'ngabs'), 'secondary', '', false);
		echo '</form>';

		echo '<hr />';
		echo '<h3>' . esc_html__('Add / Update Area', 'ngabs') . '</h3>';
		echo '<form method="post" class="ngabs-area-form">';
		wp_nonce_field('ngabs_add_area', 'ngabs_nonce');
		echo '<input type="hidden" name="ngabs_action" value="add_area" />';
		echo '<input type="hidden" name="state_code" value="' . esc_attr($selected_state) . '" />';

		echo '<table class="form-table"><tbody>';
		echo '<tr>';
		echo '<th scope="row"><label>' . esc_html__('Area Name', 'ngabs') . '</label></th>';
		echo '<td><input type="text" name="area_name" class="regular-text" required /></td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label>' . esc_html__('Area Fee (₦)', 'ngabs') . '</label></th>';
		echo '<td><input type="text" name="area_fee" class="regular-text" placeholder="Leave blank to use State default" /></td>';
		echo '</tr>';
		echo '</tbody></table>';

		submit_button(__('Save Area', 'ngabs'));
		echo '</form>';

		echo '<hr />';
		echo '<h3>' . esc_html__('Existing Areas', 'ngabs') . '</h3>';

		if (empty($areas)) {
			echo '<p>' . esc_html__('No areas found for this state yet.', 'ngabs') . '</p>';
			return;
		}

		echo '<table class="widefat striped ngabs-table">';
		echo '<thead><tr><th>' . esc_html__('Area', 'ngabs') . '</th><th>' . esc_html__('Fee (₦)', 'ngabs') . '</th><th>' . esc_html__('Actions', 'ngabs') . '</th></tr></thead>';
		echo '<tbody>';

		foreach ($areas as $row) {
			$fee_display = ($row['area_fee_kobo'] === null) ? '<em>' . esc_html__('(uses State default)', 'ngabs') . '</em>' : esc_html(NGABS_DB::format_kobo_to_naira($row['area_fee_kobo']));

			echo '<tr>';
			echo '<td>' . esc_html($row['area_name']) . '</td>';
			echo '<td>' . $fee_display . '</td>';
			echo '<td>';
			echo '<form method="post" style="display:inline;">';
			wp_nonce_field('ngabs_delete_area', 'ngabs_nonce');
			echo '<input type="hidden" name="ngabs_action" value="delete_area" />';
			echo '<input type="hidden" name="area_id" value="' . esc_attr($row['id']) . '" />';
			submit_button(__('Delete', 'ngabs'), 'delete', '', false, array(
				'onclick' => "return confirm('Are you sure you want to delete this area?');",
			));
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function handle_import_post() {
		if (!isset($_POST['ngabs_action']) || $_POST['ngabs_action'] !== 'import_csv') {
			return;
		}
		check_admin_referer('ngabs_import_csv', 'ngabs_nonce');

		if (!isset($_FILES['ngabs_csv']) || empty($_FILES['ngabs_csv']['tmp_name'])) {
			echo '<div class="notice notice-error"><p>' . esc_html__('Please choose a CSV file to upload.', 'ngabs') . '</p></div>';
			return;
		}

		$file = $_FILES['ngabs_csv'];

		$res = NGABS_Importer::import_csv($file['tmp_name']);
		if (is_wp_error($res)) {
			echo '<div class="notice notice-error"><p>' . esc_html($res->get_error_message()) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>' . esc_html__('Import completed.', 'ngabs') . '</p></div>';

		echo '<h3>' . esc_html__('Import Summary', 'ngabs') . '</h3>';
		echo '<ul class="ngabs-import-summary">';
		echo '<li>' . esc_html(sprintf(__('Created: %d', 'ngabs'), (int) $res['created'])) . '</li>';
		echo '<li>' . esc_html(sprintf(__('Updated: %d', 'ngabs'), (int) $res['updated'])) . '</li>';
		echo '<li>' . esc_html(sprintf(__('Skipped: %d', 'ngabs'), (int) $res['skipped'])) . '</li>';
		echo '</ul>';

		if (!empty($res['errors'])) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Some rows were skipped:', 'ngabs') . '</strong></p><ul>';
			foreach ($res['errors'] as $err) {
				echo '<li>' . esc_html($err) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	private static function render_import() {
		echo '<h2>' . esc_html__('CSV Import (State | Area | Price)', 'ngabs') . '</h2>';

		echo '<p class="description">';
		echo esc_html__('Upload a CSV with columns: State, Area, Price. State can be a code (e.g. LA) or name (e.g. Lagos). Price can be blank to mean “use State default”.', 'ngabs');
		echo '</p>';

		echo '<div class="ngabs-template">';
		echo '<strong>' . esc_html__('Template:', 'ngabs') . '</strong>';
		echo '<pre>State,Area,Price
FC,Wuse,2000
FC,Maitama,
LA,Ikeja,2500.50</pre>';
		echo '</div>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field('ngabs_import_csv', 'ngabs_nonce');
		echo '<input type="hidden" name="ngabs_action" value="import_csv" />';

		echo '<table class="form-table"><tbody>';
		echo '<tr>';
		echo '<th scope="row"><label for="ngabs_csv">' . esc_html__('CSV File', 'ngabs') . '</label></th>';
		echo '<td><input type="file" name="ngabs_csv" id="ngabs_csv" accept=".csv,text/csv" required /></td>';
		echo '</tr>';
		echo '</tbody></table>';

		submit_button(__('Import CSV', 'ngabs'));
		echo '</form>';
	}
}
