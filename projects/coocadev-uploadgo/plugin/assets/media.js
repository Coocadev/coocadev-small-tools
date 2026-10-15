/**
 * Editor guidance only. Plupload receives the effective server limit too,
 * but the PHP policy and file inspector enforce every supported upload.
 * Disabling this script must never grant extra upload permissions.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
/* global ccugMedia, wp, plupload */
(function () {
	'use strict';
	const { __, sprintf } = wp.i18n;
	function showHint() {
		document.querySelectorAll('.media-frame .upload-ui, .upload-ui').forEach(function (container) {
			if (container.querySelector('.ccug-media-hint')) return;
			const hint = document.createElement('p');
			hint.className = 'ccug-media-hint';
			hint.textContent = ccugMedia.hint;
			hint.setAttribute('role', 'status');
			container.appendChild(hint);
		});
	}
	if (wp.Uploader && wp.Uploader.queue) {
		wp.Uploader.queue.on('add', function (file) {
			if (file.get('size') > ccugMedia.maxBytes) {
				// Plupload also receives the same max_file_size from the server.
				file.set('ccugPolicyError', sprintf(__('File exceeds your upload limit of %d bytes.', 'coocadev-uploadgo'), ccugMedia.maxBytes));
			}
		});
	}
	document.addEventListener('click', function (event) {
		if (event.target.closest('.insert-media, .upload-view-toggle, .media-menu-item')) {
			requestAnimationFrame(showHint);
		}
	});
	showHint();
}());
