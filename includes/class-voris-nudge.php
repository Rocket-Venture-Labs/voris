<?php
/**
 * Upsell / plan-state nudge engine (Phase 2, chunk 4).
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the frozen plan read (`Voris_Plan::read()`, codes + numbers only, CL10)
 * into the plugin's own admin-facing copy. Every sentence is plugin-authored: a
 * served cap or site-limit number is NEVER restated verbatim (a plan change is
 * reflected from the read without a plugin release — AC11/AC14). The full
 * post-amendment transition table lives here:
 *
 *  - approaching (>= 80 %, dismissible per threshold),
 *  - at-cap (>= 100 %) trifurcated on the served acceptance state (C14/AC12):
 *      trial ⇒ still accepted (+ trial end), Free ⇒ not accepting, paid ⇒ overage,
 *  - soft_locked / locked (billing.lock) — non-dismissible,
 *  - past_due (billing.status) — non-dismissible,
 *  - second-site block (C10 normalized domain compare) — the bound site differs
 *    from the site this install runs on,
 *  - stale (row 7) — Voris unreachable; show last-known, mutate no threshold state.
 *
 * An unrecognised member of a switched-on code vocabulary falls back to the MOST
 * RESTRICTIVE state (never silently ignored) — a coordinated cross-repo release
 * lowers it later.
 */
final class Voris_Nudge {

	const DISMISS_OPTION = 'voris_nudge_dismissals';
	const WARN_PERCENT   = 80;  // C8 — warn threshold.
	const CAP_PERCENT    = 100; // C8 — escalate threshold.
	const AJAX_DISMISS   = 'voris_nudge_dismiss';
	const NONCE_ACTION   = 'voris_nudge';

	/**
	 * Wire the dismiss ajax handler + the settings-screen dismiss script.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_DISMISS, array( __CLASS__, 'ajax_dismiss' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the dismiss script on the Voris settings screen only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( Voris_Admin_Page::HOOK_SUFFIX !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'voris-nudge',
			plugins_url( 'assets/js/nudge.js', VORIS_PLUGIN_FILE ),
			array(),
			VORIS_VERSION,
			true
		);

		wp_localize_script(
			'voris-nudge',
			'vorisNudge',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/**
	 * The nudges to show right now, most severe first.
	 *
	 * @return array List of { code, level, dismissible, threshold_key, title, body, action_url }.
	 */
	public static function active(): array {
		$read = Voris_Plan::read();
		if ( null === $read ) {
			return array();
		}

		// Row 7: a stale read shows only the "couldn't refresh" notice and never
		// mutates a threshold state on data we know is behind.
		if ( Voris_Plan::is_stale() ) {
			return array( self::stale_nudge() );
		}

		$nudges = array();

		self::add_billing_nudges( $nudges, $read );
		self::add_usage_nudges( $nudges, $read );
		self::add_second_site_nudge( $nudges, $read );

		return array_values( $nudges );
	}

	/**
	 * Lock + past-due nudges (most severe).
	 *
	 * @param array $nudges Nudge accumulator (by reference).
	 * @param array $read   The plan read.
	 * @return void
	 */
	private static function add_billing_nudges( array &$nudges, array $read ): void {
		$lock   = isset( $read['billing']['lock'] ) ? (string) $read['billing']['lock'] : 'none';
		$status = isset( $read['billing']['status'] ) ? (string) $read['billing']['status'] : 'ok';
		$action = self::safe_action_url( isset( $read['upgrade']['url'] ) ? $read['upgrade']['url'] : '' );

		if ( 'none' !== $lock ) {
			// Unknown lock code ⇒ most restrictive (hard lock).
			$is_soft  = ( 'soft_locked' === $lock );
			$nudges[] = array(
				'code'          => $is_soft ? 'soft_locked' : 'locked',
				'level'         => 'critical',
				'dismissible'   => false,
				'threshold_key' => '',
				'title'         => $is_soft
					? __( 'Your Voris workspace is limited', 'voris-privacy-first-analytics' )
					: __( 'Your Voris workspace is locked', 'voris-privacy-first-analytics' ),
				'body'          => $is_soft
					? __( 'Some features are paused because of a billing issue. Resolve it to restore full access.', 'voris-privacy-first-analytics' )
					: __( 'Voris has paused data collection because of a billing issue. Resolve it to resume tracking.', 'voris-privacy-first-analytics' ),
				'action_url'    => $action,
			);
		}

		if ( 'ok' !== $status ) {
			// Unknown status ⇒ most restrictive (treat as past due).
			$nudges[] = array(
				'code'          => 'past_due',
				'level'         => 'critical',
				'dismissible'   => false,
				'threshold_key' => '',
				'title'         => __( 'A Voris payment is needed', 'voris-privacy-first-analytics' ),
				'body'          => __( 'Your payment is past due. Update your billing details in Voris to avoid interruption.', 'voris-privacy-first-analytics' ),
				'action_url'    => $action,
			);
		}
	}

	/**
	 * Approaching / at-cap usage nudges (only on a real measurement).
	 *
	 * @param array $nudges Nudge accumulator (by reference).
	 * @param array $read   The plan read.
	 * @return void
	 */
	private static function add_usage_nudges( array &$nudges, array $read ): void {
		$state = isset( $read['usage']['state'] ) ? (string) $read['usage']['state'] : '';
		$cap   = isset( $read['limits']['eventsPerMonth'] ) ? (int) $read['limits']['eventsPerMonth'] : 0;
		$used  = isset( $read['usage']['events'] ) ? (int) $read['usage']['events'] : 0;

		// not_yet_measured is a real zero, not staleness — nothing to compute.
		if ( 'measured' !== $state || $cap <= 0 ) {
			return;
		}

		$percent = ( $used / $cap ) * 100;
		$action  = self::safe_action_url( isset( $read['upgrade']['url'] ) ? $read['upgrade']['url'] : '' );

		if ( $percent >= self::CAP_PERCENT ) {
			$nudges[] = self::at_cap_nudge( $read, $action );
			return;
		}

		if ( $percent >= self::WARN_PERCENT ) {
			$key = self::threshold_key( $read, 'warn' );
			if ( self::is_dismissed( $key ) ) {
				return;
			}
			$nudges[] = array(
				'code'          => 'approaching',
				'level'         => 'warning',
				'dismissible'   => true,
				'threshold_key' => $key,
				// Floor, not round: rounding would show "100%" for 99.5-99.99% usage on
				// a sub-cap nudge whose body still says "if you reach your limit".
				/* translators: %d: whole-number percentage of the monthly event allowance used. */
				'title'         => sprintf( __( "You've used %d%% of your monthly event allowance", 'voris-privacy-first-analytics' ), (int) floor( $percent ) ),
				'body'          => __( 'Consider upgrading to avoid an interruption if you reach your limit this billing period.', 'voris-privacy-first-analytics' ),
				'action_url'    => $action,
			);
		}
	}

	/**
	 * The at-cap nudge, trifurcated on the served acceptance state (C14/AC12).
	 *
	 * @param array  $read   The plan read.
	 * @param string $action The host-pinned action URL.
	 * @return array
	 */
	private static function at_cap_nudge( array $read, $action ): array {
		$trial_active = ! empty( $read['trial']['active'] );
		$accepting    = isset( $read['ingest']['accepting'] ) ? (bool) $read['ingest']['accepting'] : false;

		if ( $trial_active ) {
			$ends = isset( $read['trial']['endsAt'] ) ? self::format_date( $read['trial']['endsAt'] ) : '';
			$body = '' !== $ends
				/* translators: %s: human-readable trial end date. */
				? sprintf( __( 'Voris is still recording your events during your trial, which ends %s. Upgrade to keep full tracking afterward.', 'voris-privacy-first-analytics' ), $ends )
				: __( 'Voris is still recording your events during your trial. Upgrade to keep full tracking afterward.', 'voris-privacy-first-analytics' );

			return array(
				'code'          => 'at_cap_trial',
				'level'         => 'warning',
				'dismissible'   => false,
				'threshold_key' => '',
				'title'         => __( "You've reached your trial's event limit", 'voris-privacy-first-analytics' ),
				'body'          => $body,
				'action_url'    => $action,
			);
		}

		// Free at cap: the server has stopped accepting (most restrictive default
		// if the field is missing/unknown).
		if ( ! $accepting ) {
			return array(
				'code'          => 'at_cap_free',
				'level'         => 'critical',
				'dismissible'   => false,
				'threshold_key' => '',
				'title'         => __( "You've reached your plan's event limit", 'voris-privacy-first-analytics' ),
				'body'          => __( 'Voris is currently not accepting new events from your site. Upgrade to resume tracking.', 'voris-privacy-first-analytics' ),
				'action_url'    => $action,
			);
		}

		// Paid, still accepting past the cap ⇒ overage.
		return array(
			'code'          => 'at_cap_overage',
			'level'         => 'warning',
			'dismissible'   => false,
			'threshold_key' => '',
			'title'         => __( "You've passed your plan's event limit", 'voris-privacy-first-analytics' ),
			'body'          => __( 'Voris is still recording your events; usage beyond your plan may incur overage charges. Review your plan.', 'voris-privacy-first-analytics' ),
			'action_url'    => $action,
		);
	}

	/**
	 * Second-site block: the bound site differs from the site this install runs
	 * on (C10 normalized host compare).
	 *
	 * @param array $nudges Nudge accumulator (by reference).
	 * @param array $read   The plan read.
	 * @return void
	 */
	private static function add_second_site_nudge( array &$nudges, array $read ): void {
		$bound = isset( $read['site']['domain'] ) ? self::normalize_host( $read['site']['domain'] ) : '';
		if ( '' === $bound ) {
			return;
		}

		$here = self::normalize_host( wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $bound === $here ) {
			return;
		}

		$nudges[] = array(
			'code'          => 'second_site',
			'level'         => 'warning',
			'dismissible'   => false,
			'threshold_key' => '',
			'title'         => __( "This isn't the connected site", 'voris-privacy-first-analytics' ),
			'body'          => __( 'This plugin is connected to a different site in your Voris workspace. Your plan may allow only one site — upgrade to add another, or reconnect this install to this site.', 'voris-privacy-first-analytics' ),
			'action_url'    => self::safe_action_url( isset( $read['upgrade']['url'] ) ? $read['upgrade']['url'] : '' ),
		);
	}

	/**
	 * The stale "couldn't refresh" notice (row 7).
	 *
	 * @return array
	 */
	private static function stale_nudge(): array {
		$as_of = self::format_datetime( Voris_Plan::fetched_at() );

		return array(
			'code'          => 'stale',
			'level'         => 'info',
			'dismissible'   => false,
			'threshold_key' => '',
			'title'         => __( 'Your Voris usage may be out of date', 'voris-privacy-first-analytics' ),
			'body'          => '' !== $as_of
				/* translators: %s: human-readable date and time of the last successful refresh. */
				? sprintf( __( "Voris couldn't be reached to refresh your usage. Showing the last known figures from %s.", 'voris-privacy-first-analytics' ), $as_of )
				: __( "Voris couldn't be reached to refresh your usage yet.", 'voris-privacy-first-analytics' ),
			'action_url'    => '',
		);
	}

	/**
	 * Record a dismissal, pruning dismissals from a different window/cap.
	 *
	 * @param string $key The nudge's threshold_key.
	 * @return void
	 */
	public static function dismiss( $key ): void {
		$key = (string) $key;
		if ( '' === $key ) {
			return;
		}

		$prefix = self::key_prefix( $key );
		$store  = get_option( self::DISMISS_OPTION, array() );
		$store  = is_array( $store ) ? $store : array();

		foreach ( array_keys( $store ) as $existing ) {
			if ( self::key_prefix( (string) $existing ) !== $prefix ) {
				unset( $store[ $existing ] );
			}
		}

		$store[ $key ] = true;
		update_option( self::DISMISS_OPTION, $store, false );
	}

	/**
	 * Forget every dismissal (disconnect / uninstall).
	 *
	 * Dismissals are per-account state: on disconnect they must not carry over to a
	 * connection to a different Voris account (VOR-306 / ADR-ACH004).
	 *
	 * @return void
	 */
	public static function clear_dismissals(): void {
		delete_option( self::DISMISS_OPTION );
	}

	/**
	 * Whether a threshold has been dismissed for the current window + cap.
	 *
	 * @param string $key The nudge's threshold_key.
	 * @return bool
	 */
	public static function is_dismissed( $key ): bool {
		$key   = (string) $key;
		$store = get_option( self::DISMISS_OPTION, array() );

		return is_array( $store ) && ! empty( $store[ $key ] );
	}

	/**
	 * Ajax: dismiss a threshold (admin only, nonce-checked).
	 *
	 * @return void
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		self::dismiss( $key );
		wp_send_json_success();
	}

	/**
	 * The dismissal key: window start, effective cap, threshold. Any change to
	 * the window or the cap yields a new key ⇒ the dismissal naturally resets
	 * (C13 / amendments §1).
	 *
	 * @param array  $read      The plan read.
	 * @param string $threshold The threshold name.
	 * @return string
	 */
	private static function threshold_key( array $read, $threshold ): string {
		$period = isset( $read['period']['start'] ) ? (string) $read['period']['start'] : '';
		$cap    = isset( $read['limits']['eventsPerMonth'] ) ? (int) $read['limits']['eventsPerMonth'] : 0;

		return $period . '|' . $cap . '|' . $threshold;
	}

	/**
	 * The window+cap prefix of a threshold key (everything but the threshold).
	 *
	 * @param string $key The threshold key.
	 * @return string
	 */
	private static function key_prefix( $key ): string {
		$parts = explode( '|', (string) $key );

		return isset( $parts[0], $parts[1] ) ? $parts[0] . '|' . $parts[1] . '|' : (string) $key;
	}

	/**
	 * Return the URL only if it is hosted on app.voris.ai, else '' (AC15: the
	 * plugin never renders a foreign or checkout URL).
	 *
	 * @param mixed $url The served upgrade URL.
	 * @return string
	 */
	private static function safe_action_url( $url ): string {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return ( 'app.voris.ai' === $host ) ? $url : '';
	}

	/**
	 * Normalize a host for comparison: strip scheme/port/path, lowercase, drop a
	 * leading `www.` and a trailing dot, best-effort IDN→ASCII (C10).
	 *
	 * @param mixed $host A host or URL.
	 * @return string
	 */
	private static function normalize_host( $host ): string {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return '';
		}

		// Accept a full URL too.
		if ( false !== strpos( $host, '/' ) ) {
			$parsed = wp_parse_url( $host, PHP_URL_HOST );
			$host   = $parsed ? strtolower( $parsed ) : $host;
		}

		$host = preg_replace( '/:\d+$/', '', $host ); // Port.
		$host = rtrim( $host, '.' );                  // Trailing dot.
		$host = preg_replace( '/^www\./', '', $host ); // Leading www.

		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				$host = $ascii;
			}
		}

		return $host;
	}

	/**
	 * Format an ISO-8601 instant as a localized date, or '' if unparseable.
	 *
	 * @param mixed $iso The instant.
	 * @return string
	 */
	private static function format_date( $iso ): string {
		$ts = strtotime( (string) $iso );

		return $ts ? date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $ts ) : '';
	}

	/**
	 * Format a unix timestamp as a localized date + time, or '' if empty.
	 *
	 * @param int $ts The timestamp.
	 * @return string
	 */
	private static function format_datetime( $ts ): string {
		$ts = (int) $ts;
		if ( 0 === $ts ) {
			return '';
		}

		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );

		return date_i18n( (string) $format, $ts );
	}
}
