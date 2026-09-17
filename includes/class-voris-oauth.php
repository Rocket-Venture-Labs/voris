<?php
/**
 * OAuth 2.1 device-flow client (Phase 2, chunk 1).
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives the device grant against the Voris Authorization Server (ADR-ACH003).
 *
 * The client is public (no secret): it starts a `device_authorization`, the admin
 * approves on the Voris-hosted page, and the plugin polls `token` until it mints.
 * Every outcome the frozen `partner-oauth-api.md` defines is modelled — the three
 * TERMINAL ones (`denied`, `expired`, `revoked`) stop the poll, the transient ones
 * (`pending`, `slow_down`, `unavailable`) keep it going.
 *
 * Refresh tokens ROTATE on use: a successful refresh returns a new pair that must
 * atomically replace the old one, and a stale refresh token must never be
 * re-presented (Voris treats reuse as theft and revokes the whole grant family).
 * A fencing lock serialises refreshes so two in-flight renewals cannot both spend
 * — and then re-present — the same refresh token.
 *
 * All requests go to `app.voris.ai` over pinned TLS (`Voris_Transport`), with
 * redirects disabled and a short timeout, and never block a page or checkout.
 */
final class Voris_Oauth {

	const CLIENT_ID     = 'voris-wordpress-plugin';
	const DEVICE_GRANT  = 'urn:ietf:params:oauth:grant-type:device_code';
	const REFRESH_GRANT = 'refresh_token';
	const SCOPE         = 'analytics:read';

	/** Where the connect state lives while the admin approves (non-autoloaded). */
	const PENDING_OPTION = 'voris_connect_pending';

	/** Serialises refreshes so a rotated token is never double-spent. */
	const REFRESH_LOCK = 'voris_refresh_lock';

	const TIMEOUT = 5;

	// Poll outcomes.
	const SUCCESS     = 'success';     // Minted — token payload attached.
	const PENDING     = 'pending';     // Not approved yet — keep polling.
	const SLOW_DOWN   = 'slow_down';   // Poll too fast — interval bumped, keep polling.
	const UNAVAILABLE = 'unavailable'; // Voris briefly paused — keep the code, keep polling.
	const DENIED      = 'denied';      // Admin denied — TERMINAL.
	const EXPIRED     = 'expired';     // Device code expired (1800s) — TERMINAL.
	const REVOKED     = 'revoked';     // invalid_grant: grant/site gone mid-flow — TERMINAL.
	const ERROR       = 'error';       // Transport / unexpected — retry the request, not the flow.

	/**
	 * The device-authorization endpoint.
	 *
	 * @return string
	 */
	private static function device_authorization_url(): string {
		return 'https://app.voris.ai/oauth/device_authorization';
	}

	/**
	 * The token endpoint (poll + refresh).
	 *
	 * @return string
	 */
	private static function token_url(): string {
		return 'https://app.voris.ai/oauth/token';
	}

	/**
	 * Begin a device authorization and store the pending state.
	 *
	 * The `interval` and `expires_in` in the response are RUNTIME values (never a
	 * plugin constant): the poll loop derives its cadence and its stop time from
	 * them (ADR-A107), so a longer/shorter window from Voris is honored.
	 *
	 * @return array `{ ok: bool, user_code?, verification_uri?, interval?, error? }`.
	 */
	public static function start_device_authorization(): array {
		$res = self::post_form(
			self::device_authorization_url(),
			array(
				'client_id' => self::CLIENT_ID,
				'scope'     => self::SCOPE,
			)
		);

		$body = $res['body'];

		if ( 200 !== $res['code'] || ! is_array( $body ) || empty( $body['device_code'] ) || empty( $body['user_code'] ) ) {
			return array(
				'ok'    => false,
				'error' => is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'device_authorization_failed',
			);
		}

		$interval = isset( $body['interval'] ) ? max( 1, (int) $body['interval'] ) : 5;
		$expires  = isset( $body['expires_in'] ) ? max( 1, (int) $body['expires_in'] ) : 1800;

		self::set_pending(
			array(
				'device_code' => (string) $body['device_code'],
				'user_code'   => (string) $body['user_code'],
				'expires_at'  => time() + $expires,
				'interval'    => $interval,
			)
		);

		return array(
			'ok'               => true,
			'user_code'        => (string) $body['user_code'],
			'verification_uri' => isset( $body['verification_uri'] ) ? (string) $body['verification_uri'] : 'https://app.voris.ai/device',
			'interval'         => $interval,
		);
	}

	/**
	 * Poll the token endpoint once for the pending device code.
	 *
	 * Does NOT persist the grant on success — it returns the token payload so the
	 * connect flow can read the bound site (onboarding) and save + bind together.
	 * Terminal outcomes clear the pending state.
	 *
	 * @return array `{ outcome, token?, interval?, retry_after? }`.
	 */
	public static function poll(): array {
		$pending = self::get_pending();

		if ( null === $pending || empty( $pending['device_code'] ) ) {
			return array( 'outcome' => self::EXPIRED ); // Nothing in flight — treat as a finished/expired attempt.
		}

		$res = self::post_form(
			self::token_url(),
			array(
				'grant_type'  => self::DEVICE_GRANT,
				'device_code' => (string) $pending['device_code'],
				'client_id'   => self::CLIENT_ID,
			)
		);

		$body  = is_array( $res['body'] ) ? $res['body'] : array();
		$error = isset( $body['error'] ) ? (string) $body['error'] : '';

		if ( 200 === $res['code'] && ! empty( $body['access_token'] ) ) {
			self::clear_pending();
			return array(
				'outcome' => self::SUCCESS,
				'token'   => array(
					'access_token'  => (string) $body['access_token'],
					'refresh_token' => isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '',
					'expires_in'    => isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 900,
					'scope'         => isset( $body['scope'] ) ? (string) $body['scope'] : self::SCOPE,
				),
			);
		}

		switch ( $error ) {
			case 'authorization_pending':
				return array( 'outcome' => self::PENDING );

			case 'slow_down':
				$interval            = isset( $pending['interval'] ) ? (int) $pending['interval'] + 5 : 10;
				$pending['interval'] = $interval;
				self::set_pending( $pending );
				return array(
					'outcome'  => self::SLOW_DOWN,
					'interval' => $interval,
				);

			case 'access_denied':
				self::clear_pending();
				return array( 'outcome' => self::DENIED );

			case 'expired_token':
				self::clear_pending();
				return array( 'outcome' => self::EXPIRED );

			case 'invalid_grant':
				self::clear_pending();
				return array( 'outcome' => self::REVOKED );

			case 'temporarily_unavailable':
				return array(
					'outcome'     => self::UNAVAILABLE,
					'retry_after' => self::retry_after( $res ),
				);
		}

		// A transport failure or an unnamed status: retry the REQUEST later, not
		// the whole flow — keep the pending code.
		return array( 'outcome' => self::ERROR );
	}

	/**
	 * Renew the access token, rotating the refresh token.
	 *
	 * Serialised by a fencing lock: if another refresh holds it, this call yields
	 * `busy` rather than spending the (now soon-to-be-stale) refresh token a second
	 * time. On success the stored grant is rotated in place; `invalid_grant` means
	 * the grant is dead (reconnect) — reported, not silently cleared.
	 *
	 * @return array `{ ok: bool, dead?: bool, busy?: bool }`.
	 */
	public static function refresh(): array {
		$refresh_token = Voris_Connection::refresh_token();

		if ( null === $refresh_token ) {
			return array(
				'ok'   => false,
				'dead' => true,
			);
		}

		if ( ! self::acquire_refresh_lock() ) {
			return array(
				'ok'   => false,
				'busy' => true,
			);
		}

		try {
			/*
			 * Re-read under the lock — AUTHORITATIVELY. `get_option()` is served
			 * from the object cache, which this request populated before taking
			 * the lock (the pre-lock existence check above). A concurrent worker
			 * that just rotated updated the DB (and, with a persistent cache, the
			 * shared layer) but never invalidated THIS request's runtime cache, so
			 * a plain re-read would return the now-superseded token — and
			 * re-presenting it is the reuse Voris punishes with a grant-family
			 * revoke (VOR-300). Bust the cache so the re-read hits the row.
			 */
			wp_cache_delete( Voris_Connection::OPTION, 'options' );
			$refresh_token = Voris_Connection::refresh_token();
			if ( null === $refresh_token ) {
				return array(
					'ok'   => false,
					'dead' => true,
				);
			}

			$res  = self::post_form(
				self::token_url(),
				array(
					'grant_type'    => self::REFRESH_GRANT,
					'refresh_token' => $refresh_token,
					'client_id'     => self::CLIENT_ID,
				)
			);
			$body = is_array( $res['body'] ) ? $res['body'] : array();

			if ( 200 === $res['code'] && ! empty( $body['access_token'] ) && ! empty( $body['refresh_token'] ) ) {
				Voris_Connection::rotate(
					(string) $body['access_token'],
					(string) $body['refresh_token'],
					isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 900
				);
				return array( 'ok' => true );
			}

			if ( 'invalid_grant' === ( isset( $body['error'] ) ? (string) $body['error'] : '' ) ) {
				return array(
					'ok'   => false,
					'dead' => true,
				);
			}

			// Transient (temporarily_unavailable / 5xx / transport): not dead, retry later.
			return array( 'ok' => false );
		} finally {
			self::release_refresh_lock();
		}
	}

	/**
	 * Whether the in-flight device code has passed its advertised window.
	 *
	 * The stop time is the RUNTIME `expires_at` recorded from the device-auth
	 * response (ADR-A107) — never a plugin constant — so the poll loop runs the
	 * full window Voris advertised.
	 *
	 * @return bool
	 */
	public static function is_pending_expired(): bool {
		$pending = self::get_pending();

		return null === $pending || empty( $pending['expires_at'] ) || time() >= (int) $pending['expires_at'];
	}

	/**
	 * The pending connect state, or null.
	 *
	 * @return array|null
	 */
	public static function get_pending(): ?array {
		$p = get_option( self::PENDING_OPTION, null );

		return is_array( $p ) ? $p : null;
	}

	/**
	 * Store the pending connect state (non-autoloaded).
	 *
	 * @param array $pending State.
	 * @return void
	 */
	private static function set_pending( array $pending ): void {
		if ( false === get_option( self::PENDING_OPTION, false ) ) {
			add_option( self::PENDING_OPTION, $pending, '', false );
			return;
		}

		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Clear the pending connect state.
	 *
	 * @return void
	 */
	public static function clear_pending(): void {
		delete_option( self::PENDING_OPTION );
	}

	/**
	 * Acquire the refresh fencing lock, or fail if another session holds it.
	 *
	 * Uses a MySQL advisory lock (`GET_LOCK`), which is ATOMIC across DB sessions.
	 * A `get_transient()`/`set_transient()` pair is not: two concurrent refreshes
	 * could both observe the lock free, both set it, and both spend the same
	 * refresh token — a reuse the server punishes with a grant-family revoke.
	 * Timeout 0 makes this a non-blocking try-lock; the lock auto-releases when
	 * the request's DB connection closes, so a fatal cannot strand it.
	 *
	 * @return bool
	 */
	private static function acquire_refresh_lock(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An advisory lock, not a cacheable table read; atomicity is the point.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::refresh_lock_name(), 0 ) );

		return '1' === (string) $got;
	}

	/**
	 * Release the refresh fencing lock.
	 *
	 * @return void
	 */
	private static function release_refresh_lock(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releasing the advisory lock acquired above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::refresh_lock_name() ) );
	}

	/**
	 * The advisory-lock name, scoped to this install.
	 *
	 * `GET_LOCK` names are global to the MySQL SERVER, not the database, so two
	 * WordPress installs sharing one server would serialise on — and could
	 * release — each other's refresh lock (VOR-300). Scope it to this install by
	 * its DB name + table prefix. Hashed to stay within GET_LOCK's 64-character
	 * limit and free of characters a lock name cannot hold.
	 *
	 * @return string
	 */
	public static function refresh_lock_name(): string {
		global $wpdb;

		$scope = ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix;

		return self::REFRESH_LOCK . '_' . substr( md5( $scope ), 0, 16 );
	}

	/**
	 * `Retry-After` seconds from a response, defaulting to the interval floor.
	 *
	 * @param array $res A `post_form` result.
	 * @return int
	 */
	private static function retry_after( array $res ): int {
		$header = isset( $res['retry_after'] ) ? (int) $res['retry_after'] : 0;

		return $header > 0 ? $header : 5;
	}

	/**
	 * POST a form-urlencoded body to a Voris URL over pinned TLS, parse JSON.
	 *
	 * Re-pins TLS immediately before the request (a hostile `https_ssl_verify`
	 * filter registered later would otherwise win), disables redirects, and never
	 * follows to another host. The site id / tokens are never logged.
	 *
	 * @param string $url    Target (an app.voris.ai endpoint).
	 * @param array  $params Form fields.
	 * @return array `{ code: int, body: array|null, retry_after?: int }`.
	 */
	private static function post_form( string $url, array $params ): array {
		Voris_Transport::pin_ssl_verification();

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'        => $params, // wp_remote_post URL-encodes an array body.
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code' => 0,
				'body' => null,
			);
		}

		$code        = (int) wp_remote_retrieve_response_code( $response );
		$decoded     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		return array(
			'code'        => $code,
			'body'        => is_array( $decoded ) ? $decoded : null,
			'retry_after' => $retry_after,
		);
	}
}
