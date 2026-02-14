/* global wp, ngabsBlocksData */
/**
 * NGABS - Checkout Block behaviour.
 *
 * - Watches country/state from Blocks data stores.
 * - Loads areas via REST and populates the Area <select>.
 * - Uses wc.blocksCheckout.extensionCartUpdate() to push selection to the server and trigger
 *   shipping/total recalculation (no page refresh).
 *
 * Design note:
 * - We do NOT run cart updates on every DOM mutation. We only run an update when the effective
 *   country/state changes or when the shopper changes the Area selection.
 */
(function () {
	'use strict';

	if (!window.wp || !window.wp.data || !window.wp.apiFetch || !window.ngabsBlocksData) {
		return;
	}

	const apiFetch = window.wp.apiFetch;
	const select = window.wp.data.select;

	let lastKey = '';
	let cachedOptions = [{ value: '', label: 'Select an area…' }];
	let cachedHasAreas = false;

	function getAddress() {
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

	function getCountryState() {
		const addr = getAddress();
		const country = String(addr.shipping.country || addr.billing.country || '').toUpperCase();
		const state = String(addr.shipping.state || addr.billing.state || '').toUpperCase();
		return { country, state };
	}

	function findAreaSelects() {
		const selectors = [
			'select[name="extensions[ngabs][area]"]',
			'select[name="extensions%5Bngabs%5D%5Barea%5D"]',
			'select[name*="ngabs/area"]',
			'select[id*="ngabs"][id*="area"]',
			'select[name*="ngabs_area"]'
		];
		const nodes = [];
		selectors.forEach((sel) => {
			document.querySelectorAll(sel).forEach((el) => nodes.push(el));
		});
		return Array.from(new Set(nodes));
	}

	function setVisibility(selectEl, show) {
		const wrapper =
			selectEl.closest('.wc-block-components-select') ||
			selectEl.closest('.wc-block-components-text-input') ||
			selectEl.parentElement;

		if (wrapper && wrapper.style) wrapper.style.display = show ? '' : 'none';
		selectEl.required = !!show;
	}

	function setOptions(selectEl, options, reset) {
		selectEl.innerHTML = '';
		options.forEach((opt) => {
			const o = document.createElement('option');
			o.value = opt.value;
			o.textContent = opt.label;
			selectEl.appendChild(o);
		});
		if (reset) selectEl.value = '';
	}

	function applyCachedToSelect(selectEl) {
		setVisibility(selectEl, cachedHasAreas);
		setOptions(selectEl, cachedHasAreas ? cachedOptions : [{ value: '', label: 'Select an area…' }], false);
	}

	function processError(err) {
		try {
			if (window.wc && window.wc.wcBlocksData && typeof window.wc.wcBlocksData.processErrorResponse === 'function') {
				window.wc.wcBlocksData.processErrorResponse(err);
				return;
			}
		} catch (e) {}
		// eslint-disable-next-line no-console
		console.warn('NGABS Blocks update failed', err);
	}

	function extensionCartUpdate(payload) {
		try {
			if (window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function') {
				return window.wc.blocksCheckout.extensionCartUpdate({
					namespace: ngabsBlocksData.namespace,
					data: payload
				});
			}
		} catch (e) {}
		return Promise.resolve();
	}

	async function fetchAreas(state) {
		try {
			const url = ngabsBlocksData.rest_url + '?state=' + encodeURIComponent(state);
			const path = url.replace(window.location.origin, '');
			return await apiFetch({ path });
		} catch (e) {
			return null;
		}
	}

	function bindChange(selectEl) {
		if (selectEl.__ngabsBound) return;
		selectEl.__ngabsBound = true;

		selectEl.addEventListener('change', function () {
			const cs = getCountryState();
			const area = String(selectEl.value || '');
			extensionCartUpdate({ country: cs.country, state: cs.state, area }).catch(processError);
		});
	}

	function ensureBoundAndStyled() {
		const selects = findAreaSelects();
		selects.forEach((sel) => {
			applyCachedToSelect(sel);
			bindChange(sel);
		});
		return selects.length;
	}

	async function refreshIfStateChanged() {
		const cs = getCountryState();
		const key = cs.country + '|' + cs.state;

		// If fields aren't mounted yet, do nothing and don't advance lastKey.
		if (findAreaSelects().length === 0) {
			return;
		}

		if (key === lastKey) {
			// State hasn't changed; just make sure any newly-mounted selects have correct options/binding.
			ensureBoundAndStyled();
			return;
		}

		lastKey = key;

		// Not Nigeria or no state: hide & clear and clear server selection.
		if (cs.country !== 'NG' || !cs.state) {
			cachedHasAreas = false;
			cachedOptions = [{ value: '', label: 'Select an area…' }];

			findAreaSelects().forEach((sel) => {
				setVisibility(sel, false);
				setOptions(sel, cachedOptions, true);
				bindChange(sel);
			});

			extensionCartUpdate({ country: cs.country, state: cs.state, area: '' }).catch(processError);
			return;
		}

		const res = await fetchAreas(cs.state);
		if (!res) return;

		cachedHasAreas = !!res.has_areas;
		cachedOptions = res.options || [{ value: '', label: 'Select an area…' }];

		findAreaSelects().forEach((sel) => {
			setVisibility(sel, cachedHasAreas);
			// Required flow: reset to blank on state change.
			setOptions(sel, cachedHasAreas ? cachedOptions : [{ value: '', label: 'Select an area…' }], true);
			bindChange(sel);
		});

		// Clear effective area on state change to force re-select.
		extensionCartUpdate({ country: cs.country, state: cs.state, area: '' }).catch(processError);
	}

	// Subscribe to store changes (country/state changes trigger refresh).
	// IMPORTANT: wp.data.subscribe fires very frequently; we debounce to avoid infinite loops
	// where our extensionCartUpdate triggers store changes which trigger subscribe again.
	let ngabsTimer = null;
	let ngabsInFlight = false;

	function scheduleRefresh() {
		if (ngabsInFlight) return;
		if (ngabsTimer) window.clearTimeout(ngabsTimer);
		ngabsTimer = window.setTimeout(async function () {
			ngabsInFlight = true;
			try {
				await refreshIfStateChanged();
			} finally {
				ngabsInFlight = false;
			}
		}, 150);
	}

	wp.data.subscribe(function () {
		scheduleRefresh();
	});

	document.addEventListener('DOMContentLoaded', function () {
		// Initial bind in case field mounts quickly.
		ensureBoundAndStyled();
		refreshIfStateChanged();

		// Observe for late-mounted field; we only apply cached options + bind handlers, no cart update here.
		const obs = new MutationObserver(function () {
			ensureBoundAndStyled();
		});
		obs.observe(document.body, { childList: true, subtree: true });
	});
})();
