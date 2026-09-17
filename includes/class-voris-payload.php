<?php
/**
 * Ingest payload construction.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the server-side event payloads the plugin sends to Voris.
 *
 * Only two events exist on this path: `purchase` and `refund`. Pageviews and
 * Core Web Vitals come from the hosted browser tag, which the plugin renders
 * but never constructs payloads for.
 *
 * Every key here is on the client allow list in
 * `.agent/integration/voris-ingest.md`. Fields Voris derives server-side —
 * privacy mode, visitor and session identity, geo, device and user agent,
 * `is_dev`, `server_timestamp` — are absent by construction, and the typed
 * signatures mean `properties` can only ever hold ids, counts and flags.
 */
final class Voris_Payload {

	/**
	 * The contract's literal schema version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * What an order or refund reference may contain.
	 *
	 * Deliberately narrow. `woocommerce_order_number` is filterable, and
	 * sequential-order-number plugins let a shop template the number from
	 * customer fields — so an order "number" can carry PII straight to ingest
	 * and into the failure log. Excluding `@` and whitespace rules out
	 * addresses and names; the length cap bounds anything else. Constitution
	 * §14 says refuse rather than transmit, and §15 says fail closed.
	 *
	 * @var string
	 */
	const REFERENCE_PATTERN = '/^[A-Za-z0-9._-]{1,64}\z/';

	/**
	 * ISO-4217, as the contract requires whenever an amount is present.
	 *
	 * @var string
	 */
	const CURRENCY_PATTERN = '/^[A-Z]{3}\z/';

	/**
	 * Build a purchase event for a completed order.
	 *
	 * @param string $site_id  The site's Voris id (UUID v4).
	 * @param string $order_id Order reference, for reconciliation.
	 * @param float  $amount   Order total.
	 * @param string $currency ISO-4217 currency code.
	 * @param int    $items    Number of line items.
	 * @return array
	 * @throws InvalidArgumentException When the amount is not positive, the currency is not ISO-4217, or the order reference is not a plain reference.
	 */
	public static function purchase( string $site_id, string $order_id, float $amount, string $currency, int $items ): array {
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'A purchase amount must be positive.' );
		}

		$payload = self::base( $site_id, 'purchase' );

		$payload['revenue_amount']   = self::money( $amount );
		$payload['revenue_currency'] = self::currency( $currency );
		$payload['properties']       = array(
			'order_id' => self::reference( $order_id, 'order_id' ),
			'items'    => $items,
		);

		return $payload;
	}

	/**
	 * Build a refund event, reported as a negative revenue correction.
	 *
	 * @param string $site_id   The site's Voris id (UUID v4).
	 * @param string $order_id  Order the refund belongs to.
	 * @param string $refund_id Refund reference.
	 * @param float  $amount    Refunded amount, in any sign.
	 * @param string $currency  ISO-4217 currency code.
	 * @param bool   $partial   Whether this refunds part of the order.
	 * @return array
	 * @throws InvalidArgumentException When the amount is zero, the currency is not ISO-4217, or either reference is not a plain reference.
	 */
	public static function refund( string $site_id, string $order_id, string $refund_id, float $amount, string $currency, bool $partial ): array {
		if ( 0.0 === abs( $amount ) ) {
			throw new InvalidArgumentException( 'A refund amount must be non-zero.' );
		}

		$payload = self::base( $site_id, 'refund' );

		$payload['revenue_amount']   = self::correction_amount( $amount );
		$payload['revenue_currency'] = self::currency( $currency );
		$payload['properties']       = array(
			'order_id'  => self::reference( $order_id, 'order_id' ),
			'refund_id' => self::reference( $refund_id, 'refund_id' ),
			'partial'   => $partial,
		);

		return $payload;
	}

	/**
	 * The fields every event carries, in contract order.
	 *
	 * @param string $site_id    The site's Voris id.
	 * @param string $event_name Event name, 1–64 characters.
	 * @return array
	 */
	private static function base( string $site_id, string $event_name ): array {
		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'event_id'          => Voris_Ulid::generate(),
			'site_id'           => $site_id,
			'event_name'        => $event_name,
			'timestamp'         => gmdate( 'c' ),

			/*
			 * The Voris client schema requires EVERY client field present — each is
			 * `.nullable()`, not `.optional()`, so a missing key is a 400
			 * `validation_failed` that classifies as fatal and drops the event for good.
			 * A server-side commerce event has no page, referrer, UTM or Core Web Vitals
			 * context, so those are null. `hostname` is the exception: it is the site's
			 * own host, sent so Voris can classify a dev/preview store out of billing
			 * (contract §Required fields; ach001-amendments Phase 1). `extensions` is the
			 * one `.optional()` field and is deliberately omitted.
			 */
			'url'               => null,
			'hostname'          => self::site_host(),
			'pathname'          => null,
			'referrer_hostname' => null,
			'referrer_url'      => null,
			'screen_name'       => null,
			'app_version'       => null,
			'utm_source'        => null,
			'utm_medium'        => null,
			'utm_campaign'      => null,
			'utm_content'       => null,
			'utm_term'          => null,
			'lcp_ms'            => null,
			'inp_ms'            => null,
			'cls'               => null,
			'ttfb_ms'           => null,
			'fcp_ms'            => null,
		);
	}

	/**
	 * The site's own host, for the required `hostname` field.
	 *
	 * Voris uses it to classify a dev/preview/staging store out of billing, so it
	 * must be the store's real host — never null on a normal install. Falls back to
	 * null only if `home_url()` yields no parseable host (schema-valid, but the site
	 * then cannot be dev-classified).
	 *
	 * @return string|null
	 */
	private static function site_host(): ?string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return ( is_string( $host ) && '' !== $host ) ? $host : null;
	}

	/**
	 * Decide the sign of a revenue correction.
	 *
	 * Isolated deliberately: ingest accepts a negative `revenue_amount`, but if
	 * Voris-side rollups ever mishandle negatives the fallback is to send a
	 * positive amount and resolve the sign from `event_name`. That change lands
	 * here and nowhere else.
	 *
	 * @param float $amount Refunded amount, in any sign.
	 * @return float
	 */
	private static function correction_amount( float $amount ): float {
		return -self::money( $amount );
	}

	/**
	 * Round an amount to the minor unit before it reaches the wire.
	 *
	 * PHP's default `serialize_precision` of -1 emits the shortest
	 * round-tripping representation, so 19.99 travels as `19.99`. Hosts that
	 * set `serialize_precision=17` — a legal value — get `19.989999999999998`
	 * instead. That is still valid JSON, so nothing rejects it; it is simply
	 * the wrong amount of money in the dashboard.
	 *
	 * @param float $amount Raw amount.
	 * @return float
	 */
	private static function money( float $amount ): float {
		return round( abs( $amount ), 2 );
	}

	/**
	 * Normalise a currency code to the ISO-4217 uppercase form.
	 *
	 * @param string $currency Raw currency code.
	 * @return string
	 * @throws InvalidArgumentException When the code is not ISO-4217.
	 */
	private static function currency( string $currency ): string {
		$normalised = strtoupper( trim( $currency ) );

		/*
		 * The contract requires a currency whenever an amount is present, so an
		 * empty or malformed code guarantees a 400 — which classifies as fatal
		 * and drops the revenue event for good, blaming Voris for a local
		 * fault. Refuse here instead, where the cause is still visible.
		 */
		if ( 1 !== preg_match( self::CURRENCY_PATTERN, $normalised ) ) {
			throw new InvalidArgumentException( 'A revenue amount requires an ISO-4217 currency code.' );
		}

		return $normalised;
	}

	/**
	 * Validate an order or refund reference before it reaches ingest.
	 *
	 * Refuses rather than redacts: stripping the offending characters out of
	 * something like an order number templated from a customer email would
	 * leave the local part behind and still transmit it.
	 *
	 * @param string $value Reference to check.
	 * @param string $field Field name, for the exception message.
	 * @return string
	 * @throws InvalidArgumentException When the reference is empty, too long, or carries anything but a plain reference.
	 */
	private static function reference( string $value, string $field ): string {
		if ( 1 !== preg_match( self::REFERENCE_PATTERN, $value ) ) {
			throw new InvalidArgumentException( esc_html( "The {$field} is not a plain reference and will not be transmitted." ) );
		}

		return $value;
	}
}
