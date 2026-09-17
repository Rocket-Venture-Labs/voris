<?php
/**
 * Plan / usage read + cache (Phase 2, chunk 3).
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the served plan + window-to-date usage (`GET /api/partner/plan`) and caches
 * it (ACH001 C11).
 *
 * The read is codes-and-numbers only (CL10) — the nudge engine (chunk 4) turns the
 * codes into the plugin's own copy. Displayed limits come from Voris, never the
 * plugin, so a plan change is reflected without a plugin update (AC11/AC14).
 *
 * Refresh is background, never inline: the settings screen schedules a one-shot
 * WP-Cron refresh when the cache is older than an hour and renders the cached value
 * meanwhile, because a Voris call must never delay an admin request (constitution
 * §16). Staleness has TWO distinct clocks:
 *  - `fetched_at` — when the plugin last managed to talk to Voris. Past the stale
 *    bound ⇒ "couldn't refresh" (we are the ones behind).
 *  - `measuredAt` — when Voris last measured usage. `not_yet_measured` ⇒ "not yet
 *    measured this window" (a real zero, not staleness).
 */
final class Voris_Plan {

	const CACHE_OPTION = 'voris_plan_cache';
	const REFRESH_HOOK = 'voris_plan_refresh';
	const FRESH_FOR    = HOUR_IN_SECONDS;      // Refresh when older than this.
	const STALE_AFTER  = DAY_IN_SECONDS;       // fetched_at older than this ⇒ "couldn't refresh".

	/**
	 * Wire the background refresh hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh' ) );
		add_action( 'load-' . Voris_Admin_Page::HOOK_SUFFIX, array( __CLASS__, 'maybe_schedule_refresh' ) );
	}

	/**
	 * The cached read body, or null if nothing has been fetched.
	 *
	 * @return array|null
	 */
	public static function read(): ?array {
		$cache = get_option( self::CACHE_OPTION, null );

		return ( is_array( $cache ) && isset( $cache['read'] ) && is_array( $cache['read'] ) ) ? $cache['read'] : null;
	}

	/**
	 * When the plugin last successfully fetched, or 0.
	 *
	 * @return int
	 */
	public static function fetched_at(): int {
		$cache = get_option( self::CACHE_OPTION, null );

		return ( is_array( $cache ) && ! empty( $cache['fetched_at'] ) ) ? (int) $cache['fetched_at'] : 0;
	}

	/**
	 * The last read error status, or '' — for the "couldn't refresh" surface.
	 *
	 * @return string
	 */
	public static function last_error(): string {
		$cache = get_option( self::CACHE_OPTION, null );

		return ( is_array( $cache ) && ! empty( $cache['last_error'] ) ) ? (string) $cache['last_error'] : '';
	}

	/**
	 * Whether the cached read is too old to trust as current ("couldn't refresh").
	 *
	 * Measured from `fetched_at` (our last contact with Voris), NOT from Voris's own
	 * `measuredAt` — the two mean different things (see the class docblock).
	 *
	 * @return bool
	 */
	public static function is_stale(): bool {
		$fetched = self::fetched_at();

		return 0 === $fetched || ( time() - $fetched ) > self::STALE_AFTER;
	}

	/**
	 * On the settings screen: schedule a background refresh if the cache is old.
	 *
	 * Never fetches inline — §16 forbids blocking an admin request on Voris. Only
	 * meaningful when a grant is held (an unconnected site has nothing to read).
	 *
	 * @return void
	 */
	public static function maybe_schedule_refresh(): void {
		if ( ! Voris_Connection::is_connected() ) {
			return;
		}

		$fetched = self::fetched_at();
		if ( 0 !== $fetched && ( time() - $fetched ) < self::FRESH_FOR ) {
			return; // Still fresh.
		}

		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time(), self::REFRESH_HOOK );
		}
	}

	/**
	 * Background refresh: fetch the plan read and cache it.
	 *
	 * A denied/unavailable read updates `last_error` and leaves the prior cached
	 * value in place (a transient failure must not blank the usage display); a dead
	 * grant clears the credential so the UI shows "reconnect".
	 *
	 * @return void
	 */
	public static function refresh(): void {
		if ( ! Voris_Connection::is_connected() ) {
			return;
		}

		$res = Voris_Partner_Api::get( 'plan' );

		if ( Voris_Partner_Api::OK === $res['status'] ) {
			self::write(
				array(
					'read'       => $res['body'],
					'fetched_at' => time(),
					'last_error' => '',
				)
			);
			return;
		}

		// A dead grant (RECONNECT) or a bound site that no longer exists (NOT_FOUND,
		// frozen contract: "not_found ⇒ reconnect") both require re-connecting —
		// drop the credential so the UI shows reconnect instead of looping on a
		// stale read while retaining a credential that can never succeed again.
		if ( Voris_Partner_Api::RECONNECT === $res['status'] || Voris_Partner_Api::NOT_FOUND === $res['status'] ) {
			Voris_Connection::clear();
		}

		// Keep the prior cached read (if any); just record why we couldn't refresh.
		$cache               = get_option( self::CACHE_OPTION, array() );
		$cache               = is_array( $cache ) ? $cache : array();
		$cache['last_error'] = (string) $res['status'];
		self::write( $cache );
	}

	/**
	 * Write the cache non-autoloaded.
	 *
	 * @param array $cache Cache payload.
	 * @return void
	 */
	private static function write( array $cache ): void {
		if ( false === get_option( self::CACHE_OPTION, false ) ) {
			add_option( self::CACHE_OPTION, $cache, '', false );
			return;
		}

		update_option( self::CACHE_OPTION, $cache, false );
	}

	/**
	 * Clear the cache (disconnect / uninstall).
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::CACHE_OPTION );
		$scheduled = wp_next_scheduled( self::REFRESH_HOOK );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::REFRESH_HOOK );
		}
	}
}
