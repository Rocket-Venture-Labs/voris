/**
 * Voris — dismiss a plan nudge (Phase 2, chunk 4).
 *
 * Persists a per-threshold dismissal server-side so it does not reappear within
 * the same billing window + cap (AC13). Only dismissible nudges carry a button;
 * at-cap and lock states have none. No dependencies (no build step). Uses the
 * localized `vorisNudge` { ajaxUrl, nonce }.
 */
( function () {
	'use strict';

	var cfg = window.vorisNudge || {};
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest ? event.target.closest( '.voris-nudge-dismiss' ) : null;
		if ( ! btn ) {
			return;
		}

		event.preventDefault();

		var notice = btn.closest( '.voris-nudge' );
		var key = btn.getAttribute( 'data-key' ) || '';

		var body = new URLSearchParams();
		body.set( 'action', 'voris_nudge_dismiss' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'key', key );

		if ( notice ) {
			notice.style.display = 'none';
		}

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () {
			// A failed dismissal is harmless — the nudge simply returns on reload.
			if ( notice ) {
				notice.style.display = '';
			}
		} );
	} );
}() );
