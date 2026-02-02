<?php
if (!defined('ABSPATH')) {
	exit;
}

class NGABS_Checkout {

	public static function init() {
		add_filter('woocommerce_checkout_fields', array(__CLASS__, 'add_area_field'));
		add_action('woocommerce_checkout_update_order_review', array(__CLASS__, 'capture_area_to_session'));
		add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'validate_area'), 10, 2);
		add_action('woocommerce_checkout_create_order', array(__CLASS__, 'save_area_to_order_meta'), 10, 2);

		add_action('wp_ajax_ngabs_get_areas', array(__CLASS__, 'ajax_get_areas'));
		add_action('wp_ajax_nopriv_ngabs_get_areas', array(__CLASS__, 'ajax_get_areas'));

		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_checkout_assets'));
	}

	public static function enqueue_checkout_assets() {
		if (!function_exists('is_checkout') || !is_checkout()) {
			return;
		}

		wp_enqueue_script(
			'ngabs-checkout-area',
			NGABS_PLUGIN_URL . 'assets/js/checkout-area.js',
			array('jquery'),
			NGABS_VERSION,
			true
		);

		wp_localize_script('ngabs-checkout-area', 'NGABS', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('ngabs_checkout_nonce'),
		));
	}

	public static function add_area_field($fields) {
		$fields['shipping']['ngabs_area'] = array(
			'type'        => 'select',
			'label'       => __('Area', 'ngabs'),
			'required'    => false,
			'options'     => array('' => __('Select an area…', 'ngabs')),
			'priority'    => 95,
			'class'       => array('form-row-wide'),
			'clear'       => true,
		);

		return $fields;
	}

	public static function capture_area_to_session($posted_data) {
		if (!WC()->session) {
			return;
		}

		parse_str($posted_data, $data);

		$country = isset($data['shipping_country']) ? strtoupper((string) $data['shipping_country']) : '';
		$state   = isset($data['shipping_state']) ? strtoupper((string) $data['shipping_state']) : '';

		$area = isset($data['ngabs_area']) ? wc_clean(wp_unslash($data['ngabs_area'])) : '';
		$area = is_string($area) ? trim($area) : '';

		if ($country !== 'NG') {
			WC()->session->set('ngabs_area', '');
			return;
		}

		if ($state === '' || !NGABS_DB::state_has_areas($state)) {
			WC()->session->set('ngabs_area', '');
			return;
		}

		WC()->session->set('ngabs_area', $area);
	}

	public static function validate_area($data, $errors) {
		$country = isset($data['shipping_country']) ? strtoupper((string) $data['shipping_country']) : '';
		$state   = isset($data['shipping_state']) ? strtoupper((string) $data['shipping_state']) : '';

		if ($country !== 'NG' || $state === '') {
			return;
		}

		if (!NGABS_DB::state_has_areas($state)) {
			return;
		}

		$area = isset($_POST['ngabs_area']) ? wc_clean(wp_unslash($_POST['ngabs_area'])) : '';
		$area = is_string($area) ? trim($area) : '';

		if ($area === '') {
			$errors->add('ngabs_area_required', __('Please select your Area for delivery.', 'ngabs'));
			return;
		}

		$areas = NGABS_DB::list_areas($state);
		$valid = false;
		foreach ($areas as $row) {
			if ((string) $row['area_name'] === (string) $area) {
				$valid = true;
				break;
			}
		}
		if (!$valid) {
			$errors->add('ngabs_area_invalid', __('Selected Area is not valid for the chosen State.', 'ngabs'));
		}
	}

	public static function save_area_to_order_meta($order, $data) {
		$country = isset($data['shipping_country']) ? strtoupper((string) $data['shipping_country']) : '';
		if ($country !== 'NG') {
			return;
		}

		$state = isset($data['shipping_state']) ? strtoupper((string) $data['shipping_state']) : '';
		if ($state === '' || !NGABS_DB::state_has_areas($state)) {
			return;
		}

		$area = isset($_POST['ngabs_area']) ? wc_clean(wp_unslash($_POST['ngabs_area'])) : '';
		$area = is_string($area) ? trim($area) : '';

		if ($area !== '') {
			$order->update_meta_data('_ngabs_area', $area);
			$order->update_meta_data('_ngabs_state', $state);
		}
	}

	public static function ajax_get_areas() {
		check_ajax_referer('ngabs_checkout_nonce', 'nonce');

		$state = isset($_POST['state']) ? wc_clean(wp_unslash($_POST['state'])) : '';
		$country = isset($_POST['country']) ? wc_clean(wp_unslash($_POST['country'])) : '';
		$state = strtoupper((string) $state);
		$country = strtoupper((string) $country);

		if ($country !== 'NG' || $state === '') {
			wp_send_json_success(array(
				'has_areas' => false,
				'options'  => array(),
			));
		}

		$rows = NGABS_DB::list_areas($state);
		$options = array();
		foreach ($rows as $r) {
			$options[] = array(
				'value' => (string) $r['area_name'],
				'label' => (string) $r['area_name'],
			);
		}

		wp_send_json_success(array(
			'has_areas' => count($options) > 0,
			'options'  => $options,
		));
	}
}
