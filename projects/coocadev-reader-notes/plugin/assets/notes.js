/**
 * Own-note interface: one queue per article, shared by duplicate shells.
 * generation and AbortController reject late responses from old sessions.
 * Drafts stay in memory; visibility/page events clear private DOM and
 * require a session recheck. Conflicts require an explicit reader choice.
 * Use text nodes for notes; never turn literal note contents into HTML.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
/* Coocadev Reader Notes. GPL-2.0-or-later. Readable source; no build step. */
(function () {
	'use strict';
	const config = window.ccrnConfig;
	if (!config) return;
	const t = config.strings, shells = Array.from(document.querySelectorAll('.ccrn-shell'));
	const states = new Map(), requests = new Set();
	let session = null, generation = 0, checkInProgress = null, active = false, exporting = false;
	function node(tag, text, attributes) {
		const item = document.createElement(tag);
		if (text !== undefined && text !== null) item.textContent = text;
		Object.entries(attributes || {}).forEach(([key, value]) => item.setAttribute(key, String(value)));
		return item;
	}
	function button(label, action) {
		const result = node('button', label, { type: 'button' });
		result.addEventListener('click', action);
		return result;
	}
	function setStatus(state, message, error) {
		state.message = message; state.error = !!error;
		for (const shell of state.shells) {
			const status = shell.querySelector('.ccrn-status');
			if (status) { status.textContent = message; status.classList.toggle('ccrn-error', !!error); }
		}
	}
	function cancel() {
		generation++;
		for (const controller of requests) controller.abort();
		requests.clear();
		for (const state of states.values()) {
			clearTimeout(state.timer); state.timer = null; state.saving = false;
		}
	}
	function neutral(message, canLogin) {
		for (const shell of shells) {
			shell.replaceChildren(node('p', message || t.loading));
			if (canLogin) {
				const link = node('a', t.login, { href: config.login });
				shell.append(link);
			} else {
				shell.append(button(t.retry, () => verify()));
			}
		}
	}
	function suspend(discard) {
		active = false; cancel();
		if (discard) { states.clear(); session = null; }
		neutral(t.paused, false);
	}
	async function request(path, method, data) {
		if (!active || !session) throw Object.assign(new Error(t.paused), { code: 'ccrn_paused' });
		const stamp = generation, controller = new AbortController();
		requests.add(controller);
		const timeout = setTimeout(() => controller.abort(), 20000);
		try {
			const url = new URL(config.api);
			// WordPress plain-permalink REST roots contain ?rest_route=.
			if (url.searchParams.has('rest_route')) {
				const parts = path.split('?');
				url.searchParams.set('rest_route', url.searchParams.get('rest_route') + parts[0]);
				new URLSearchParams(parts[1] || '').forEach((value, key) => url.searchParams.set(key, value));
			} else {
				const parts = path.split('?');
				url.pathname += parts[0];
				url.search = parts[1] || '';
			}
			const response = await fetch(url, {
				method: method || 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
				headers: { 'X-WP-Nonce': session.nonce, 'Content-Type': 'application/json' },
				body: data === undefined ? undefined : JSON.stringify(data)
			});
			const result = await response.json();
			if (stamp !== generation || !active) throw new DOMException('Stale session', 'AbortError');
			if (!response.ok) {
				const error = Object.assign(new Error(result.message || t.error), { code: result.code, status: response.status });
				if (response.status === 401 || result.code === 'rest_cookie_invalid_nonce' || result.code === 'ccrn_epoch') {
					suspend(true);
					neutral(t.paused, true);
				}
				throw error;
			}
			return result;
		} finally {
			clearTimeout(timeout); requests.delete(controller);
		}
	}
	async function verify() {
		if (document.hidden) return;
		if (checkInProgress) return checkInProgress;
		active = false; cancel(); neutral(t.loading, false);
		const stamp = generation, controller = new AbortController();
		requests.add(controller);
		const timeout = setTimeout(() => controller.abort(), 15000);
		checkInProgress = (async () => {
			try {
				const url = new URL(config.ajax);
				url.searchParams.set('action', 'ccrn_bootstrap');
				const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
				const result = await response.json();
				if (stamp !== generation || document.hidden) return;
				if (!response.ok || !result.success || !result.data.nonce) {
					states.clear(); session = null; neutral(t.login, true); return;
				}
				const next = result.data;
				if (session && (session.user !== next.user || session.epoch !== next.epoch)) states.clear();
				session = next; active = true;
				await mount();
			} catch (error) {
				if (stamp === generation) neutral(t.paused, false);
			} finally {
				clearTimeout(timeout); requests.delete(controller); checkInProgress = null;
			}
		})();
		return checkInProgress;
	}
	function createState(post) {
		return { post, note: null, draft: '', dirty: false, saving: false, lastSave: 0, timer: null, shells: [], message: t.new, error: false, conflict: null };
	}
	async function mount() {
		const byPost = new Map();
		for (const shell of shells) {
			if (shell.dataset.ccrnMode === 'list') { renderListShell(shell); continue; }
			const post = Number(shell.dataset.ccrnPost);
			if (!post) continue;
			if (!byPost.has(post)) byPost.set(post, []);
			byPost.get(post).push(shell);
		}
		for (const [post, members] of byPost) {
			let state = states.get(post);
			if (!state) { state = createState(post); states.set(post, state); }
			state.shells = members;
			try {
				const data = await request('posts/' + post + '/note');
				if (!state.dirty) {
					state.note = data.note; state.draft = data.note ? data.note.content : ''; state.conflict = null;
					state.message = data.note ? t.saved : t.new;
				} else if ((data.note ? data.note.version : 0) !== (state.note ? state.note.version : 0)) {
					state.conflict = data.note; state.message = t.conflict;
				}
				renderNote(state);
			} catch (error) {
				if (active && error.name !== 'AbortError') for (const shell of members) shell.replaceChildren(node('p', error.message));
			}
		}
	}
	function renderNote(state) {
		for (const shell of state.shells) {
			const id = 'ccrn-text-' + shells.indexOf(shell);
			const label = node('label', t.label, { for: id });
			const input = node('textarea', null, { id, maxlength: '20000', 'aria-describedby': id + '-help', spellcheck: 'true' });
			input.value = state.draft;
			input.disabled = !session.writable || session.erasing;
			const help = node('small', t.limit + ' ' + t.private, { id: id + '-help' });
			const count = node('small', Array.from(state.draft).length + ' / 10000', { class: 'ccrn-count' });
			const status = node('p', state.message, { class: 'ccrn-status', role: 'status', 'aria-live': 'polite' });
			const actions = node('div', null, { class: 'ccrn-actions' });
			const save = button(t.save, () => saveNote(state));
			save.disabled = input.disabled;
			actions.append(save, button(t.copy, () => copyText(state)));
			if (state.note) {
				actions.append(button(t.export, () => exportNotes(state.note.id)), button(t.delete, () => removeNote(state)));
			}
			input.addEventListener('input', () => {
				state.draft = input.value; state.dirty = true;
				for (const peer of state.shells) {
					const other = peer.querySelector('textarea');
					if (other && other !== input) other.value = state.draft;
					const peerCount = peer.querySelector('.ccrn-count');
					if (peerCount) peerCount.textContent = Array.from(state.draft).length + ' / 10000';
				}
				setStatus(state, t.unsaved);
				if (!state.conflict) schedule(state);
			});
			shell.replaceChildren(label, input, count, help, status, actions);
			if (!session.writable || session.erasing) shell.append(node('p', session.erasing ? t.erasing : t.readonly));
			if (state.conflict) renderConflict(shell, state);
		}
	}
	function schedule(state) {
		clearTimeout(state.timer);
		if (!active || state.conflict || session.erasing || !session.writable) return;
		state.timer = setTimeout(() => saveNote(state), Math.max(1500, 2000 - (Date.now() - state.lastSave)));
	}
	async function saveNote(state) {
		if (!active || state.saving || !state.dirty || state.conflict || session.erasing || !session.writable) return;
		clearTimeout(state.timer);
		const text = state.draft;
		if (Array.from(text).length > 10000 || new TextEncoder().encode(text).byteLength > 40960) { setStatus(state, t.limit, true); return; }
		const stamp = generation;
		state.saving = true; state.lastSave = Date.now(); setStatus(state, t.saving);
		try {
			const data = await request('posts/' + state.post + '/note', 'PUT', { content: text, version: state.note ? state.note.version : 0, epoch: session.epoch });
			if (stamp !== generation) return;
			state.note = data.note;
			state.dirty = state.draft !== text;
			if (!state.dirty) state.draft = data.note.content;
			setStatus(state, state.dirty ? t.unsaved : t.saved);
			if (!state.dirty) {
				// Keep cursor/selection and focus intact on an autosave response.
				for (const shell of state.shells) {
					const actions = shell.querySelector('.ccrn-actions');
					if (actions && actions.children.length === 2) {
						actions.append(button(t.export, () => exportNotes(state.note.id)), button(t.delete, () => removeNote(state)));
					}
				}
			}
		} catch (error) {
			if (stamp !== generation || !active || error.name === 'AbortError') return;
			if (error.code === 'ccrn_conflict') {
				const data = await request('posts/' + state.post + '/note').catch(() => null);
				if (data && active) { state.conflict = data.note || { version: 0, content: '' }; setStatus(state, t.conflict, true); renderNote(state); }
			} else setStatus(state, error.message, true);
		} finally {
			state.saving = false;
			// Only a successful save queues newer input. Errors need explicit Save/Retry.
			if (stamp === generation && active && state.dirty && !state.error && !state.conflict) schedule(state);
		}
	}
	function renderConflict(shell, state) {
		const box = node('div', null, { class: 'ccrn-conflict' });
		box.append(node('p', t.conflict), node('pre', state.conflict.content));
		const controls = node('div', null, { class: 'ccrn-actions' });
		controls.append(button(t.load, () => {
			state.note = state.conflict.version ? state.conflict : null;
			state.draft = state.conflict.content; state.dirty = false; state.conflict = null;
			state.message = t.saved; state.error = false; renderNote(state);
		}), button(t.replace, () => {
			if (!window.confirm(t.confirmReplace)) return;
			state.note = state.conflict.version ? state.conflict : null;
			state.conflict = null; state.dirty = true; state.error = false;
			renderNote(state); saveNote(state);
		}));
		box.append(controls); shell.append(box);
	}
	async function copyText(state) {
		if (!active) return;
		try { await navigator.clipboard.writeText(state.draft); setStatus(state, t.copied); }
		catch (error) {
			const textarea = state.shells[0].querySelector('textarea');
			if (textarea) { textarea.focus(); textarea.select(); }
		}
	}
	async function removeNote(state) {
		if (!active || !state.note || !window.confirm(t.confirmDelete)) return;
		if (state.saving) { setStatus(state, t.saving); return; }
		clearTimeout(state.timer);
		try {
			await request('notes/' + state.note.id, 'DELETE', { version: state.note.version, epoch: session.epoch });
			state.note = null; state.draft = ''; state.dirty = false; state.conflict = null; state.message = t.new;
			renderNote(state);
		} catch (error) { if (active) setStatus(state, error.message, true); }
	}
	function renderListShell(shell) {
		const searchId = 'ccrn-search-' + shells.indexOf(shell);
		const input = node('input', null, { id: searchId, type: 'search', maxlength: '100' });
		const form = node('form');
		form.append(node('label', t.search, { for: searchId }), input, button(t.search, () => load(true)));
		form.addEventListener('submit', event => { event.preventDefault(); load(true); });
		const actions = node('div', null, { class: 'ccrn-actions' });
		actions.append(button(t.exportAll, () => exportNotes()), button(t.erase, () => eraseAll(shell)));
		const results = node('div'), status = node('p', t.loading, { role: 'status', 'aria-live': 'polite' });
		const more = button(t.next, () => load(false));
		more.hidden = true;
		shell.replaceChildren(form, actions, status, results, more);
		let cursor = null, ticket = 0;
		async function load(reset) {
			const current = ++ticket, stamp = generation;
			if (reset) { cursor = null; results.replaceChildren(); }
			status.textContent = t.loading; more.disabled = true;
			try {
				const data = await request('notes?before=' + (cursor || 0) + '&search=' + encodeURIComponent(input.value));
				if (current !== ticket || stamp !== generation) return;
				for (const note of data.notes) {
					const entry = node('article', null, { class: 'ccrn-entry' });
					if (note.source) entry.append(node('a', note.source.title, { href: note.source.url }));
					else entry.append(node('h3', t.unavailable));
					entry.append(node('p', note.content.slice(0, 160)), node('small', note.updated_at_gmt + ' UTC'));
					const controls = node('div', null, { class: 'ccrn-actions' });
					controls.append(button(t.export, () => exportNotes(note.id)), button(t.delete, async () => {
						if (!window.confirm(t.confirmDelete)) return;
						try {
							await request('notes/' + note.id, 'DELETE', { version: note.version, epoch: session.epoch });
							entry.remove();
							if (note.post_id && states.has(note.post_id)) {
								const state = states.get(note.post_id); clearTimeout(state.timer); states.delete(note.post_id); await mount();
							}
						} catch (error) { if (active) status.textContent = error.message; }
					}));
					entry.append(controls); results.append(entry);
				}
				cursor = data.next; more.hidden = !cursor; more.disabled = false;
				status.textContent = results.children.length ? '' : t.empty;
				if (session.erasing) status.textContent = t.erasing;
			} catch (error) { if (active) status.textContent = error.message; }
		}
		load(true);
	}
	async function exportNotes(id) {
		if (!active || exporting) return;
		exporting = true;
		const stamp = generation, chunks = [];
		try {
			let before = 0;
			do {
				const data = await request('export', 'POST', id ? { id } : { before });
				for (const note of data.notes) {
					chunks.push((note.source ? note.source.title + '\n' + note.source.url : t.unavailable) + '\n' + note.updated_at_gmt + ' UTC\n\n' + note.content + '\n\n---\n\n');
				}
				before = data.next;
			} while (before && active && stamp === generation);
			if (!active || stamp !== generation) return;
			const url = URL.createObjectURL(new Blob(chunks, { type: 'text/plain;charset=utf-8' }));
			const download = node('a', '', { href: url, download: id ? 'reader-note-' + id + '.txt' : 'reader-notes.txt' });
			document.body.append(download); download.click(); download.remove();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		} catch (error) { if (active) window.alert(error.message); }
		finally { exporting = false; }
	}
	async function eraseAll(shell) {
		if (!active || !window.confirm(t.confirmAll)) return;
		// Cancel pending drafts before beginning the server-side epoch barrier.
		cancel(); states.clear(); neutral(t.erasing, false);
		const stamp = generation;
		shell.replaceChildren(node('p', t.erasing, { role: 'status' }));
		try {
			let operation = session.operation || null, done = false;
			do {
				const data = await request('erase', 'POST', { epoch: session.epoch, operation });
				if (!active || stamp !== generation) return;
				session.epoch = data.epoch; session.operation = data.operation; session.erasing = !data.done;
				operation = data.operation; done = data.done;
			} while (!done);
			await mount();
		} catch (error) {
			if (active) { shell.replaceChildren(node('p', error.message), button(t.retry, () => verify())); }
		}
	}
	document.addEventListener('visibilitychange', () => { if (document.hidden) suspend(false); else verify(); });
	window.addEventListener('pagehide', () => suspend(true));
	window.addEventListener('pageshow', () => verify());
	window.addEventListener('beforeunload', event => {
		if (active && Array.from(states.values()).some(state => state.dirty)) { event.preventDefault(); event.returnValue = ''; }
	});
	// Optional cross-tab channel carries invalidation only, never note text.
	if ('BroadcastChannel' in window) {
		const channel = new BroadcastChannel('ccrn-session');
		channel.addEventListener('message', () => { suspend(true); verify(); });
	}
	verify();
})();

