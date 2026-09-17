<?php
/**
 * WooCommerce integration.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports store revenue to Voris from the server.
 *
 * A browser tag cannot see revenue reliably — ad-blockers, page caches, and
 * off-site payment redirects all break it — so purchases and refunds are sent
 * from PHP when the order lifecycle says so.
 *
 * Nothing here runs unless WooCommerce is present, and every WooCommerce
 * symbol referenced by the plugin lives in this file (AC17).
 */
final class Voris_WooCommerce {

	/**
	 * ULID of a purchase that has been sent but not yet acknowledged.
	 *
	 * @var string
	 */
	const PURCHASE_PENDING_META = '_voris_purchase_pending';

	/**
	 * ULID of a purchase Voris has confirmed receiving (ADR-MG001).
	 *
	 * @var string
	 */
	const PURCHASE_EVENT_META = '_voris_purchase_event_id';

	/**
	 * Voris site id the confirmed purchase was delivered to (ADR-ACH006).
	 *
	 * A later refund correction must reverse revenue in the workspace that
	 * received the sale, not whatever the site is connected to at refund time.
	 *
	 * @var string
	 */
	const PURCHASE_SITE_META = '_voris_purchase_site_id';

	/**
	 * ULID of the correction sent for a refund, so re-fires are no-ops.
	 *
	 * @var string
	 */
	const REFUND_EVENT_META = '_voris_refund_event_id';

	/**
	 * Whether WooCommerce is here.
	 *
	 * @var bool
	 */
	private bool $available;

	/**
	 * Delivery service.
	 *
	 * @var Voris_Dispatcher
	 */
	private Voris_Dispatcher $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Voris_Dispatcher $dispatcher Delivery service.
	 * @param bool|null        $available  Override for tests; defaults to detection.
	 */
	public function __construct( Voris_Dispatcher $dispatcher, ?bool $available = null ) {
		$this->dispatcher = $dispatcher;
		$this->available  = $available ?? class_exists( 'WooCommerce' );
	}

	/**
	 * Hook the order lifecycle, if there is one.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->available ) {
			return;
		}

		add_action( 'woocommerce_order_status_completed', array( $this, 'record_purchase' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'record_refund' ), 10, 2 );
		add_action( 'voris_event_delivered', array( $this, 'confirm_event' ) );
		add_action( 'voris_event_failed', array( $this, 'abandon_event' ) );
	}

	/**
	 * Declare compatibility with High-Performance Order Storage.
	 *
	 * Without this WooCommerce assumes the plugin is unsafe with custom order
	 * tables and warns the store owner. Everything here goes through order CRUD
	 * rather than post meta, so HPOS is fine.
	 *
	 * Registered from the main plugin file at load time rather than from the
	 * bootstrap. WooCommerce fires this from `WooCommerce::init()` on `init`
	 * priority 0, and registering at file scope is its documented pattern —
	 * the one arrangement that does not depend on plugin load order.
	 *
	 * @return void
	 */
	public static function declare_hpos_compatibility(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			VORIS_PLUGIN_FILE,
			true
		);
	}

	/**
	 * Report a completed order as revenue.
	 *
	 * Fires at "completed" rather than "processing" (C2): fulfilment is the
	 * stabler signal and produces fewer corrections.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function record_purchase( $order_id ): void {
		/*
		 * Connection state is checked before anything is written, so a
		 * disabled or unconnected site leaves no trace on the order at all —
		 * and cannot later look, to a refund, like a purchase Voris received
		 * (AC7, ADR-MG001).
		 */
		if ( ! Voris_Settings::is_connected() || ! Voris_Settings::is_tracking_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		/*
		 * A free order — fully discounted, or a zero-priced product — carries
		 * no revenue to report. Skipped silently rather than logged: it is an
		 * ordinary outcome, and logging it on every completion would churn
		 * genuine failures out of the log's 50-entry ring buffer.
		 */
		if ( (float) $order->get_total() <= 0 ) {
			return;
		}

		/*
		 * Claim the purchase op for this order before minting. Two completion
		 * callbacks running at once — a gateway webhook racing an admin action,
		 * a double-fired transition — would otherwise both read an empty marker,
		 * both mint a DIFFERENT event id, and both dispatch, which ingest cannot
		 * dedup (VOR-305, ADR-ACH007). A callback that cannot claim does nothing:
		 * the one holding the claim mints exactly once.
		 */
		$order_id = (int) $order->get_id();
		$claim    = $this->claim_order_op( 'purchase', $order_id );

		if ( true !== $claim ) {
			/*
			 * `false` is contention — a concurrent callback owns this op — and
			 * stays silent, or the log would churn on every double-fire. `null`
			 * is a GET_LOCK *error*, not contention: this completion will not
			 * fire again, so a purchase would vanish with no trace. Record that
			 * one (§4, ADR-MG001: visible rather than silent).
			 */
			if ( null === $claim ) {
				Voris_Queue::log_failure(
					array(
						'event_name' => 'purchase',
						'properties' => array( 'order_id' => (string) $order->get_id() ),
					),
					'claim_error',
					0
				);
			}

			return;
		}

		try {
			/*
			 * The one authoritative "already reported?" check, made fresh under
			 * the claim. `read_meta_data( true )` bypasses the object cache and
			 * reads the data store directly, so a marker another callback
			 * committed — even one a stale (persistent) object cache would hide —
			 * is seen here. This is what makes the order report its revenue
			 * exactly once across concurrent callbacks (ADR-ACH007) and across
			 * sequential hook re-fires (AC8) alike.
			 */
			$order->read_meta_data( true );

			if ( $this->purchase_already_handled( $order ) ) {
				return;
			}

			try {
				$payload = Voris_Payload::purchase(
					Voris_Settings::site_id(),
					(string) $order->get_id(),
					(float) $order->get_total(),
					(string) $order->get_currency(),
					(int) $order->get_item_count()
				);
			} catch ( Throwable $e ) {
				/*
				 * The order cannot produce a conformant event — an unusable
				 * currency, or a reference the payload builder refuses. Record it
				 * and let the status transition finish: a revenue report must
				 * never break an order (§16).
				 */
				Voris_Queue::log_failure(
					array(
						'event_name' => 'purchase',
						'properties' => array( 'order_id' => (string) $order->get_id() ),
					),
					'refused_locally',
					0
				);

				return;
			}

			$order->update_meta_data( self::PURCHASE_PENDING_META, $payload['event_id'] );
			$order->save();

			$this->dispatcher->dispatch( $payload );
		} finally {
			$this->release_order_op( 'purchase', $order_id );
		}
	}

	/**
	 * Report a refund as a negative revenue correction.
	 *
	 * Full and partial refunds are both reported (C3). A refund is only sent
	 * when Voris confirmed receiving the purchase: correcting revenue that
	 * never arrived would leave the dashboard reading negative against a sale
	 * it has no record of (ADR-MG001).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public function record_refund( $order_id, $refund_id ): void {
		if ( ! Voris_Settings::is_connected() || ! Voris_Settings::is_tracking_enabled() ) {
			return;
		}

		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order || ! $refund ) {
			return;
		}

		if ( '' === (string) $order->get_meta( self::PURCHASE_EVENT_META ) ) {
			/*
			 * Either the purchase was never sent, or it was sent and never
			 * acknowledged. Sending a correction now would be the dangling
			 * correction C3 forbids, so record the gap instead.
			 */
			Voris_Queue::log_failure(
				array(
					'event_name' => 'refund',
					'properties' => array( 'order_id' => (string) $order->get_id() ),
				),
				'purchase_not_delivered',
				0
			);

			return;
		}

		/*
		 * A correction must reverse revenue in the workspace that received the
		 * sale. If the site was reconnected to a different Voris workspace since
		 * the purchase was confirmed, sending against the current site id would
		 * mis-route the correction — the old workspace keeps unreversed revenue,
		 * the new one gets a correction for a sale it never saw. Refuse and
		 * record the mismatch rather than mis-route (VOR-304, ADR-ACH006). A
		 * legacy order with no pinned site (confirmed before ADR-ACH006) has no
		 * evidence of a change, so it falls back to the current site.
		 */
		$purchase_site = (string) $order->get_meta( self::PURCHASE_SITE_META );
		$current_site  = Voris_Settings::site_id();

		if ( '' !== $purchase_site && $purchase_site !== $current_site ) {
			Voris_Queue::log_failure(
				array(
					'event_name' => 'refund',
					'properties' => array( 'order_id' => (string) $order->get_id() ),
				),
				'purchase_site_mismatch',
				0
			);

			return;
		}

		/*
		 * Claim the refund op before minting, so two concurrent refund callbacks
		 * for the same refund cannot both mint a DIFFERENT correction id and both
		 * dispatch (VOR-305, ADR-ACH007).
		 */
		$refund_id = (int) $refund->get_id();
		$claim     = $this->claim_order_op( 'refund', $refund_id );

		if ( true !== $claim ) {
			// See record_purchase(): contention is silent, a lock error is logged.
			if ( null === $claim ) {
				Voris_Queue::log_failure(
					array(
						'event_name' => 'refund',
						'properties' => array( 'order_id' => (string) $order->get_id() ),
					),
					'claim_error',
					0
				);
			}

			return;
		}

		try {
			// Re-read fresh under the claim (see record_purchase()).
			$refund->read_meta_data( true );

			if ( '' !== (string) $refund->get_meta( self::REFUND_EVENT_META ) ) {
				return;
			}

			/*
			 * This refund's own amount against the order total. Comparing
			 * cumulative refunds instead would answer "is the order fully
			 * refunded yet", so refunding 25 then 75 of a 100 order would flag
			 * the second — three quarters of the order — as a full refund.
			 */
			$is_partial = (float) $refund->get_amount() < (float) $order->get_total();

			try {
				// The same site id the guard above validated, so the payload can
				// only ever carry the workspace the refund was cleared for.
				$payload = Voris_Payload::refund(
					$current_site,
					(string) $order->get_id(),
					(string) $refund->get_id(),
					(float) $refund->get_amount(),
					(string) $order->get_currency(),
					$is_partial
				);
			} catch ( Throwable $e ) {
				Voris_Queue::log_failure(
					array(
						'event_name' => 'refund',
						'properties' => array( 'order_id' => (string) $order->get_id() ),
					),
					'refused_locally',
					0
				);

				return;
			}

			$refund->update_meta_data( self::REFUND_EVENT_META, $payload['event_id'] );
			$refund->save();

			$this->dispatcher->dispatch( $payload );
		} finally {
			$this->release_order_op( 'refund', $refund_id );
		}
	}

	/**
	 * Promote a delivered purchase from pending to confirmed (ADR-MG001).
	 *
	 * @param array $event The delivered event.
	 * @return void
	 */
	public function confirm_event( $event ): void {
		$order = $this->order_for_event( $event, 'purchase' );

		if ( ! $order ) {
			return;
		}

		$order->delete_meta_data( self::PURCHASE_PENDING_META );
		$order->update_meta_data( self::PURCHASE_EVENT_META, (string) $event['event_id'] );

		/*
		 * Pin the workspace this purchase was delivered to, taken from the
		 * delivered event itself rather than re-read from settings (which may
		 * have changed). A later refund reverses revenue here, or refuses if the
		 * site has since moved to a different workspace (ADR-ACH006).
		 */
		$order->update_meta_data( self::PURCHASE_SITE_META, isset( $event['site_id'] ) ? (string) $event['site_id'] : '' );

		$order->save();
	}

	/**
	 * Forget a purchase that was never delivered.
	 *
	 * Leaving the pending marker would make a later refund believe Voris has
	 * revenue it never received, which is exactly the dangling correction C3
	 * forbids.
	 *
	 * @param array $event The event that failed.
	 * @return void
	 */
	public function abandon_event( $event ): void {
		$order = $this->order_for_event( $event, 'purchase' );

		if ( ! $order ) {
			return;
		}

		/*
		 * Only clear the marker this event put down. A stale failure notice
		 * for a superseded attempt must not unlock an order whose current
		 * attempt is still in flight.
		 */
		$event_id = isset( $event['event_id'] ) ? (string) $event['event_id'] : '';

		if ( '' === $event_id || (string) $order->get_meta( self::PURCHASE_PENDING_META ) !== $event_id ) {
			return;
		}

		$order->delete_meta_data( self::PURCHASE_PENDING_META );
		$order->save();
	}

	/**
	 * Whether this order's purchase has already been sent or confirmed.
	 *
	 * Either marker suppresses a second send, so a status that reaches
	 * "completed" twice reports revenue once (AC8).
	 *
	 * @param WC_Order $order Order to check.
	 * @return bool
	 */
	private function purchase_already_handled( $order ): bool {
		return '' !== (string) $order->get_meta( self::PURCHASE_PENDING_META )
			|| '' !== (string) $order->get_meta( self::PURCHASE_EVENT_META );
	}

	/**
	 * Resolve the order an event belongs to.
	 *
	 * @param mixed  $event      Event payload.
	 * @param string $event_name Event name to match.
	 * @return WC_Order|null
	 */
	private function order_for_event( $event, string $event_name ) {
		if ( ! is_array( $event ) || ( $event['event_name'] ?? '' ) !== $event_name ) {
			return null;
		}

		$order_id = $event['properties']['order_id'] ?? '';
		$order    = $order_id ? wc_get_order( (int) $order_id ) : false;

		return $order ? $order : null;
	}

	/**
	 * Claim an order op with a MySQL advisory lock, non-blocking (ADR-ACH007).
	 *
	 * `GET_LOCK` is ATOMIC across DB sessions — the check-then-mint the order
	 * marker cannot do on its own — so a second concurrent callback for the same
	 * order cannot also mint. Timeout 0 makes this a try-lock: a callback that
	 * cannot claim returns and does nothing. The lock auto-releases when the
	 * request's DB connection closes, so a fatal cannot strand it. Storage
	 * agnostic, so it holds under classic and HPOS order storage alike (AC7).
	 * The same primitive guards the OAuth refresh (`Voris_Oauth`).
	 *
	 * `GET_LOCK` answers three ways, and the caller must tell them apart: `1`
	 * acquired, `0` a concurrent holder (contention), `NULL` an error (a killed
	 * thread, out of memory). Contention is expected and silent; an error would
	 * otherwise drop a single, non-concurrent event with no trace, so the caller
	 * logs that one.
	 *
	 * @param string $op Operation, `purchase` or `refund`.
	 * @param int    $id Order id for a purchase, refund id for a refund.
	 * @return bool|null True acquired, false contended, null on a lock error.
	 */
	private function claim_order_op( string $op, int $id ): ?bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An advisory lock, not a cacheable table read; atomicity is the point.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $op, $id ), 0 ) );

		if ( null === $got ) {
			return null;
		}

		return '1' === (string) $got;
	}

	/**
	 * Release the advisory lock claimed for an order op.
	 *
	 * @param string $op Operation, `purchase` or `refund`.
	 * @param int    $id Order id for a purchase, refund id for a refund.
	 * @return void
	 */
	private function release_order_op( string $op, int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releasing the advisory lock acquired above.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $op, $id ) ) );
	}

	/**
	 * The advisory-lock name for an order op. Well under MySQL's 64-char limit.
	 *
	 * @param string $op Operation, `purchase` or `refund`.
	 * @param int    $id Order id for a purchase, refund id for a refund.
	 * @return string
	 */
	private static function lock_name( string $op, int $id ): string {
		return 'voris_wc_' . $op . '_' . $id;
	}
}
