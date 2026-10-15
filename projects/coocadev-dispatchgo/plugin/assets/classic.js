/**
 * Refresh the classic Woo notice and hidden preview token as one fragment.
 * The timer only refreshes presentation; server time, signature validation
 * and receipt persistence remain authoritative before gateway processing.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
(function ($) {
	'use strict';
	// Woo's response refresh replaces the notice and its hidden receipt together.
	$(document.body).on('checkout_error.ccdg', function () { $(document.body).trigger('update_checkout'); });
	window.setInterval(function () {
		if (!document.hidden && !$('form.checkout').hasClass('processing')) { $(document.body).trigger('update_checkout'); }
	}, 60000);
})(jQuery);
