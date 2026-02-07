/* global jQuery, ngabsAdmin */
jQuery(function ($) {
	'use strict';

	function openModal(mode, data) {
		var $modal = $('#ngabs-area-modal');
		$('#ngabs-modal-title').text(mode === 'edit' ? 'Edit Area' : 'Add Area');
		$('#ngabs_area_submit').text(mode === 'edit' ? 'Update Area' : 'Save Area');

		$('#ngabs_area_id').val(data.id || 0);
		$('#ngabs_area_name').val(data.name || '');
		$('#ngabs_area_fee').val(data.fee || '');

		$modal.show().attr('aria-hidden', 'false');
		$('#ngabs_area_name').trigger('focus');
	}

	function closeModal() {
		$('#ngabs-area-modal').hide().attr('aria-hidden', 'true');
	}

	$(document).on('click', '.ngabs-open-modal', function (e) {
		e.preventDefault();
		var $btn = $(this);
		openModal($btn.data('mode'), {
			id: $btn.data('area-id'),
			name: $btn.data('area-name'),
			fee: $btn.data('area-fee')
		});
	});

	$(document).on('click', '.ngabs-modal-close, .ngabs-modal__backdrop', function (e) {
		e.preventDefault();
		closeModal();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') closeModal();
	});

	$(document).on('click', 'a[data-confirm="1"]', function () {
		var msg = (window.ngabsAdmin && ngabsAdmin.confirm_delete) ? ngabsAdmin.confirm_delete : 'Delete this area?';
		return window.confirm(msg);
	});
});
