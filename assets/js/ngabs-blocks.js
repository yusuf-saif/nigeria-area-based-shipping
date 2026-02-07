/* global wp, ngabsBlocksData */
(function () {
	'use strict';
	if (!window.wp || !wp.data || !wp.apiFetch || !window.ngabsBlocksData) return;

	const apiFetch = wp.apiFetch;
	const select = wp.data.select;
	const dispatch = wp.data.dispatch;

	let lastKey = '';

	function getAddress() {
		try {
			const checkoutStore = select('wc/store/checkout');
			const cartStore = select('wc/store/cart');

			let shipping = checkoutStore && checkoutStore.getShippingAddress ? checkoutStore.getShippingAddress() : null;
			let billing = checkoutStore && checkoutStore.getBillingAddress ? checkoutStore.getBillingAddress() : null;

			if (!shipping && cartStore && cartStore.getCustomerData) {
				const customer = cartStore.getCustomerData();
				shipping = customer && customer.shippingAddress ? customer.shippingAddress : null;
				billing = customer && customer.billingAddress ? customer.billingAddress : null;
			}

			return { shipping: shipping || {}, billing: billing || {} };
		} catch (e) {
			return { shipping: {}, billing: {} };
		}
	}

	function currentCountryState() {
		const addr = getAddress();
		const country = (addr.shipping.country || addr.billing.country || '').toUpperCase();
		const state = (addr.shipping.state || addr.billing.state || '').toUpperCase();
		return { country, state };
	}

	async function fetchAreas(state) {
		const url = ngabsBlocksData.rest_url + '?state=' + encodeURIComponent(state);
		try {
			return await apiFetch({ url });
		} catch (e) {
			return null;
		}
	}

	function setExtensionData(country, state, area) {
		try {
			const cartDispatch = dispatch('wc/store/cart');
			if (cartDispatch && cartDispatch.setExtensionData) {
				cartDispatch.setExtensionData(ngabsBlocksData.namespace, { country, state, area });
				return;
			}
		} catch (e) {}
	}

	wp.data.subscribe(async function () {
		const cs = currentCountryState();
		const key = cs.country + '|' + cs.state;
		if (key === lastKey) return;
		lastKey = key;

		if (cs.country !== 'NG' || !cs.state) {
			setExtensionData(cs.country, cs.state, '');
			return;
		}

		const res = await fetchAreas(cs.state);
		if (!res) return;

		setExtensionData(cs.country, cs.state, '');
	});
})();
