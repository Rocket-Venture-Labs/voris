<?php
/**
 * Retry queue and failure log.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds events whose first delivery attempt failed, plus a short log of the
 * ones that were given up on.
 *
 * Both live in non-autoloaded options rather than a custom table: revenue
 * events arrive at order-completion volume, and the Options API costs no
 * schema, no migration, and no uninstall SQL (constitution §8, ADR-ACH001).
 * Both are bounded — a store that can grow without limit is a worse failure
 * than a dropped event.
 *
 * The log deliberately records references, never payloads: no site id, no
 * revenue figure, nothing that could carry customer data (§11, §14).
 */
final class Voris_Queue {

	/**
	 * Queued events awaiting another attempt.
	 *
	 * @var string
	 */
	const QUEUE_OPTION = 'voris_event_queue';

	/**
	 * Permanently failed deliveries, for the owner to inspect (AC10).
	 *
	 * @var string
	 */
	const LOG_OPTION = 'voris_failure_log';

	/**
	 * Most events held at once. Overflow drops the oldest.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Most failures remembered at once.
	 *
	 * @var int
	 */
	const MAX_LOG_ENTRIES = 50;

	/**
	 * Every queued entry, oldest first.
	 *
	 * @return array
	 */
	public static function all(): array {
		return self::read_entries();
	}

	/**
	 * Queue an event for another attempt.
	 *
	 * When the queue is already full the oldest entry is dropped to the
	 * failure log: the freshest revenue signal is the one worth keeping, and
	 * the log preserves the audit trail.
	 *
	 * @param array $event           The full event payload, id included.
	 * @param int   $attempts        Attempts made so far.
	 * @param int   $next_attempt_at Unix timestamp of the next attempt.
	 * @return void
	 */
	public static function enqueue( array $event, int $attempts, int $next_attempt_at ): void {
		/*
		 * One entry per event id. A retry replaces its predecessor rather than
		 * queueing alongside it, so `attempts` stays authoritative and the
		 * 3-attempt bound cannot be reopened by a duplicate.
		 */
		$event_id = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';
		$entries  = array_values(
			array_filter(
				self::all(),
				static function ( $entry ) use ( $event_id ) {
					return (string) $entry['event']['event_id'] !== $event_id;
				}
			)
		);

		$excess = ( count( $entries ) + 1 ) - self::MAX_ENTRIES;

		for ( $i = 0; $i < $excess; $i++ ) {
			$dropped = array_shift( $entries );
			self::log_failure( $dropped['event'], 'queue_overflow', 0 );

			/*
			 * Eviction is a permanent failure like any other, and listeners
			 * depend on hearing about those: without this the WooCommerce
			 * integration never clears the order's pending marker, leaving it
			 * unreportable for good and every later refund skipped
			 * (ADR-MG001).
			 */
			do_action( 'voris_event_failed', $dropped['event'], 'queue_overflow', 0 );
		}

		$entries[] = array(
			'event'           => $event,
			'attempts'        => $attempts,
			'next_attempt_at' => $next_attempt_at,
		);

		self::write( self::QUEUE_OPTION, $entries );
	}

	/**
	 * Entries whose next attempt has come due.
	 *
	 * @param int $now Unix timestamp to compare against.
	 * @return array
	 */
	public static function due_entries( int $now ): array {
		$due = array_filter(
			self::all(),
			static function ( $entry ) use ( $now ) {
				return $entry['next_attempt_at'] <= $now;
			}
		);

		return array_values( $due );
	}

	/**
	 * Drop a queued event by its id.
	 *
	 * @param string $event_id ULID of the event to remove.
	 * @return void
	 */
	public static function remove( string $event_id ): void {
		$entries   = self::all();
		$remaining = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $event_id ) {
					return (string) $entry['event']['event_id'] !== $event_id;
				}
			)
		);

		/*
		 * Most events are delivered on the first attempt and were never
		 * queued, so removing them should not create the option.
		 */
		if ( count( $remaining ) === count( $entries ) ) {
			return;
		}

		self::write( self::QUEUE_OPTION, $remaining );
	}

	/**
	 * Record a delivery the plugin has stopped trying to make.
	 *
	 * @param array  $event  The event that failed.
	 * @param string $reason Why it was given up on.
	 * @param int    $code   HTTP status, or 0 when there was none.
	 * @return void
	 */
	public static function log_failure( array $event, string $reason, int $code ): void {
		$log = self::log();

		$log[] = array(
			'time'       => time(),
			'event_name' => isset( $event['event_name'] ) ? (string) $event['event_name'] : '',
			'event_id'   => isset( $event['event_id'] ) ? (string) $event['event_id'] : '',
			'order_ref'  => isset( $event['properties']['order_id'] ) ? (string) $event['properties']['order_id'] : '',
			'reason'     => $reason,
			'code'       => $code,
		);

		self::write( self::LOG_OPTION, array_slice( $log, -self::MAX_LOG_ENTRIES ) );
	}

	/**
	 * The failure log, oldest first.
	 *
	 * @return array
	 */
	public static function log(): array {
		return self::read( self::LOG_OPTION );
	}

	/**
	 * Read a store, tolerating a corrupted option.
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	private static function read( string $option ): array {
		$stored = get_option( $option, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Queued entries, with anything malformed discarded.
	 *
	 * A partial restore, a foreign write, or a future schema change can leave
	 * an entry the drain cannot read. Indexing into it would warn, treat the
	 * entry as permanently due, and — at the queue bound — hand `null` to
	 * `log_failure()` and fatal inside cron, taking every later job with it.
	 *
	 * @return array
	 */
	private static function read_entries(): array {
		$usable = array_filter(
			self::read( self::QUEUE_OPTION ),
			static function ( $entry ) {
				return is_array( $entry )
					&& isset( $entry['event'], $entry['next_attempt_at'], $entry['attempts'] )
					&& is_array( $entry['event'] )
					&& isset( $entry['event']['event_id'] )
					&& is_numeric( $entry['next_attempt_at'] )
					// The drain increments this; a missing or negative value
					// would reset the attempt count or index the backoff
					// schedule out of range.
					&& is_int( $entry['attempts'] )
					&& $entry['attempts'] >= 1;
			}
		);

		return array_values( $usable );
	}

	/**
	 * Write a store, never autoloaded.
	 *
	 * @param string $option  Option name.
	 * @param array  $entries Entries to store.
	 * @return void
	 */
	private static function write( string $option, array $entries ): void {
		update_option( $option, $entries, false );
	}
}
