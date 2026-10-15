/**
 * Native editing-list controls; all capabilities are checked again in PHP.
 * Quick Edit waits for the Core save, then sends only explicitly changed
 * marks against fresh content. Keep versions and fingerprints from the
 * last server response and show per-item failures instead of optimistic success.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
/* global ccdmAdmin, wp, jQuery, inlineEditPost */
(function ($) {
	'use strict';
	const { __, sprintf } = wp.i18n;
	const pending = new Map();
	async function request(path, method, body) {
		const response = await fetch(ccdmAdmin.root + path, {
			method: method || 'GET', credentials: 'same-origin', cache: 'no-store',
			headers: { 'X-WP-Nonce': ccdmAdmin.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		});
		const data = await response.json();
		if (!response.ok) throw new Error(data.message || __('Could not save. Reload and try again.', 'coocadev-donemark'));
		return data;
	}
	function text(mark) {
		if (mark.stale) return __('Needs review', 'coocadev-donemark');
		if (mark.type === 'review') return mark.done ? __('Matches reviewed version', 'coocadev-donemark') : __('Not reviewed', 'coocadev-donemark');
		return mark.done ? __('Done', 'coocadev-donemark') : __('Not done', 'coocadev-donemark');
	}
	function render(container, state) {
		container.dataset.state = JSON.stringify(state);
		container.querySelectorAll('.ccdm-toggle').forEach(function (button) {
			const mark = state.marks.find(item => item.id === button.dataset.mark);
			if (!mark) { button.disabled = true; return; }
			button.textContent = mark.label + ': ' + text(mark);
			button.setAttribute('aria-pressed', String(mark.done && !mark.stale));
			let actor = button.nextElementSibling;
			if (!actor || actor.tagName !== 'SMALL') {
				actor = document.createElement('small');
				button.after(actor);
			}
			actor.textContent = mark.changed_at_gmt ? mark.actor + ' · ' + mark.changed_at_gmt + ' UTC' : '';
		});
	}
	function report(container, message, error) {
		const status = container.querySelector('.ccdm-status');
		if (status) { status.textContent = message; status.classList.toggle('ccdm-error', !!error); }
	}
	document.addEventListener('click', async function (event) {
		const button = event.target.closest('.ccdm-toggle');
		if (!button) return;
		const container = button.closest('.ccdm-marks');
		const state = JSON.parse(container.dataset.state);
		const mark = state.marks.find(item => item.id === button.dataset.mark);
		if (!mark) return;
		button.disabled = true;
		report(container, __('Saving…', 'coocadev-donemark'));
		try {
			const updated = await request('posts/' + state.post_id + '/marks/' + mark.id, 'PUT', {
				done: mark.stale || !mark.done, version: mark.version, content_hash: state.content_hash
			});
			render(container, updated);
			report(container, __('Saved.', 'coocadev-donemark'));
		} catch (error) {
			report(container, error.message, true);
			try { render(container, await request('posts/' + state.post_id + '/marks')); } catch (ignored) { /* Keep visible error and existing state. */ }
		} finally { button.disabled = false; }
	});
	const originalEdit = inlineEditPost.edit;
	inlineEditPost.edit = function (id) {
		originalEdit.apply(this, arguments);
		const postId = typeof id === 'object' ? this.getId(id) : id;
		const source = document.querySelector('.ccdm-marks[data-post="' + Number(postId) + '"]');
		const target = document.querySelector('#edit-' + Number(postId) + ' .ccdm-quick-fields');
		if (!source || !target) return;
		target.replaceChildren();
		pending.set(Number(postId), new Map());
		const state = JSON.parse(source.dataset.state);
		state.marks.forEach(function (mark) {
			const label = document.createElement('label');
			const input = document.createElement('input');
			input.type = 'checkbox'; input.checked = mark.done && !mark.stale;
			input.addEventListener('change', function () {
				pending.get(Number(postId)).set(mark.id, { done: input.checked, version: mark.version });
			});
			label.append(input, document.createTextNode(' ' + mark.label + ' — ' + text(mark)));
			target.appendChild(label);
		});
	};
	$(document).ajaxSuccess(async function (event, xhr, settings) {
		if (typeof settings.data !== 'string') return;
		const params = new URLSearchParams(settings.data);
		if (params.get('action') !== 'inline-save') return;
		const id = Number(params.get('post_ID'));
		const changes = pending.get(id);
		if (!changes || !changes.size || !String(xhr.responseText).includes('id="post-' + id + '"')) return;
		pending.delete(id);
		const container = document.querySelector('.ccdm-marks[data-post="' + id + '"]');
		if (!container) return;
		try {
			let state = await request('posts/' + id + '/marks');
			for (const [markId, change] of changes) {
				state = await request('posts/' + id + '/marks/' + markId, 'PUT', { done: change.done, version: change.version, content_hash: state.content_hash });
			}
			render(container, state);
			report(container, __('Saved.', 'coocadev-donemark'));
		} catch (error) { report(container, error.message, true); }
	});
	const table = document.querySelector('.wp-list-table');
	if (!table) return;
	const bulk = document.createElement('div'); bulk.className = 'ccdm-bulk';
	const markSelect = document.createElement('select'); markSelect.setAttribute('aria-label', __('Bulk mark', 'coocadev-donemark'));
	ccdmAdmin.definitions.forEach(function (mark) {
		const option = document.createElement('option'); option.value = mark.id; option.textContent = mark.label; markSelect.appendChild(option);
	});
	const stateSelect = document.createElement('select'); stateSelect.setAttribute('aria-label', __('Bulk mark state', 'coocadev-donemark'));
	[[ 'true', __('Mark done / reviewed', 'coocadev-donemark') ], [ 'false', __('Clear mark', 'coocadev-donemark') ]].forEach(function ([value, label]) {
		const option = document.createElement('option'); option.value = value; option.textContent = label; stateSelect.appendChild(option);
	});
	const apply = document.createElement('button'); apply.type = 'button'; apply.className = 'button'; apply.textContent = __('Apply DoneMark to selected posts', 'coocadev-donemark');
	const output = document.createElement('div'); output.className = 'ccdm-bulk-result'; output.setAttribute('role', 'status'); output.setAttribute('aria-live', 'polite');
	bulk.append(markSelect, stateSelect, apply, output); table.before(bulk);
	apply.addEventListener('click', async function () {
		const items = [];
		document.querySelectorAll('tbody input[name="post[]"]:checked').forEach(function (checkbox) {
			const container = document.querySelector('.ccdm-marks[data-post="' + Number(checkbox.value) + '"]');
			if (!container) return;
			const state = JSON.parse(container.dataset.state);
			const mark = state.marks.find(item => item.id === markSelect.value);
			if (mark) items.push({ post_id: state.post_id, mark_id: mark.id, done: stateSelect.value === 'true', version: mark.version, content_hash: state.content_hash });
		});
		if (!items.length || items.length > 100) { output.textContent = __('Select between 1 and 100 editable posts.', 'coocadev-donemark'); return; }
		apply.disabled = true;
		try {
			const data = await request('bulk', 'POST', { items });
			const errors = [];
			let saved = 0;
			data.results.forEach(function (result) {
				if (result.success) {
					saved++;
					const container = document.querySelector('.ccdm-marks[data-post="' + result.post_id + '"]');
					if (container) render(container, result.state);
				} else errors.push('#' + result.post_id + ': ' + result.error);
			});
			output.textContent = sprintf(__('Saved %1$d of %2$d changes.', 'coocadev-donemark'), saved, items.length) + (errors.length ? '\n' + errors.join('\n') : '');
		} catch (error) { output.textContent = error.message; } finally { apply.disabled = false; }
	});
}(jQuery));
