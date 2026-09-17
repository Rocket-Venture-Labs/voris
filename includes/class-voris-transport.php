<?php
/**
 * Ingest transport.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Posts a single event to Voris and classifies what came back.
 *
 * The endpoint is hard-wired (C1) — no setting, constant or filter overrides
 * it. Batching is deliberately unused: a batch response carries no per-event
 * status, which would make the never-retry-on-400 rule unimplementable per
 * event (ADR-ACH001).
 *
 * Nothing here logs or echoes the payload or the site id (constitution §11);
 * the caller receives a classification, a status code, and a safe error slug.
 */
final class Voris_Transport {

	/**
	 * Delivered. Stop.
	 *
	 * @var string
	 */
	const SUCCESS = 'success';

	/**
	 * The request itself is wrong. Retrying cannot help.
	 *
	 * @var string
	 */
	const FATAL = 'fatal';

	/**
	 * Voris was unreachable or unwell. Try again later.
	 *
	 * @var string
	 */
	const RETRYABLE = 'retryable';

	/**
	 * Seconds to wait for Voris before giving up (constitution §16).
	 *
	 * @var int
	 */
	const TIMEOUT = 2;

	/**
	 * The registered server-sender slug (Voris `REGISTERED_SERVER_SENDERS`).
	 *
	 * @var string
	 */
	const SENDER_SLUG = 'voris-wordpress-plugin';

	/**
	 * The one 429 rejection that is terminal, not retryable: a Free-plan cap.
	 *
	 * @var string
	 */
	const REJECTION_AT_CAP = 'event_limit_reached';

	/**
	 * Where events go. Hard-wired by C1.
	 *
	 * A class constant, not a `define()`: global constants keep whichever value
	 * was set first, so wp-config.php or an mu-plugin could pre-empt one and
	 * redirect every payload — and the site id with it — to another host. This
	 * cannot be pre-empted. WooCommerce declares its own remote endpoints the
	 * same way.
	 *
	 * @var string
	 */
	private const INGEST_URL = 'https://ingest.voris.ai';

	/**
	 * The Authorization-Server host. OAuth + partner reads (Phase 2) go here; SSL
	 * pinning must cover it too, not just ingest.
	 *
	 * @var string
	 */
	private const APP_URL = 'https://app.voris.ai';

	/**
	 * The single-event ingest endpoint.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		return self::INGEST_URL . '/api/event';
	}

	/**
	 * Force certificate verification on for ingest requests.
	 *
	 * `WP_Http` applies `http_request_args` after merging defaults, so a site
	 * running the common "disable sslverify to fix my SSL errors" snippet would
	 * silently downgrade ingest to unverified TLS — exposing revenue data and
	 * the site id to interception. Scoped to this endpoint only; other plugins'
	 * requests are none of our business.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function force_ssl_verification( $args, $url ): array {
		if ( self::is_voris_host( $url ) ) {
			$args['sslverify'] = true;
		}

		return $args;
	}

	/**
	 * Force the certificate bundle back on for ingest requests.
	 *
	 * `http_request_args` alone is not enough. `WP_Http::request()` translates
	 * `sslverify` into the transport's `verify` option and *then* applies
	 * `https_ssl_verify` — so `add_filter( 'https_ssl_verify', '__return_false' )`,
	 * the most widely copy-pasted "fix my SSL errors" snippet there is, runs
	 * after everything above it and silently downgrades ingest to unverified
	 * TLS. cURL re-applies the same filter again for good measure.
	 *
	 * Scoped to the ingest URL: other plugins' requests are their own business.
	 *
	 * @param mixed  $verify Path to a CA bundle, or a boolean.
	 * @param string $url    Request URL.
	 * @return mixed
	 */
	public static function force_ssl_certificate( $verify, $url = '' ) {
		if ( ! self::is_voris_host( $url ) || $verify ) {
			return $verify;
		}

		return ABSPATH . WPINC . '/certificates/ca-bundle.crt';
	}

	/**
	 * Move our TLS filters to the back of the queue, immediately before a send.
	 *
	 * Registering at `PHP_INT_MAX` once at bootstrap is not enough: WordPress
	 * appends within a priority bucket, so anything registered later at the
	 * same priority runs after us and wins. Re-adding here means nothing can
	 * outlive our filter for the request that matters.
	 *
	 * @return void
	 */
	public static function pin_ssl_verification(): void {
		$filters = array(
			'http_request_args'      => 'force_ssl_verification',
			'https_ssl_verify'       => 'force_ssl_certificate',
			'https_local_ssl_verify' => 'force_ssl_certificate',
		);

		foreach ( $filters as $hook => $method ) {
			remove_filter( $hook, array( __CLASS__, $method ), PHP_INT_MAX );
			add_filter( $hook, array( __CLASS__, $method ), PHP_INT_MAX, 2 );
		}
	}

	/**
	 * Whether a URL points at a Voris host we pin (ingest or the AS).
	 *
	 * Compares the host rather than a string prefix, so
	 * `https://ingest.voris.ai.example.com` is not mistaken for the endpoint.
	 *
	 * @param mixed $url Candidate URL.
	 * @return bool
	 */
	private static function is_voris_host( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return wp_parse_url( self::INGEST_URL, PHP_URL_HOST ) === $host
			|| wp_parse_url( self::APP_URL, PHP_URL_HOST ) === $host;
	}

	/**
	 * Send one event.
	 *
	 * @param array $payload A contract-conformant event payload.
	 * @return array `{ result: string, code: int, error: string }`.
	 */
	public static function send( array $payload ): array {
		self::pin_ssl_verification();

		$body = wp_json_encode( $payload );

		/*
		 * Encoding fails on INF, NAN, or invalid UTF-8 in an order reference.
		 * Sending an empty body would earn a 400 and look like a Voris-side
		 * validation failure, so name the real cause and never retry it.
		 */
		if ( false === $body ) {
			return self::result( self::FATAL, 0, 'encode_failed' );
		}

		$response = wp_safe_remote_post(
			self::endpoint(),
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'   => 'application/json',
					'X-Voris-Sender' => self::sender(),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( self::RETRYABLE, 0, $response->get_error_code() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return self::result( self::classify( $code, self::rejection_code( $response ) ), $code, '' );
	}

	/**
	 * The `X-Voris-Sender` header value: the registered slug plus this version.
	 *
	 * Marks the event as server-originated (ADR-A120), so Voris classifies it as a
	 * distinct server class — country-only, no visitor/session, and crucially NOT
	 * User-Agent-parsed. Without it the WordPress `wp_remote_post` User-Agent is
	 * parsed and classified as a bot, and the revenue event is dropped (the AC 8/9
	 * gap). The slug must be in Voris's registered sender set or ingest 400s.
	 *
	 * @return string
	 */
	private static function sender(): string {
		$version = defined( 'VORIS_VERSION' ) ? VORIS_VERSION : '0';

		return self::SENDER_SLUG . '/' . $version;
	}

	/**
	 * The Voris rejection `code` from a response body, or '' when there is none.
	 *
	 * Read only to tell an at-cap 429 (`event_limit_reached`, terminal for the
	 * billing window) from a rate-limit 429 (`rate_limited`, retryable). Never
	 * logged or echoed (constitution §11).
	 *
	 * @param array $response A WP HTTP response array.
	 * @return string
	 */
	private static function rejection_code( $response ): string {
		$body = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		$decoded = json_decode( $body, true );

		return ( is_array( $decoded ) && isset( $decoded['code'] ) && is_string( $decoded['code'] ) )
			? $decoded['code']
			: '';
	}

	/**
	 * Map an HTTP status (and any rejection code) onto a delivery decision.
	 *
	 * `400` (bad payload) and `404` (unknown site id) never improve by being sent
	 * again. A `429 event_limit_reached` is a Free-plan cap — terminal for the
	 * billing window — so it is fatal too. Every other non-success (a bare `429`,
	 * a `429 rate_limited`, a `5xx`, or any status the contract does not name) is
	 * worth one more bounded attempt.
	 *
	 * @param int    $code      HTTP status code.
	 * @param string $rejection Voris rejection code from the body, or '' when none.
	 * @return string One of the class constants.
	 */
	public static function classify( int $code, string $rejection = '' ): string {
		if ( $code >= 200 && $code < 300 ) {
			return self::SUCCESS;
		}

		if ( 400 === $code || 404 === $code ) {
			return self::FATAL;
		}

		/*
		 * A 429 `event_limit_reached` is a Free-plan cap: terminal for the billing
		 * window, so retrying only burns attempts and never succeeds until the
		 * window resets. A 429 `rate_limited` (or a 429 with no code, or any 5xx)
		 * stays retryable. (Contract "rejection codes"; ach001-amendments Phase 1.)
		 */
		if ( 429 === $code && self::REJECTION_AT_CAP === $rejection ) {
			return self::FATAL;
		}

		return self::RETRYABLE;
	}

	/**
	 * Shape a result. Deliberately carries no payload and no site id.
	 *
	 * @param string $result One of the class constants.
	 * @param int    $code   HTTP status, or 0 for a transport failure.
	 * @param string $error  WordPress error slug, or an empty string.
	 * @return array
	 */
	private static function result( string $result, int $code, string $error ): array {
		return array(
			'result' => $result,
			'code'   => $code,
			'error'  => $error,
		);
	}
}
