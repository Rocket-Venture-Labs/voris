/**
 * Voris — in-admin connect heartbeat (Phase 2, ADR-ACH003 + ADR-ACH004).
 *
 * "Connect to Voris" starts a device authorization, shows a persistent approval
 * link + user code, and polls the wp-admin ajax endpoint every advertised interval
 * until the token mints. WP-Cron is NOT used — it is visitor-triggered and would
 * never run while the admin is approving. The cadence comes from the server-advertised
 * interval (honoring slow_down / Retry-After), never a constant.
 *
 * Hardening (ADR-ACH004):
 *  - The approval link is the PRIMARY, keyboard-focusable path; window.open is a
 *    best-effort convenience whose success or failure changes nothing (VOR-299).
 *  - The heartbeat RESUMES on page load whenever the connection is incomplete or a
 *    device code is still pending — the flow's own reload must never orphan the poll
 *    (VOR-298). A "Resume connection" button drives the same path by hand.
 *  - A "Disconnect" button releases the connection (VOR-306).
 *
 * No build step. Depends only on WordPress's bundled `wp-i18n` (declared on the
 * `voris-connect` handle) so its user-facing copy runs through `wp.i18n.__()`.
 * Uses the localized `vorisConnect` { ajaxUrl, nonce, resume, userCode?, connectUrl? }.
 */
( function () {
	'use strict';

	var __ = window.wp.i18n.__;
	var sprintf = window.wp.i18n.sprintf;

	var cfg = window.vorisConnect || {};
	var btn = document.getElementById( 'voris-connect-btn' );
	var status = document.getElementById( 'voris-connect-status' );
	var approve = document.getElementById( 'voris-connect-approve' );
	var link = document.getElementById( 'voris-connect-link' );
	var code = document.getElementById( 'voris-connect-code' );
	var disconnectBtn = document.getElementById( 'voris-connect-disconnect' );

	// The status/connect controls may be absent on a fully-connected screen (only the
	// Disconnect button renders then) — bail only when there is no endpoint at all.
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	var polling = false;

	function say( text ) {
		if ( status ) {
			status.textContent = text;
		}
	}

	function post( action ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	// Terminal outcomes (stop) vs pending/confirming (keep polling).
	var MESSAGES = {
		denied: __( 'Connection was declined in Voris. Click Connect to try again.', 'voris' ),
		expired: __( 'The connection request expired. Click Connect to try again.', 'voris' ),
		revoked: __( 'That connection is no longer valid. Click Connect to reconnect.', 'voris' ),
		reconnect: __( 'Please reconnect — sign in as an owner or admin of the workspace.', 'voris' ),
		needs_role: __( 'This connection needs an owner or admin. Restore that role in Voris, then reload this page.', 'voris' ),
		error: __( 'Could not reach Voris just now. Retrying…', 'voris' ),
	};

	function schedule( fn, seconds ) {
		window.setTimeout( fn, Math.max( 1, seconds ) * 1000 );
	}

	function approvalMessage() {
		if ( cfg.userCode ) {
			return sprintf(
				/* translators: %s: the user code to enter on the Voris approval page. */
				__( 'Approve this connection in Voris using the link above (code %s).', 'voris' ),
				cfg.userCode
			);
		}

		return __( 'Approve this connection in Voris using the link above.', 'voris' );
	}

	// Render the approval affordance: a real, focusable link + the visible user code.
	// The reliable path whether or not a pop-up is allowed (VOR-299).
	function showApproval( connectUrl, userCode ) {
		if ( userCode ) {
			cfg.userCode = userCode;
		}

		if ( link && connectUrl ) {
			link.href = connectUrl;
		}
		if ( code && cfg.userCode ) {
			code.textContent = cfg.userCode;
		}
		if ( approve ) {
			approve.hidden = false;
		}
	}

	function stop( d ) {
		polling = false;
		if ( btn ) {
			btn.disabled = false;
		}
		say( MESSAGES[ d.status ] || __( 'Connection ended. Click Connect to try again.', 'voris' ) );
	}

	function poll( interval ) {
		post( 'voris_connect_check' ).then( function ( res ) {
			var d = ( res && res.data ) || {};

			switch ( d.status ) {
				case 'connected':
					// Still finishing (binding / attesting)? Keep polling — do NOT
					// reload, or the loop would be orphaned again (VOR-298).
					if ( d.confirming ) {
						say( __( 'Finishing connecting…', 'voris' ) );
						schedule( function () {
							poll( interval );
						}, interval );
						return;
					}

					say(
						d.tracking_live
							? __( 'Connected. Voris is receiving data from this site.', 'voris' )
							: __( 'Connected. Waiting for the first event…', 'voris' )
					);
					// Reload so the settings screen reflects the connected state.
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
					return;

				case 'pending':
					say( approvalMessage() );
					schedule( function () {
						poll( d.interval || d.retry_after || interval );
					}, d.interval || d.retry_after || interval );
					return;

				case 'error':
					say( MESSAGES.error );
					schedule( function () {
						poll( interval );
					}, interval );
					return;

				default:
					stop( d );
			}
		} ).catch( function () {
			say( MESSAGES.error );
			schedule( function () {
				poll( interval );
			}, interval );
		} );
	}

	// Start a fresh device authorization.
	function begin() {
		if ( polling ) {
			return;
		}
		polling = true;
		if ( btn ) {
			btn.disabled = true;
		}
		say( __( 'Starting…', 'voris' ) );

		post( 'voris_connect_begin' ).then( function ( res ) {
			var d = ( res && res.data ) || {};

			if ( ! d.ok || ! d.connect_url ) {
				polling = false;
				if ( btn ) {
					btn.disabled = false;
				}
				say( __( 'Could not start the connection. Please try again.', 'voris' ) );
				return;
			}

			showApproval( d.connect_url, d.user_code );
			say( approvalMessage() );

			// Best-effort convenience only: open the approval page in a new tab if the
			// browser allows it. Its result is ignored — the link above is the reliable
			// route, so a blocked pop-up strands nothing (VOR-299).
			try {
				window.open( d.connect_url, '_blank', 'noopener' );
			} catch ( e ) {}

			poll( d.interval || 5 );
		} ).catch( function () {
			polling = false;
			if ( btn ) {
				btn.disabled = false;
			}
			say( __( 'Could not start the connection. Please try again.', 'voris' ) );
		} );
	}

	// Resume an in-progress connection (VOR-298): re-show the approval affordance if a
	// device code is still pending, then poll — without re-minting a code.
	function resume() {
		if ( polling ) {
			return;
		}
		polling = true;
		if ( btn ) {
			btn.disabled = true;
		}

		if ( cfg.connectUrl ) {
			showApproval( cfg.connectUrl, cfg.userCode );
			say( approvalMessage() );
		} else {
			say( __( 'Finishing connecting…', 'voris' ) );
		}

		poll( 5 );
	}

	if ( btn ) {
		btn.addEventListener( 'click', function () {
			if ( cfg.resume ) {
				resume();
			} else {
				begin();
			}
		} );
	}

	if ( disconnectBtn ) {
		disconnectBtn.addEventListener( 'click', function () {
			disconnectBtn.disabled = true;
			say( __( 'Disconnecting…', 'voris' ) );

			post( 'voris_connect_disconnect' ).then( function () {
				window.location.reload();
			} ).catch( function () {
				disconnectBtn.disabled = false;
				say( __( 'Could not disconnect just now. Please try again.', 'voris' ) );
			} );
		} );
	}

	// Auto-resume on load so a reload mid-connect keeps working toward completion.
	if ( cfg.resume ) {
		resume();
	}
}() );
