/* global jQuery, NGABS */
jQuery(function ($) {
	'use strict';

	function getCountryState() {
		var country = $('#shipping_country').val() || $('#billing_country').val() || '';
		var state = $('#shipping_state').val() || $('#billing_state').val() || '';
		return { country: country, state: state };
	}

	function setAreaVisibility(show) {
		var row = $('#ngabs_area').closest('.form-row');
		if (show) row.show();
		else row.hide();
	}

	function setAreaRequired(required) {
		var row = $('#ngabs_area').closest('.form-row');
		if (required) row.addClass('validate-required');
		else row.removeClass('validate-required');
	}

	function loadAreas() {
		var cs = getCountryState();

		if (!cs.country || cs.country.toUpperCase() !== 'NG' || !cs.state) {
			setAreaVisibility(false);
			setAreaRequired(false);
			$('#ngabs_area').html('<option value="">Select an area…</option>');
			return;
		}

		$.post(NGABS.ajax_url, {
			action: 'ngabs_get_areas',
			nonce: NGABS.nonce,
			country: cs.country,
			state: cs.state
		}).done(function (resp) {
			if (!resp || !resp.success) return;

			var data = resp.data || {};
			var options = data.options || [];

			if (!data.has_areas) {
				setAreaVisibility(false);
				setAreaRequired(false);
				$('#ngabs_area').html('<option value="">Select an area…</option>');
				$(document.body).trigger('update_checkout');
				return;
			}

			var current = $('#ngabs_area').val() || '';
			var html = '<option value="">Select an area…</option>';
			options.forEach(function (opt) {
				var sel = (opt.value === current) ? ' selected' : '';
				html += '<option value="' + String(opt.value).replace(/"/g, '&quot;') + '"' + sel + '>' + opt.label + '</option>';
			});

			$('#ngabs_area').html(html);
			setAreaVisibility(true);
			setAreaRequired(true);

			$(document.body).trigger('update_checkout');
		});
	}

	loadAreas();
	setTimeout(loadAreas, 600);

	$(document.body).on('change', '#billing_country, #billing_state, #shipping_country, #shipping_state', loadAreas);

	$(document.body).on('change', '#ngabs_area', function () {
		$(document.body).trigger('update_checkout');
	});
});
