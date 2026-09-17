<?php
/**
 * Partner read client (Phase 2, chunks 2–3).
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bearer reads against the Voris partner surface on the Authorization-Server host
 * (`app.voris.ai`, NOT `mcp.voris.ai`).
 *
 * A read that returns `401 unauthorized` is a transiently-expired access token, not
 * a dead grant: the client refreshes once and retries. A credential is dropped ONLY
 * when the refresh itself is `invalid_grant` (dead) or the retry is still
 * unauthorized — reconnect is learned from renewal, never from a single denied read
 * (`partner-oauth-api.md`). Every other error is surfaced as a code the caller maps
 * to admin copy; nothing is logged (constitution §11).
 */
final class Voris_Partner_Api {

	const BASE    = 'https://app.voris.ai/api/partner';
	const TIMEOUT = 5;

	// Read outcomes (the `status` a caller switches on).
	const OK          = 'ok';
	const RECONNECT   = 'reconnect';   // Dead grant / still-unauthorized after renewal ⇒ reconnect.
	const DENIED_ROLE = 'denied_role'; // Below owner/admin ⇒ WAIT: restore the role, keep the credential.
	const NOT_FOUND   = 'not_found';   // Bound site gone ⇒ reconnect.
	const UNAVAILABLE = 'unavailable'; // slow_down / temporarily_unavailable / 5xx / transport ⇒ retry later.

	/**
	 * GET a partner endpoint, refreshing once on a 401.
	 *
	 * @param string $path Endpoint path under /api/partner (e.g. 'plan', 'onboarding').
	 * @return array `{ status, body?: array, code?: int }`.
	 */
	public static function get( string $path ): array {
		return self::request( 'GET', $path );
	}

	/**
	 * POST a partner endpoint (no body), refreshing once on a 401.
	 *
	 * @param string $path Endpoint path under /api/partner.
	 * @return array `{ status, body?: array, code?: int }`.
	 */
	public static function post( string $path ): array {
		return self::request( 'POST', $path );
	}

	/**
	 * Perform a bearer request with a single refresh-and-retry on 401.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Endpoint path.
	 * @return array
	 */
	private static function request( string $method, string $path ): array {
		$token = Voris_Connection::access_token();

		if ( null === $token ) {
			return array( 'status' => self::RECONNECT );
		}

		$res = self::send( $method, $path, $token );

		if ( 401 === $res['code'] ) {
			$refresh = Voris_Oauth::refresh();

			if ( ! empty( $refresh['dead'] ) ) {
				return array( 'status' => self::RECONNECT ); // Renewal says the grant is dead.
			}

			if ( empty( $refresh['ok'] ) ) {
				return array( 'status' => self::UNAVAILABLE ); // Couldn't refresh (busy/transient) — retry later, keep the credential.
			}

			$token = Voris_Connection::access_token();
			if ( null === $token ) {
				return array( 'status' => self::RECONNECT );
			}

			$res = self::send( $method, $path, $token );
		}

		return self::classify( $res );
	}

	/**
	 * Map an HTTP result to a read outcome.
	 *
	 * @param array $res A `send` result.
	 * @return array
	 */
	private static function classify( array $res ): array {
		$code  = (int) $res['code'];
		$body  = is_array( $res['body'] ) ? $res['body'] : array();
		$error = isset( $body['error'] ) ? (string) $body['error'] : '';

		if ( 200 === $code ) {
			return array(
				'status' => self::OK,
				'body'   => $body,
				'code'   => 200,
			);
		}

		if ( 401 === $code ) {
			return array( 'status' => self::RECONNECT ); // Still unauthorized after a good refresh.
		}

		if ( 403 === $code ) {
			// denied_role ⇒ wait (keep the credential); denied_scope ⇒ reconnect.
			return array( 'status' => 'denied_role' === $error ? self::DENIED_ROLE : self::RECONNECT );
		}

		if ( 404 === $code || 'not_found' === $error ) {
			return array( 'status' => self::NOT_FOUND );
		}

		// slow_down / temporarily_unavailable / 5xx / transport failure.
		return array( 'status' => self::UNAVAILABLE );
	}

	/**
	 * Send one bearer request. Pinned TLS, no redirects, short timeout, no logging.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Endpoint path.
	 * @param string $token  Access token.
	 * @return array `{ code: int, body: array|null }`.
	 */
	private static function send( string $method, string $path, string $token ): array {
		Voris_Transport::pin_ssl_verification();

		$url  = self::BASE . '/' . ltrim( $path, '/' );
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		$response = ( 'POST' === $method ) ? wp_safe_remote_post( $url, $args ) : wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code' => 0,
				'body' => null,
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => is_array( $decoded ) ? $decoded : null,
		);
	}
}
