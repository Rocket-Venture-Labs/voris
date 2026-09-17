<?php
/**
 * ULID generation.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates the `event_id` values Voris uses as an idempotency key.
 *
 * A ULID is 26 Crockford base32 characters: a 48-bit millisecond timestamp in
 * the first 10, then 80 random bits in the remaining 16. Because the timestamp
 * leads, an id minted in a later millisecond sorts after an earlier one.
 * Within a single millisecond the order is random — ADR-ACH002 accepted that
 * deliberately, since `event_id` is an idempotency key and never a sort key.
 * The contract lives in `.agent/integration/voris-ingest.md`.
 *
 * Implemented in-repo rather than pulled from Composer — see ADR-ACH002.
 */
final class Voris_Ulid {

	/**
	 * Crockford base32. `I`, `L`, `O` and `U` are deliberately absent.
	 *
	 * @var string
	 */
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Generate a ULID.
	 *
	 * @return string 26-character Crockford base32 identifier.
	 */
	public static function generate(): string {
		$time_ms = (int) ( microtime( true ) * 1000 );

		return self::encode_time( $time_ms ) . self::encode_randomness();
	}

	/**
	 * Encode a millisecond timestamp as the 10-character prefix.
	 *
	 * Ten characters hold 50 bits; a 48-bit timestamp leaves the top two bits
	 * zero, which is what keeps the prefix sortable.
	 *
	 * @param int $time_ms Milliseconds since the Unix epoch.
	 * @return string
	 */
	private static function encode_time( int $time_ms ): string {
		$encoded = '';

		for ( $i = 0; $i < 10; $i++ ) {
			$encoded   = self::ALPHABET[ $time_ms & 31 ] . $encoded;
			$time_ms >>= 5;
		}

		return $encoded;
	}

	/**
	 * Encode 80 bits of entropy as the 16-character suffix.
	 *
	 * @return string
	 */
	private static function encode_randomness(): string {
		$bits = '';

		foreach ( str_split( random_bytes( 10 ) ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}

		$encoded = '';

		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$encoded .= self::ALPHABET[ bindec( $chunk ) ];
		}

		return $encoded;
	}
}
