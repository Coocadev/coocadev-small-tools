/**
 * Readable, unbundled Blocks adapter using registered Woo dependencies.
 * Only the opaque server token enters extension checkout data, never a
 * browser-calculated dispatch date. Failed refresh does not bypass the
 * server checkout validation. ExperimentalOrderMeta needs Woo regression QA.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
/* Unbundled source: uses the WordPress and WooCommerce libraries already registered by Woo. */
(function (wp, wc) {
	'use strict';
	if (!wc || !wc.blocksCheckout || !wc.wcBlocksData) { return; }
	var blocksCheckout = wc.blocksCheckout;
	var blocksData = wc.wcBlocksData;
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var cartStore = blocksData.cartStore;
	var checkoutStore = blocksData.checkoutStore;
	var Fill = blocksCheckout.ExperimentalOrderMeta;
	if (!Fill || !cartStore || !checkoutStore) { return; }
	function Message(props) {
		return el('div', { className: 'ccdg-block-notice', 'aria-live': 'polite' },
			el('p', null, props.text),
			el('button', { type: 'button', className: 'ccdg-refresh', onClick: props.refresh }, wp.i18n.__('Refresh dispatch notice', 'coocadev-dispatchgo')));
	}
	function Notice() {
		var state = wp.data.useSelect(function (select) {
			var cart = select(cartStore).getCartData();
			return { data: cart.extensions && cart.extensions.ccdg, status: select(checkoutStore).getCheckoutStatus() };
		}, []);
		var previous = useRef(state.status);
		var data = state.data;
		function refresh() {
			return blocksCheckout.extensionCartUpdate({ namespace: 'ccdg', data: {} }).catch(function () { /* Checkout server validation remains authoritative. */ });
		}
		useEffect(function () {
			if (data && data.token) {
				wp.data.dispatch(checkoutStore).setExtensionData('ccdg', { token: data.token });
			}
		}, [data && data.token]);
		useEffect(function () {
			refresh();
			var timer = window.setInterval(function () { if (!document.hidden) { refresh(); } }, 60000);
			return function () { window.clearInterval(timer); };
		}, []);
		useEffect(function () {
			if (state.status === 'idle' && previous.current !== 'idle' && previous.current !== 'pristine') { refresh(); }
			previous.current = state.status;
		}, [state.status]);
		var isCheckout = !!document.querySelector('.wp-block-woocommerce-checkout');
		if (!data || !data.text || (!isCheckout && !data.show_cart)) { return null; }
		return el(Fill, null, el(Message, { text: data.text, refresh: refresh }));
	}
	wp.plugins.registerPlugin('ccdg-dispatch-notice', { render: Notice, scope: 'woocommerce-checkout' });
})(window.wp, window.wc);
