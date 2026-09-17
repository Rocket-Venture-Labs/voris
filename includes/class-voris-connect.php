<?php
/**
 * Connect orchestration + admin-ajax poll (Phase 2, chunk 2).
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives the in-admin "Connect to Voris" flow (ADR-ACH003, C9).
 *
 * The admin clicks Connect (`begin`) → the plugin starts a device authorization and
 * opens the Voris-hosted `/oauth/connect` in a new tab → a JS heartbeat calls
 * `check` (the `wp_ajax_voris_connect_check` endpoint) every advertised interval
 * until the token mints. On mint the flow saves the grant, reads onboarding for the
 * bound `site.{id,domain}`, **writes the site id into `voris_settings` (the single
 * source the tag + WooCommerce path read — otherwise a connected site never
 * tracks)**, attests installed, and reports whether tracking is live yet.
 *
 * Binding is idempotent: if a later `check` finds the grant saved but not yet
 * bound (a transient onboarding-read failure at mint), it re-tries the bind rather
 * than re-polling a spent device code.
 */
final class Voris_Connect {

	const NONCE_ACTION = 'voris_connect';

	/**
	 * Register the ajax endpoints + the connect-screen script.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_voris_connect_begin', array( __CLASS__, 'ajax_begin' ) );
		add_action( 'wp_ajax_voris_connect_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'wp_ajax_voris_connect_disconnect', array( __CLASS__, 'ajax_disconnect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The Voris-hosted connect page for a user code (ADR-ACH003).
	 *
	 * @param string $user_code The device user code.
	 * @return string
	 */
	public static function connect_url( string $user_code ): string {
		return add_query_arg(
			array(
				'client_id' => Voris_Oauth::CLIENT_ID,
				'user_code' => $user_code,
			),
			'https://app.voris.ai/oauth/connect'
		);
	}

	/**
	 * Begin a connection: start the device flow, hand the UI what it needs.
	 *
	 * @return array `{ ok, user_code?, connect_url?, interval?, error? }`.
	 */
	public static function begin(): array {
		$out = Voris_Oauth::start_device_authorization();

		if ( empty( $out['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => isset( $out['error'] ) ? (string) $out['error'] : 'begin_failed',
			);
		}

		return array(
			'ok'          => true,
			'user_code'   => $out['user_code'],
			'connect_url' => self::connect_url( $out['user_code'] ),
			'interval'    => $out['interval'],
		);
	}

	/**
	 * One poll tick from the heartbeat.
	 *
	 * If a grant is already stored but unbound, retry the bind (idempotent) instead
	 * of polling a spent code. Otherwise poll; on mint, save the tokens and bind.
	 *
	 * @return array `{ status, tracking_live?, interval?, retry_after? }`.
	 */
	public static function check(): array {
		if ( Voris_Connection::is_connected() ) {
			// Fully established (bound + attested) short-circuits with no network call.
			// Anything short of that is still connecting — keep finishing the bind
			// and/or retry the attestation (VOR-298), never sit idle.
			if ( Voris_Connection::is_fully_connected() ) {
				return array(
					'status'        => 'connected',
					'tracking_live' => true,
				);
			}

			return self::bind();
		}

		// Not connected: if the device code has passed its advertised window, stop —
		// even if Voris was never reachable to say `expired_token` (VOR-307). The
		// deadline is the runtime `expires_at` (ADR-A107), never a plugin constant.
		if ( Voris_Oauth::is_pending_expired() ) {
			Voris_Oauth::clear_pending();
			return array( 'status' => 'expired' );
		}

		$poll = Voris_Oauth::poll();

		switch ( $poll['outcome'] ) {
			case Voris_Oauth::SUCCESS:
				$token = $poll['token'];
				Voris_Connection::save(
					$token['access_token'],
					$token['refresh_token'],
					(int) $token['expires_in']
				);
				return self::bind();

			case Voris_Oauth::PENDING:
				return array( 'status' => 'pending' );

			case Voris_Oauth::SLOW_DOWN:
				return array(
					'status'   => 'pending',
					'interval' => isset( $poll['interval'] ) ? (int) $poll['interval'] : 10,
				);

			case Voris_Oauth::UNAVAILABLE:
				return array(
					'status'      => 'pending',
					'retry_after' => isset( $poll['retry_after'] ) ? (int) $poll['retry_after'] : 5,
				);

			case Voris_Oauth::DENIED:
				return array( 'status' => 'denied' );

			case Voris_Oauth::REVOKED:
				return array( 'status' => 'revoked' );

			case Voris_Oauth::EXPIRED:
				return array( 'status' => 'expired' );
		}

		return array( 'status' => 'error' ); // Transport hiccup — the heartbeat retries.
	}

	/**
	 * Drive the incomplete connection toward completion, idempotently (VOR-298).
	 *
	 * Re-entrant: safe to call on every heartbeat while the connection is not yet
	 * fully established. It reads onboarding, binds the site + writes the
	 * single-source id the first time it learns it, and attests the install —
	 * retrying a failed attestation on a later tick rather than skipping it. The
	 * distinct in-progress states are:
	 *  - *binding*  — grant good, site not yet confirmed → keep polling (`confirming`).
	 *  - *attesting* — bound + id written, attestation not yet confirmed → retry it.
	 *  - *tracking*  — bound + attested → done.
	 *
	 * @return array
	 */
	private static function bind(): array {
		$onb = Voris_Partner_Api::get( 'onboarding' );

		// A dead grant (RECONNECT) or a bound site that no longer exists (NOT_FOUND,
		// frozen contract: "not_found ⇒ reconnect") both mean this grant can never
		// bind — drop it and ask to reconnect rather than loop in "confirming".
		if ( Voris_Partner_Api::RECONNECT === $onb['status'] || Voris_Partner_Api::NOT_FOUND === $onb['status'] ) {
			Voris_Connection::clear();
			return array( 'status' => 'reconnect' );
		}

		// The connecting user was downgraded below owner/admin: keep the credential
		// (ADR-ACH003) and tell the admin to restore the role, rather than looping in
		// "confirming" forever.
		if ( Voris_Partner_Api::DENIED_ROLE === $onb['status'] ) {
			return array( 'status' => 'needs_role' );
		}

		$ok   = ( Voris_Partner_Api::OK === $onb['status'] );
		$site = ( $ok && ! empty( $onb['body']['site']['id'] ) ) ? $onb['body']['site'] : null;

		// Bind + write the single-source id the first time onboarding reveals the site.
		if ( null !== $site && null === Voris_Connection::bound_site_id() ) {
			Voris_Connection::bind_site( (string) $site['id'], isset( $site['domain'] ) ? (string) $site['domain'] : '' );
			Voris_Settings::set_site_id( (string) $site['id'] );
		}

		if ( null === Voris_Connection::bound_site_id() ) {
			// Grant is good but we could not confirm the site yet — keep it and let
			// the next heartbeat retry the bind. Not an error to the admin.
			return array(
				'status'        => 'connected',
				'tracking_live' => false,
				'confirming'    => true,
			);
		}

		// Bound. Attest once, idempotently; retry on a later tick until it confirms.
		if ( ! Voris_Connection::is_attested() && Voris_Partner_Api::OK === Voris_Partner_Api::post( 'onboarding' )['status'] ) {
			Voris_Connection::mark_attested();
		}

		return array(
			'status'        => 'connected',
			'tracking_live' => $ok && ! empty( $onb['body']['trackingLive'] ),
			'confirming'    => ! Voris_Connection::is_attested(),
		);
	}

	/**
	 * Drop the OAuth connection (VOR-306). The last-bound site id is left in settings
	 * so the admin can keep tracking manually or clear it (C9 manual-fallback); the
	 * manual field becomes editable again because its readonly is driven by the grant,
	 * not the id.
	 *
	 * Also clears account-specific carry-over — the cached plan/usage figures and
	 * dismissed upgrade prompts — so a later connection to a *different* Voris account
	 * does not inherit the old account's state.
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		Voris_Oauth::clear_pending();
		Voris_Connection::clear();
		Voris_Plan::clear();
		Voris_Nudge::clear_dismissals();
	}

	/**
	 * Ajax: begin a connection. Nonce + capability gated (constitution §7/§16).
	 *
	 * @return void
	 */
	public static function ajax_begin(): void {
		self::guard_ajax();
		wp_send_json_success( self::begin() );
	}

	/**
	 * Ajax: one heartbeat poll tick. Nonce + capability gated.
	 *
	 * @return void
	 */
	public static function ajax_check(): void {
		self::guard_ajax();
		wp_send_json_success( self::check() );
	}

	/**
	 * Ajax: disconnect / switch account. Nonce + capability gated (VOR-306, §7).
	 *
	 * @return void
	 */
	public static function ajax_disconnect(): void {
		self::guard_ajax();
		self::disconnect();
		wp_send_json_success( array( 'status' => 'disconnected' ) );
	}

	/**
	 * Refuse an ajax call without a valid nonce AND the manage-options capability.
	 *
	 * @return void
	 */
	private static function guard_ajax(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
	}

	/**
	 * Enqueue the connect heartbeat on the plugin's settings screen only.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( Voris_Admin_Page::HOOK_SUFFIX !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'voris-connect',
			plugins_url( 'assets/js/connect.js', VORIS_PLUGIN_FILE ),
			array( 'wp-i18n' ),
			VORIS_VERSION,
			true
		);

		// Runtime i18n (VOR-308): connect.js renders its status/approval copy through
		// the wp.i18n translation helper; point the voris domain at the shipped
		// languages/ directory so those strings can be localized, the way
		// conventions.md prescribes for enqueued scripts.
		wp_set_script_translations(
			'voris-connect',
			'voris',
			plugin_dir_path( VORIS_PLUGIN_FILE ) . 'languages'
		);

		// Resume state (VOR-298): the heartbeat must re-arm on load — not only on a
		// click — whenever the connection is incomplete (a grant is held but not yet
		// bound + attested) or a device code is still pending, because the flow's own
		// reload would otherwise orphan the poll. When a code is pending, hand the JS
		// the user code + connect URL so it can re-render the approval affordance.
		$pending     = Voris_Oauth::get_pending();
		$has_pending = ( null !== $pending && ! empty( $pending['user_code'] ) && ! Voris_Oauth::is_pending_expired() );
		$incomplete  = Voris_Connection::is_connected() && ! Voris_Connection::is_fully_connected();

		$data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'resume'  => ( $incomplete || $has_pending ),
		);

		if ( $has_pending ) {
			$data['userCode']   = (string) $pending['user_code'];
			$data['connectUrl'] = self::connect_url( (string) $pending['user_code'] );
		}

		wp_localize_script( 'voris-connect', 'vorisConnect', $data );
	}
}
