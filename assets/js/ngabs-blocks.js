/* global wp, ngabsBlocksData */
/**
 * Nigeria Area-Based Shipping — Checkout Blocks script
 *
 * Responsibilities:
 * - Detect current Country/State for billing + shipping from WC Blocks data stores.
 * - Fetch Areas for a state (REST) and populate BOTH Billing and Shipping Area selects.
 * - On state change, reset area to blank (required flow) and trigger shipping recalculation.
 * - Push selected billing_area + shipping_area to Store API extension data.
 *
 * Notes:
 * WC Blocks DOM and data stores can vary by WooCommerce version. This script is intentionally
 * defensive and uses broad selectors + store fallbacks.
 */
(function () {
	'use strict';

	if (!window.wp || !wp.data || !wp.apiFetch || !window.ngabsBlocksData) return;

	const apiFetch = wp.apiFetch;
	const select = wp.data.select;
	const dispatch = wp.data.dispatch;

	let prevShippingKey = '';
	let prevBillingKey = '';
	let optionsCache = {}; // state => { has_areas, options }

	function safeUpper(v) {
		return String(v || '').toUpperCase();
	}

	function getCustomerAddresses() {
		try {
			const checkoutStore = select('wc/store/checkout');
			const cartStore = select('wc/store/cart');

			let shipping = checkoutStore && checkoutStore.getShippingAddress ? checkoutStore.getShippingAddress() : null;
			let billing = checkoutStore && checkoutStore.getBillingAddress ? checkoutStore.getBillingAddress() : null;

			if ((!shipping || !billing) && cartStore && cartStore.getCustomerData) {
				const customer = cartStore.getCustomerData();
				shipping = shipping || (customer && customer.shippingAddress) || null;
				billing = billing || (customer && customer.billingAddress) || null;
			}

			return { shipping: shipping || {}, billing: billing || {} };
		} catch (e) {
			return { shipping: {}, billing: {} };
		}
	}

	function getKeys() {
		const addr = getCustomerAddresses();
		const shippingCountry = safeUpper(addr.shipping.country || '');
		const shippingState = String(addr.shipping.state || '');
		const billingCountry = safeUpper(addr.billing.country || '');
		const billingState = String(addr.billing.state || '');

		return {
			shipping: shippingCountry + '|' + shippingState,
			billing: billingCountry + '|' + billingState,
			shippingCountry,
			shippingState,
			billingCountry,
			billingState
		};
	}

	async function fetchAreas(state) {
		if (!state) return { has_areas: false, options: [{ value: '', label: 'Select an area…' }] };

		if (optionsCache[state]) return optionsCache[state];

		try {
			const url = ngabsBlocksData.rest_url + '?state=' + encodeURIComponent(state);
			const path = url.replace(window.location.origin, '');
			const res = await apiFetch({ path });

			const safe = {
				has_areas: !!(res && res.has_areas),
				options: (res && res.options) ? res.options : [{ value: '', label: 'Select an area…' }]
			};

			optionsCache[state] = safe;
			return safe;
		} catch (e) {
			return { has_areas: false, options: [{ value: '', label: 'Select an area…' }] };
		}
	}

	function findSelect(kind) {
		// kind: 'billing' or 'shipping'
		const selectors = [
			// Additional fields often include the id in name or id.
			'select[name*="ngabs/' + kind + '_area"]',
			'select[id*="ngabs"][id*="' + kind + '"]',
			'select[name*="ngabs_' + kind + '_area"]'
		];

		for (let i = 0; i < selectors.length; i++) {
			const el = document.querySelector(selectors[i]);
			if (el) return el;
		}
		return null;
	}

	function setSelectOptions(selectEl, options, resetToBlank) {
		const current = resetToBlank ? '' : (selectEl.value || '');
		selectEl.innerHTML = '';

		(options || []).forEach((opt) => {
			const o = document.createElement('option');
			o.value = opt.value;
			o.textContent = opt.label;
			if (o.value === current) o.selected = true;
			selectEl.appendChild(o);
		});

		if (resetToBlank) selectEl.value = '';
	}

	function setVisibility(selectEl, show) {
		const wrapper =
			selectEl.closest('.wc-block-components-select') ||
			selectEl.closest('.wc-block-components-text-input') ||
			selectEl.parentElement;

		if (wrapper && wrapper.style) wrapper.style.display = show ? '' : 'none';
		selectEl.required = !!show;
	}

	function pushExtensionData(payload) {
		// Prefer cart store (newer), then checkout store (older).
		try {
			const cartDispatch = dispatch('wc/store/cart');
			if (cartDispatch && cartDispatch.setExtensionData) {
				cartDispatch.setExtensionData(ngabsBlocksData.namespace, payload);
				if (cartDispatch.calculateShipping) cartDispatch.calculateShipping();
				return;
			}
		} catch (e) {}

		try {
			const checkoutDispatch = dispatch('wc/store/checkout');
			if (checkoutDispatch && checkoutDispatch.setExtensionData) {
				checkoutDispatch.setExtensionData(ngabsBlocksData.namespace, payload);
			}
		} catch (e) {}
	}

	function bindChange(selectEl, kind, getPayload) {
		if (selectEl.dataset.ngabsBound === '1') return;
		selectEl.dataset.ngabsBound = '1';

		selectEl.addEventListener('change', function () {
			pushExtensionData(getPayload());
		});
	}

	async function syncOne(kind, country, state, resetSelection) {
		const selectEl = findSelect(kind);
		if (!selectEl) return;

		// Not Nigeria or empty state: hide field + clear.
		if (safeUpper(country) !== 'NG' || !state) {
			setVisibility(selectEl, false);
			setSelectOptions(selectEl, [{ value: '', label: 'Select an area…' }], true);
			return;
		}

		const res = await fetchAreas(state);
		setSelectOptions(selectEl, res.options, !!resetSelection);
		setVisibility(selectEl, res.has_areas);

		// If has areas and we just reset, force blank value.
		if (res.has_areas && resetSelection) selectEl.value = '';

		return;
	}

	function currentPayload() {
		const keys = getKeys();
		const billingSelect = findSelect('billing');
		const shippingSelect = findSelect('shipping');

		return {
			country: (keys.shippingCountry || keys.billingCountry),
			state: (keys.shippingState || keys.billingState),
			billing_area: billingSelect ? String(billingSelect.value || '') : '',
			shipping_area: shippingSelect ? String(shippingSelect.value || '') : ''
		};
	}

	async function refresh() {
		const k = getKeys();

		const shippingChanged = (k.shipping !== prevShippingKey);
		const billingChanged = (k.billing !== prevBillingKey);

		// Only act when something changes.
		if (!shippingChanged && !billingChanged) return;

		prevShippingKey = k.shipping;
		prevBillingKey = k.billing;

		// Requirement: reset area when state changes.
		await syncOne('shipping', k.shippingCountry, k.shippingState, shippingChanged);
		await syncOne('billing', k.billingCountry, k.billingState, billingChanged);

		const billingSelect = findSelect('billing');
		const shippingSelect = findSelect('shipping');

		if (billingSelect) bindChange(billingSelect, 'billing', currentPayload);
		if (shippingSelect) bindChange(shippingSelect, 'shipping', currentPayload);

		// Push immediately after refresh (so server has updated blank / new values).
		pushExtensionData(currentPayload());
	}

	// Subscribe to store updates.
	wp.data.subscribe(function () {
		refresh();
	});

	// Also refresh on DOM changes (Blocks can mount fields late).
	document.addEventListener('DOMContentLoaded', function () {
		refresh();
		const obs = new MutationObserver(function () { refresh(); });
		obs.observe(document.body, { childList: true, subtree: true });
	});
})();
