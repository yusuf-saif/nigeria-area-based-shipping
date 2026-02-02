<?php
if (!defined('ABSPATH')) {
	exit;
}

class NGABS_Shipping_Method extends WC_Shipping_Method {

	public function __construct($instance_id = 0) {
		$this->id                 = 'ngabs_shipping';
		$this->instance_id        = absint($instance_id);
		$this->method_title       = __('Nigeria Area-Based Shipping', 'ngabs');
		$this->method_description = __('Area-based shipping for Nigeria using state defaults and optional per-area fees.', 'ngabs');
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
		);

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title      = $this->get_option('title', __('Nigeria Shipping', 'ngabs'));
		$this->tax_status = $this->get_option('tax_status', 'taxable');
		$this->handling_kobo = (int) $this->get_option('handling_kobo', 0);

		add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'title' => array(
				'title'       => __('Method Title', 'ngabs'),
				'type'        => 'text',
				'description' => __('Title shown to customers during checkout.', 'ngabs'),
				'default'     => __('Nigeria Shipping', 'ngabs'),
				'desc_tip'    => true,
			),
			'tax_status' => array(
				'title'   => __('Tax Status', 'ngabs'),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __('Taxable', 'ngabs'),
					'none'    => __('None', 'ngabs'),
				),
			),
			'handling_kobo' => array(
				'title'       => __('Base Handling Fee (₦)', 'ngabs'),
				'type'        => 'text',
				'description' => __('Optional base handling fee added on top of state/area fee. Enter in naira (e.g. 500 or 500.00). Stored safely as kobo.', 'ngabs'),
				'default'     => '0',
				'desc_tip'    => true,
			),
		);
	}

	public function process_admin_options() {
		parent::process_admin_options();

		$raw = $this->get_option('handling_kobo', '0');
		$parsed = NGABS_DB::parse_price_to_kobo($raw);
		if (is_wp_error($parsed)) {
			$this->update_option('handling_kobo', '0');
			add_action('admin_notices', function () use ($parsed) {
				echo '<div class="notice notice-error"><p>' . esc_html($parsed->get_error_message()) . '</p></div>';
			});
		} else {
			$this->update_option('handling_kobo', (string) (int) ($parsed ?: 0));
		}

		$this->handling_kobo = (int) $this->get_option('handling_kobo', 0);
	}

	public function is_available($package) {
		$available = parent::is_available($package);

		$country = isset($package['destination']['country']) ? strtoupper((string) $package['destination']['country']) : '';
		if ($country !== 'NG') {
			return false;
		}

		return $available;
	}

	public function calculate_shipping($package = array()) {
		$dest = isset($package['destination']) ? $package['destination'] : array();
		$country = isset($dest['country']) ? strtoupper((string) $dest['country']) : '';
		if ($country !== 'NG') {
			return;
		}

		$state = isset($dest['state']) ? strtoupper((string) $dest['state']) : '';
		if ($state === '') {
			return; // wait for state selection
		}

		$chosen_area = '';
		if (WC()->session) {
			$chosen_area = (string) WC()->session->get('ngabs_area', '');
		}

		$has_areas = NGABS_DB::state_has_areas($state);

		$fee_kobo = null;

		if ($has_areas && $chosen_area !== '') {
			$area_fee = NGABS_DB::get_area_fee_kobo($state, $chosen_area);
			if ($area_fee !== null) {
				$fee_kobo = (int) $area_fee;
			}
		}

		if ($fee_kobo === null) {
			$state_fee = NGABS_DB::get_state_fee_kobo($state);
			if ($state_fee !== null) {
				$fee_kobo = (int) $state_fee;
			}
		}

		if ($fee_kobo === null) {
			// Safest: return no rate to prevent accidental free shipping
			NGABS_Admin::maybe_flag_missing_config_notice($state);
			return;
		}

		$handling_kobo = (int) $this->get_option('handling_kobo', 0);
		$total_kobo = (int) $fee_kobo + max(0, $handling_kobo);

		$cost = $total_kobo / 100;

		$rate = array(
			'id'       => $this->get_rate_id(),
			'label'    => $this->title,
			'cost'     => $cost,
			'package'  => $package,
			'calc_tax' => 'per_order',
		);

		$this->add_rate($rate);
	}
}
