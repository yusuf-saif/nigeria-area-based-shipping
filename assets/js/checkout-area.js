/* global jQuery, NGABS */
jQuery(function ($) {
	'use strict';

	function isNigeria(country) {
		return String(country || '').toUpperCase() === 'NG';
	}

	function getSectionCountry(section) {
		return $('#' + section + '_country').val() || '';
	}

	function getSectionState(section) {
		return $('#' + section + '_state').val() || '';
	}

	function getSelect(section) {
		return $('#' + section + '_ngabs_area');
	}

	function setVisibility(section, show) {
		var $row = getSelect(section).closest('.form-row');
		show ? $row.show() : $row.hide();
	}

	function setRequired(section, required) {
		var $row = getSelect(section).closest('.form-row');
		required ? $row.addClass('validate-required') : $row.removeClass('validate-required');
		getSelect(section).prop('required', !!required);
	}

	function setOptions(section, options, keepValue) {
		var $sel = getSelect(section);
		var current = keepValue ? ($sel.val() || '') : '';
		var html = '<option value="">Select an area…</option>';

		(options || []).forEach(function (opt) {
			var v = String(opt.value || '').replace(/"/g, '&quot;');
			var sel = (v === current) ? ' selected' : '';
			html += '<option value="' + v + '"' + sel + '>' + opt.label + '</option>';
		});

		$sel.html(html);

		if (!keepValue) {
			$sel.val('');
		}
	}

	function loadAreasFor(section, resetSelection) {
		var country = getSectionCountry(section);
		var state = getSectionState(section);

		if (!isNigeria(country) || !state) {
			setVisibility(section, false);
			setRequired(section, false);
			setOptions(section, [], false);
			return;
		}

		$.post(NGABS.ajax_url, {
			action: 'ngabs_get_areas',
			nonce: NGABS.nonce,
			country: country,
			state: state
		}).done(function (resp) {
			if (!resp || !resp.success) return;

			var data = resp.data || {};
			var options = data.options || [];

			if (!data.has_areas) {
				setVisibility(section, false);
				setRequired(section, false);
				setOptions(section, [], false);
				$(document.body).trigger('update_checkout');
				return;
			}

			setVisibility(section, true);
			setRequired(section, true);
			setOptions(section, options, !resetSelection);

			$(document.body).trigger('update_checkout');
		});
	}

	function init() {
		loadAreasFor('billing', true);
		loadAreasFor('shipping', true);
	}

	init();
	setTimeout(init, 600);

	$(document.body).on('change', '#billing_country, #billing_state', function () {
		loadAreasFor('billing', true);
	});
	$(document.body).on('change', '#shipping_country, #shipping_state', function () {
		loadAreasFor('shipping', true);
	});

	$(document.body).on('change', '#billing_ngabs_area, #shipping_ngabs_area', function () {
		$(document.body).trigger('update_checkout');
	});
});
