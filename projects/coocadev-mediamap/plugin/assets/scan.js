/**
 * Read-only, sequential scan controller. The DOM uses textContent for all
 * server strings. Pausing aborts only the in-flight batch, so retrying the same
 * ID cursor cannot skip records. Results are not stored in localStorage.
 *
 * @package CoocadevMediaMap
 * @license GPL-2.0-or-later
 */

(function () {
	'use strict';
	const config = window.ccmmScan;
	const form   = document.getElementById( 'ccmm_form' );
	if ( ! config || ! form) {
		return;
	}
	const pause  = document.getElementById( 'ccmm_pause' );
	const resume = document.getElementById( 'ccmm_continue' );
	const status = document.getElementById( 'ccmm_status' );
	const list   = document.getElementById( 'ccmm_results' );
	let cursor   = 0, scanned = 0, skipped = 0, visited = 0, parameters = null, controller = null, generation = 0;
	let running  = false, done = false;
	const seen   = new Set();
	function controls() {
		pause.disabled = ! running; resume.disabled = ! parameters || running || done; }
	async function run() {
		if (running || done || ! parameters) {
			return;
		}
		running     = true;
		const runId = ++generation;
		controls();
		while (running && runId === generation) {
			controller = new AbortController();
			const url  = new URL( config.endpoint, window.location.href );
			parameters.forEach( (value, key) => url.searchParams.set( key, value ) );
			url.searchParams.set( 'after', String( cursor ) );
			status.textContent = config.running + ' ' + config.summary + ' ' + scanned + ' ' + config.visited + ' ' + visited + (skipped ? ' ' + config.skipped + ' ' + skipped : '');
			try {
				const response = await fetch( url, {credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': config.nonce}, signal: controller.signal} );
				const batch    = await response.json();
				if ( ! response.ok) {
					throw new Error( batch.message || config.error );
				}
				if ( ! running || runId !== generation) {
					break;
				}
				for (const match of batch.matches) {
					if (seen.has( match.id )) {
						continue;
					}
					seen.add( match.id );
					const item        = document.createElement( 'li' );
					const title       = document.createElement( 'strong' );
					title.textContent = match.title;
					item.appendChild( title );
					item.appendChild( document.createTextNode( ' (' + match.type + ') — ' + match.reasons.join( ', ' ) + ' ' ) );
					if (match.edit) {
						const link   = document.createElement( 'a' );
						const target = new URL( match.edit, window.location.href );
						if (target.origin === window.location.origin) {
							link.href = target.href; link.textContent = config.edit; item.appendChild( link ); }
					}
					list.appendChild( item );
				}
				if ( ! batch.done && batch.next <= cursor) {
					throw new Error( config.error );
				}
				cursor   = batch.next;
				scanned += batch.scanned;
				skipped += batch.skipped; visited += batch.visited;
				done = batch.done;
				if (done) {
					running            = false;
					status.textContent = (skipped ? config.incomplete : config.done) + ' ' + config.summary + ' ' + scanned + ' ' + config.visited + ' ' + visited + (skipped ? ' ' + config.skipped + ' ' + skipped : (seen.size ? '' : ' ' + config.empty));
				}
			} catch (error) {
				if (runId !== generation) {
					break;
				}
				running            = false;
				status.textContent = error.name === 'AbortError' ? config.paused : (error.message || config.error);
			}
		}
		controls();
	}
	form.addEventListener(
		'submit',
		function (event) {
			event.preventDefault();
			generation++;
			if (controller) {
				controller.abort();
			}
			running = false; done = false; cursor = 0; scanned = 0; skipped = 0; visited = 0;
			parameters = new URLSearchParams( new FormData( form ) );
			seen.clear(); list.replaceChildren();
			run();
		}
	);
	pause.addEventListener(
		'click',
		function () {
			running = false; if (controller) {
				controller.abort();
			} controls(); }
	);
	resume.addEventListener( 'click', run );
}());
