/**
 * Editor placeholders for server-rendered note and list blocks.
 * No private note is serialized into post content or the editor preview.
 * The frontend receives a neutral shell and authenticates separately.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
/* Coocadev Reader Notes. GPL-2.0-or-later. Source, no build step. */
(function (blocks, element, i18n, editor) {
	'use strict';
	const el = element.createElement;
	[
		['note', i18n.__('Reader Note', 'coocadev-reader-notes'), i18n.__('A private reader note will load here after sign-in. No reader text is shown in the editor.', 'coocadev-reader-notes')],
		['list', i18n.__('My Notes', 'coocadev-reader-notes'), i18n.__('The signed-in reader’s private notes list will load here.', 'coocadev-reader-notes')]
	].forEach(function (item) {
		blocks.registerBlockType('coocadev-reader-notes/' + item[0], {
			apiVersion: 3, title: item[1], category: 'widgets', icon: 'edit',
			edit: function () { return el('div', editor.useBlockProps({ className: 'ccrn-editor-placeholder' }), el('strong', {}, item[1]), el('p', {}, item[2])); },
			save: function () { return null; }
		});
	});
})(window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.blockEditor);

