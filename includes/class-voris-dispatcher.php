<?php
/**
 * Event delivery.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides when an event is sent, and what happens to it afterwards.
 *
 * Nothing is sent while the caller is still working. Payloads raised during a
 * request are held and flushed on `shutdown`, after output — so a slow or
 * unreachable Voris cannot delay page render, admin, or checkout
 * (constitution §16). The send itself blocks briefly because the status code
 * has to be read to tell a rejected payload from an outage.
 *
 * Outcomes are announced as actions rather than returned, because a delivery
 * can succeed on a later request entirely — a queue drain in cron — and the
 * WooCommerce integration has to learn about it either way (ADR-MG001).
 *
 * Actions fired:
 * - `voris_event_delivered` ( array $event ) — Voris has the event.
 * - `voris_event_failed` ( array $event, string $reason, int $code ) — given up on.
 */
final class Voris_Dispatcher {

	/**
	 * Cron hook that drains the queue. Single events only — never recurring,
	 * so an idle plugin leaves nothing scheduled (ADR-ACH001).
	 *
	 * @var string
	 */
	const CRON_HOOK = 'voris_drain_queue';

	/**
	 * Seconds to wait before attempt 2, then before attempt 3.
	 *
	 * Three attempts total, per C6. The array length is the bound.
	 *
	 * @var array
	 */
	const RETRY_SCHEDULE = array( 300, 1800 );

	/**
	 * Sends per drain, so a backlog cannot monopolise one request.
	 *
	 * @var int
	 */
	const MAX_PER_RUN = 20;

	/**
	 * How late a cron event must be before an admin visit takes over.
	 *
	 * @var int
	 */
	const OVERDUE_GRACE = 60;

	/**
	 * Held while a drain is running, so two requests cannot drain at once.
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'voris_draining';

	/**
	 * Seconds the lock survives if a drain dies mid-run.
	 *
	 * @var int
	 */
	const LOCK_TTL = 120;

	/**
	 * Seconds a drain may occupy a PHP worker.
	 *
	 * `MAX_PER_RUN` alone is not a bound on time: twenty sends at the 2 s
	 * transport timeout is 40 s, past PHP's default `max_execution_time` of 30.
	 * A fatal there skips the `finally` that releases the lock, and takes every
	 * cron job queued behind it.
	 *
	 * @var int
	 */
	const MAX_RUN_SECONDS = 10;

	/**
	 * Who may have their request adopt an overdue drain.
	 *
	 * Stricter than "logged in": `admin_init` also fires on admin-post.php,
	 * which any subscriber can reach.
	 *
	 * @var string
	 */
	const FALLBACK_CAPABILITY = 'manage_options';

	/**
	 * Payloads raised this request, awaiting `shutdown`.
	 *
	 * @var array
	 */
	private array $pending = array();

	/**
	 * Whether the flush is already hooked.
	 *
	 * @var bool
	 */
	private bool $flush_hooked = false;

	/**
	 * Whether the shutdown flush has already run this request.
	 *
	 * Once it has, `shutdown` will not fire it again (a re-added same-priority
	 * callback is a no-op and `apply_filters` does not rewind), so a later
	 * dispatch is persisted durably rather than held in memory (VOR-302).
	 *
	 * @var bool
	 */
	private bool $flushed = false;

	/**
	 * Hand an event over for delivery.
	 *
	 * Returns immediately. The event is sent on `shutdown`.
	 *
	 * @param array $payload A contract-conformant event payload.
	 * @return void
	 */
	public function dispatch( array $payload ): void {
		/*
		 * The single `shutdown` flush has already run and we are still inside
		 * `shutdown` — a callback ordered after ours is raising an event (a
		 * gateway finaliser, say). It cannot ride a second flush (re-arming a
		 * same-priority shutdown callback is a no-op; `apply_filters` does not
		 * rewind), so persist it durably for the drain, by id, instead of
		 * leaving it to strand in memory (VOR-302). The `doing_action` guard
		 * scopes this to the real request shutdown: a caller invoking
		 * `send_pending()` directly (a later, separate request) still batches
		 * normally.
		 */
		if ( $this->flushed && doing_action( 'shutdown' ) ) {
			Voris_Queue::enqueue( $payload, 1, time() );
			self::schedule_next();

			return;
		}

		$this->pending[] = $payload;

		if ( $this->flush_hooked ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'send_pending' ) );
		$this->flush_hooked = true;
	}

	/**
	 * Send everything raised this request. Hooked to `shutdown`.
	 *
	 * @return void
	 */
	public function send_pending(): void {
		if ( $this->pending ) {
			$this->release_client();
		}

		$deadline = time() + self::MAX_RUN_SECONDS;
		$sent     = 0;
		$deferred = false;

		/*
		 * Drained in a loop rather than by re-arming the hook, so an event
		 * raised *synchronously while we drain* — by a `voris_event_delivered`
		 * or `voris_event_failed` listener that dispatches in turn — is caught
		 * on the next pass. Re-arming would not catch even that: re-adding the
		 * same callback at the same priority overwrites the existing entry in
		 * place instead of appending, and `apply_filters` iterates a snapshot it
		 * does not rewind, so the hook never fires again in this request (§4).
		 *
		 * An event raised by a `shutdown` callback that runs *after* this flush
		 * is persisted durably by dispatch() (the `doing_action('shutdown')`
		 * branch) rather than stranded (VOR-302). The one residual is an event
		 * first-dispatched during shutdown when no flush was ever hooked
		 * (VOR-323).
		 */
		while ( $this->pending ) {
			$pending       = $this->pending;
			$this->pending = array();

			foreach ( $pending as $payload ) {
				/*
				 * Budget the first flush exactly like the cron drain. Twenty
				 * sends at the transport timeout can outlive max_execution_time,
				 * and a fatal there strands the remainder; a bulk order
				 * completion or a Voris outage must neither block the request
				 * nor lose events. Past the budget, persist for the bounded
				 * drain rather than send inline (VOR-303).
				 */
				if ( $sent >= self::MAX_PER_RUN || time() >= $deadline ) {
					Voris_Queue::enqueue( $payload, 1, time() );
					$deferred = true;

					continue;
				}

				$this->attempt( $payload, 1 );
				++$sent;
			}
		}

		$this->flushed      = true;
		$this->flush_hooked = false;

		if ( $deferred ) {
			self::schedule_next();
		}
	}

	/**
	 * Make one delivery attempt and act on the outcome.
	 *
	 * @param array $payload  The event payload, id included.
	 * Outcomes are announced, not returned — see the class docblock.
	 *
	 * @param int   $attempts Which attempt this is, counting from 1.
	 * @return void
	 */
	public function attempt( array $payload, int $attempts ): void {
		$result = Voris_Transport::send( $payload );

		if ( Voris_Transport::SUCCESS === $result['result'] ) {
			$this->settle( $payload );

			/**
			 * Fires once Voris has accepted an event.
			 *
			 * @param array $payload The delivered event.
			 */
			do_action( 'voris_event_delivered', $payload );

			return;
		}

		if ( Voris_Transport::FATAL === $result['result'] ) {
			/*
			 * The payload is wrong or the site id is unknown. Retrying cannot
			 * change either, so record it where the owner can see it (AC10)
			 * and stop.
			 */
			$this->settle( $payload );
			$reason = 'encode_failed' === $result['error'] ? 'encode_failed' : 'rejected';

			Voris_Queue::log_failure( $payload, $reason, $result['code'] );

			/**
			 * Fires when an event will not be attempted again.
			 *
			 * @param array  $payload The event that failed.
			 * @param string $reason  Why it was given up on.
			 * @param int    $code    HTTP status, or 0 when there was none.
			 */
			do_action( 'voris_event_failed', $payload, $reason, $result['code'] );

			return;
		}

		if ( $attempts >= count( self::RETRY_SCHEDULE ) + 1 ) {
			/*
			 * Three attempts spent. Dropping the event is the bounded outcome
			 * C6 asks for; the log is what makes it visible to the owner
			 * rather than silent (AC10).
			 */
			$this->settle( $payload );
			Voris_Queue::log_failure( $payload, 'gave_up', $result['code'] );

			/** This action is documented above. */
			do_action( 'voris_event_failed', $payload, 'gave_up', $result['code'] );

			return;
		}

		// Voris was unwell or unreachable. Keep the event and its id for a
		// later attempt, so a retry deduplicates instead of double-counting.
		Voris_Queue::enqueue( $payload, $attempts, time() + self::RETRY_SCHEDULE[ $attempts - 1 ] );
		self::schedule_next();
	}

	/**
	 * Retry everything that has come due. Hooked to the cron event.
	 *
	 * @return void
	 */
	public function drain(): void {
		/*
		 * Two requests draining at once would both read the same due entries,
		 * both send them, and then race on the queue option — resurrecting a
		 * settled event with a stale attempt count and reopening the bound.
		 */
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		/*
		 * A fencing token, not a flag. If this drain outlives LOCK_TTL its
		 * lock expires, another acquires, and an unconditional delete in the
		 * `finally` below would release *that* drain's lock — reopening the
		 * very race the lock exists to close.
		 */
		$token = wp_generate_password( 12, false );
		set_transient( self::LOCK_TRANSIENT, $token, self::LOCK_TTL );

		$this->release_client();

		try {
			$due      = array_slice( Voris_Queue::due_entries( time() ), 0, self::MAX_PER_RUN );
			$deadline = time() + self::MAX_RUN_SECONDS;

			foreach ( $due as $entry ) {
				/*
				 * Stop on time, not just on count. A slow Voris turns twenty
				 * sends into forty seconds, which outlives the default
				 * max_execution_time — and a fatal there would skip the
				 * `finally` below and strand the lock for its full TTL.
				 */
				if ( time() >= $deadline ) {
					break;
				}

				$this->attempt( $entry['event'], $entry['attempts'] + 1 );
			}

			self::schedule_next();
		} finally {
			if ( get_transient( self::LOCK_TRANSIENT ) === $token ) {
				delete_transient( self::LOCK_TRANSIENT );
			}
		}
	}

	/**
	 * Rescue a drain that WP-Cron never ran. Hooked to `admin_init`.
	 *
	 * WP-Cron only fires when someone visits, so a quiet site can leave events
	 * queued indefinitely. When the scheduled event is well past due, this runs
	 * it on the current admin request's `shutdown` — after output, so the admin
	 * screen is never delayed (ADR-ACH001, §16).
	 *
	 * @return void
	 */
	public function maybe_run_overdue_drain(): void {
		/*
		 * `admin_init` is not an authenticated hook. Core fires it in
		 * admin-ajax.php and admin-post.php *before* either checks who is
		 * asking, so without this an anonymous request could make the site
		 * perform a drain's worth of outbound calls and order-meta writes.
		 */
		if ( ! current_user_can( self::FALLBACK_CAPABILITY ) || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $scheduled || $scheduled > time() - self::OVERDUE_GRACE ) {
			return;
		}

		/*
		 * The stale event is left in place deliberately: `schedule_next()` is
		 * authoritative and will replace it once the drain has run. Clearing
		 * it here instead would strand the queue with nothing scheduled if the
		 * drain then found the lock held.
		 */
		add_action( 'shutdown', array( $this, 'drain' ) );
	}

	/**
	 * Reschedule the drain for a queue that survived a deactivate→reactivate or
	 * an upgrade. Hooked from activation/upgrade, not per request.
	 *
	 * Deactivation clears the drain cron but keeps the queue (C4). Without this,
	 * a reactivated site has a non-empty queue and nothing scheduled — and the
	 * overdue-admin fallback cannot help, because it only adopts an EXISTING
	 * overdue schedule. So captured revenue would sit undelivered forever
	 * (VOR-301). A no-op on an empty queue: an idle plugin still leaves nothing
	 * scheduled.
	 *
	 * @return void
	 */
	public static function reconcile_schedule(): void {
		if ( empty( Voris_Queue::all() ) ) {
			return;
		}

		self::schedule_next();
	}

	/**
	 * Keep the schedule in step with the queue.
	 *
	 * One single event at a time, aimed at the earliest entry due. An empty
	 * queue leaves nothing scheduled at all, so an idle plugin is invisible to
	 * cron (ADR-ACH001, and constitution §24's orphan hygiene).
	 *
	 * Static: it reads only the queue and the schedule, so activation/upgrade
	 * can reconcile without constructing a dispatcher.
	 *
	 * @return void
	 */
	private static function schedule_next(): void {
		$entries   = Voris_Queue::all();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( empty( $entries ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );

			return;
		}

		$next = (int) min( array_column( $entries, 'next_attempt_at' ) );

		/*
		 * Authoritative rather than "schedule only if nothing is scheduled":
		 * an event WP-Cron never ran stays permanently past due, and merely
		 * noting that *a* schedule exists would leave it that way — so every
		 * admin request would adopt the drain for as long as the queue had
		 * anything in it.
		 */
		if ( $scheduled === $next ) {
			return;
		}

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( $next, self::CRON_HOOK );
	}

	/**
	 * Let the browser go before spending time on Voris.
	 *
	 * `wp_ob_end_flush_all` runs at `shutdown` priority 1, so output is already
	 * on the wire — but WordPress sends no `Content-Length` for dynamic output,
	 * so the response is not *terminated* until PHP exits. Until then the
	 * browser's `load` event never fires, `defer` scripts never execute, and an
	 * XHR success handler never runs.
	 *
	 * That last one is the problem §16 cares about: an all-virtual WooCommerce
	 * cart completes inside the customer's own `?wc-ajax=checkout` request, and
	 * the checkout redirect only happens in that request's success callback. A
	 * 2 s send would sit squarely in the customer's spinner. The refund modal
	 * on admin-ajax is the same shape.
	 *
	 * Core does this in exactly one place — `wp-cron.php`, before `wp-load.php`
	 * — for exactly this reason. Called only when there is something to send,
	 * so a request that has no Voris work is left alone.
	 *
	 * @return void
	 */
	private function release_client(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();

			return;
		}

		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Drop an event that needs no further attempts.
	 *
	 * Harmless when it was never queued: the first attempt happens before any
	 * enqueue, so most events settle without ever having been stored.
	 *
	 * @param array $payload The event payload.
	 * @return void
	 */
	private function settle( array $payload ): void {
		if ( isset( $payload['event_id'] ) ) {
			Voris_Queue::remove( (string) $payload['event_id'] );
		}
	}
}
