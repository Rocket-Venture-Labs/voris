<?php
/**
 * Settings screen.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's settings screen: Tools → Voris.
 *
 * The settings form posts to core's `options.php`, which handles its own nonce
 * and capability — so this screen adds no settings form handler and no REST
 * route (constitution §7). It is not the plugin's only admin surface, though:
 * the one-click connect flow (`Voris_Connect`) registers three admin-ajax
 * endpoints and nudge dismissal (`Voris_Nudge`) one — each gated on a nonce
 * AND `manage_options` and driven by a script this screen enqueues.
 */
final class Voris_Admin_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'voris';

	/**
	 * Who may see or change any of this (AC16).
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The admin-page hook suffix WordPress derives for this screen under Tools
	 * (`add_management_page` → `tools_page_{slug}`). Single-sourced here so the
	 * script-enqueue guards and the background-refresh hook cannot drift from the
	 * menu location.
	 *
	 * @var string
	 */
	const HOOK_SUFFIX = 'tools_page_' . self::MENU_SLUG;

	/**
	 * Transient that carries the cache-purge advice to the next page load.
	 *
	 * @var string
	 */
	const CACHE_NOTICE_TRANSIENT = 'voris_cache_notice';

	/**
	 * Hook the screen up.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// A Settings link on the plugins.php row, so a just-activated site can
		// jump straight to setup (VOR-317).
		add_filter(
			'plugin_action_links_' . plugin_basename( VORIS_PLUGIN_FILE ),
			array( $this, 'add_settings_action_link' )
		);

		/*
		 * Both hooks: update_option() delegates to add_option() when the
		 * option does not exist yet, so a fresh install's first save fires
		 * add_option_* and never update_option_*.
		 */
		add_action( 'update_option_' . Voris_Settings::OPTION, array( $this, 'flag_cache_notice' ) );
		add_action( 'add_option_' . Voris_Settings::OPTION, array( $this, 'flag_cache_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_cache_notice' ) );
	}

	/**
	 * Remember that the tag's configuration changed.
	 *
	 * A page-cache plugin will keep serving the old HTML — with the old site
	 * id, or with a tag that should now be gone — until its cache is purged.
	 *
	 * @return void
	 */
	public function flag_cache_notice(): void {
		set_transient( self::CACHE_NOTICE_TRANSIENT, 1, MINUTE_IN_SECONDS * 5 );
	}

	/**
	 * Show the cache-purge advice once, to administrators only.
	 *
	 * @return void
	 */
	public function render_cache_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! get_transient( self::CACHE_NOTICE_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::CACHE_NOTICE_TRANSIENT );

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'Voris settings saved. If your site uses a page cache, purge it so visitors get the updated tracking setup.', 'voris' )
		);
	}

	/**
	 * Add the page under Tools.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_management_page(
			__( 'Voris', 'voris' ),
			__( 'Voris', 'voris' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Prepend a Settings link to the plugin's row on wp-admin/plugins.php (VOR-317).
	 *
	 * The link points at this screen under Tools. It is prepended so it sits
	 * before the core Deactivate link, the WordPress convention; the incoming
	 * links are preserved, never replaced.
	 *
	 * @param string[] $links Existing action links for this plugin's row.
	 * @return string[]
	 */
	public function add_settings_action_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Settings', 'voris' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register the option so `options.php` will accept it, sanitized.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		add_settings_section(
			'voris_connection',
			__( 'Connection', 'voris' ),
			array( $this, 'render_connection_status' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'voris_site_id',
			__( 'Site ID', 'voris' ),
			array( $this, 'render_site_id_field' ),
			self::MENU_SLUG,
			'voris_connection',
			array( 'label_for' => 'voris_site_id' )
		);

		add_settings_section(
			'voris_tracking',
			__( 'Tracking', 'voris' ),
			'__return_empty_string',
			self::MENU_SLUG
		);

		add_settings_field(
			'voris_tracking_enabled',
			__( 'Collect analytics', 'voris' ),
			array( $this, 'render_tracking_field' ),
			self::MENU_SLUG,
			'voris_tracking'
		);

		add_settings_section(
			'voris_data',
			__( 'Data', 'voris' ),
			'__return_empty_string',
			self::MENU_SLUG
		);

		add_settings_field(
			'voris_keep_data_on_uninstall',
			__( 'When uninstalling', 'voris' ),
			array( $this, 'render_keep_data_field' ),
			self::MENU_SLUG,
			'voris_data'
		);
	}

	/**
	 * Say whether this site is connected, above the field that decides it.
	 *
	 * @return void
	 */
	public function render_connection_status(): void {
		if ( Voris_Settings::is_connected() ) {
			$status_line = __( 'Connected. Voris is receiving data from this site.', 'voris' );
		} elseif ( Voris_Connection::is_connected() ) {
			// A grant is held but the site is not fully bound yet — the connection is
			// still being finished (VOR-298), not a plain "not connected" state.
			$status_line = __( 'Finishing connecting to Voris…', 'voris' );
		} else {
			$status_line = __( 'Not connected. Connect your Voris account, or add a site ID below.', 'voris' );
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $status_line ) );

		// Third-party disclosure (wp.org Guideline 7): the data-sharing statement + the
		// Voris Terms + Privacy Policy, shown where the user activates the integration.
		printf(
			'<p class="description">%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: Privacy Policy link, 2: Terms of Service link. */
					__( 'Connecting sends this site&#8217;s analytics to Voris, an external service operated by Rocket Venture Labs B.V. See what is shared in the %1$s and %2$s.', 'voris' ),
					'<a href="' . esc_url( 'https://voris.ai/legal/privacy/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'voris' ) . '</a>',
					'<a href="' . esc_url( 'https://voris.ai/legal/terms/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'voris' ) . '</a>'
				),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			)
		);

		$grant_held      = Voris_Connection::is_connected();
		$fully_connected = Voris_Connection::is_fully_connected();

		// One-click connect / resume (ADR-ACH003 + ADR-ACH004): shown until the
		// connection is FULLY established (bound + attested), not merely until a grant
		// exists — so an incomplete connection stays recoverable in-UI instead of
		// stranding with no control (VOR-298). The manual site-id field below is the
		// always-available fallback. The button, approval link, and aria-live status
		// are driven by assets/js/connect.js.
		if ( ! $fully_connected ) {
			printf(
				'<p><button type="button" class="button button-primary" id="voris-connect-btn">%s</button></p>',
				esc_html( $grant_held ? __( 'Resume connection', 'voris' ) : __( 'Connect to Voris', 'voris' ) )
			);

			// Persistent, keyboard-focusable approval link + the visible user code
			// (VOR-299): the reliable route whether or not a pop-up is allowed. The JS
			// fills the href/code and reveals it; it is never the case that the screen
			// claims a tab opened when it did not.
			printf(
				'<p id="voris-connect-approve" hidden>%1$s <a id="voris-connect-link" href="#" target="_blank" rel="noopener">%2$s</a> <code id="voris-connect-code"></code></p>',
				esc_html__( 'Approve this connection in Voris:', 'voris' ),
				esc_html__( 'Open the Voris approval page', 'voris' )
			);

			printf( '<p id="voris-connect-status" role="status" aria-live="polite"></p>' );
		}

		// Disconnect / switch account (VOR-306): available whenever a grant is held —
		// including an incomplete one, so a stuck owner can escape. Nonce + capability
		// are enforced by the ajax handler; this is only the control.
		if ( $grant_held ) {
			printf(
				'<p><button type="button" class="button" id="voris-connect-disconnect">%s</button></p>',
				esc_html__( 'Disconnect', 'voris' )
			);
		}
	}

	/**
	 * The site id input.
	 *
	 * @return void
	 */
	public function render_site_id_field(): void {
		// While connected via a Voris account the id is managed by the grant, so
		// the manual field is read-only (C9 precedence); clearing the connection
		// makes it editable again.
		$readonly = Voris_Connection::is_connected() ? ' readonly' : '';

		printf(
			'<input type="text" id="voris_site_id" name="%1$s[site_id]" value="%2$s" class="regular-text" spellcheck="false" autocomplete="off" aria-describedby="voris_site_id_help"%3$s />',
			esc_attr( Voris_Settings::OPTION ),
			esc_attr( Voris_Settings::site_id() ),
			esc_attr( $readonly )
		);

		printf(
			'<p class="description" id="voris_site_id_help">%s</p>',
			esc_html(
				Voris_Connection::is_connected()
					? __( 'Managed by your connected Voris account. Disconnect to edit this manually.', 'voris' )
					: __( 'Find this in your site’s settings in app.voris.ai. It looks like 3f2504e0-4f89-41d3-9a0c-0305e82c3301. Leave it empty to disconnect.', 'voris' )
			)
		);
	}

	/**
	 * The tracking switch, in a fieldset so screen readers announce the group.
	 *
	 * @return void
	 */
	public function render_tracking_field(): void {
		$this->render_checkbox(
			'tracking_enabled',
			Voris_Settings::is_tracking_enabled(),
			__( 'Tracking', 'voris' ),
			__( 'Send pageviews and Core Web Vitals to Voris', 'voris' ),
			__( 'Turning this off removes the tracking tag from your site. Nothing is sent while it is off.', 'voris' )
		);
	}

	/**
	 * The uninstall data choice (C4 — remove by default).
	 *
	 * @return void
	 */
	public function render_keep_data_field(): void {
		$this->render_checkbox(
			'keep_data_on_uninstall',
			Voris_Settings::keeps_data_on_uninstall(),
			__( 'Uninstall behaviour', 'voris' ),
			__( 'Keep my settings if I uninstall Voris', 'voris' ),
			__( 'By default, uninstalling removes everything this plugin stored. Deactivating never deletes anything.', 'voris' )
		);
	}

	/**
	 * Render one checkbox as an accessible group.
	 *
	 * A checkbox cannot be the target of the table header's `label for`, so
	 * each group carries its own fieldset and legend instead (AC19).
	 *
	 * @param string $key         Settings key.
	 * @param bool   $checked     Current value.
	 * @param string $legend      Group name, visually hidden.
	 * @param string $label       Checkbox label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function render_checkbox( string $key, bool $checked, string $legend, string $label, string $description ): void {
		$id      = 'voris_' . $key;
		$help_id = $id . '_help';

		echo '<fieldset>';
		printf( '<legend class="screen-reader-text"><span>%s</span></legend>', esc_html( $legend ) );

		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s aria-describedby="%5$s" /> %6$s</label>',
			esc_attr( $id ),
			esc_attr( Voris_Settings::OPTION ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_attr( $help_id ),
			esc_html( $label )
		);

		printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $help_id ), esc_html( $description ) );
		echo '</fieldset>';
	}

	/**
	 * Render the screen.
	 *
	 * The capability is checked again here: `add_management_page()` governs the
	 * menu, but the page callback can still be reached directly.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Voris settings.', 'voris' ) );
		}

		echo '<div class="wrap">';

		// The brand logo, not a bare text heading (VOR-316). Kept inside the h1 so
		// the screen's accessible heading name is still exactly "Voris" (the alt
		// text); the asset is an SVG, so it is crisp at any pixel ratio, and it
		// makes no assumption about the admin colour scheme's foreground — the
		// content area is light under every core scheme.
		printf(
			'<h1><img src="%1$s" alt="%2$s" width="131" height="36" /></h1>',
			esc_url( plugins_url( 'assets/images/voris-logo.svg', VORIS_PLUGIN_FILE ) ),
			esc_attr__( 'Voris', 'voris' )
		);

		$this->render_nudges();

		/*
		 * This screen lives under Tools, not Settings, so core's options-head.php
		 * does not auto-print the sanitizer's validation errors — render them here
		 * (once), keyed to the option the sanitizer registers them against.
		 */
		settings_errors( Voris_Settings::OPTION );

		echo '<form action="options.php" method="post">';
		settings_fields( Voris_Settings::GROUP );
		do_settings_sections( self::MENU_SLUG );
		submit_button();
		echo '</form>';

		$this->render_transparency();
		$this->render_plan_summary();
		$this->render_delivery_log();

		echo '</div>';
	}

	/**
	 * Explain the privacy posture, consent situation, and exactly what is sent.
	 *
	 * Pure transparency (ACH003): reads no secret, writes no option, makes no
	 * request. The privacy-mode copy states the plugin's own always-true posture —
	 * the plugin never fetches a live mode from Voris (clarify C1). The consent
	 * copy is a pure function of `Voris_Consent::detect()`. The commerce list is
	 * shown only when WooCommerce is active.
	 *
	 * @return void
	 */
	private function render_transparency(): void {
		echo '<h2>' . esc_html__( 'Privacy &amp; data sharing', 'voris' ) . '</h2>';

		// Privacy mode (VOR-312).
		echo '<h3>' . esc_html__( 'Privacy mode', 'voris' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html__( 'This plugin runs in Voris Mode A by default: it is cookieless, creates no visitor, session, or user identifier, and needs no cookie banner. Voris resolves the exact privacy mode server-side, per visitor region — the plugin never sets or changes it, and never sends anything mode-specific.', 'voris' )
		);
		printf(
			'<p>%s</p>',
			esc_html__( 'Mode A is strictly aggregated and cookie-free. Mode B and Mode C add session-based identifiers that Voris derives on its own servers; on those modes, obtaining consent is part of your own legal setup, not something this plugin decides. Your mode is configured in app.voris.ai.', 'voris' )
		);

		// Consent (VOR-313).
		echo '<h3>' . esc_html__( 'Consent', 'voris' ) . '</h3>';
		printf(
			'<p class="description">%s</p>',
			esc_html( self::consent_message( Voris_Consent::detect() ) )
		);

		// What Voris receives (VOR-314).
		echo '<h3>' . esc_html__( 'What Voris receives from this site', 'voris' ) . '</h3>';
		printf(
			'<p>%s</p>',
			esc_html__( 'From the tracking tag: page views and Core Web Vitals (loading-speed measurements). In Mode A these set no cookies and carry no identifier.', 'voris' )
		);

		if ( class_exists( 'WooCommerce' ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'From WooCommerce, for each completed order: the order total, its currency, and the number of items, with the order ID as a reference. Refunds are sent as negative corrections.', 'voris' )
			);
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'Never sent: customer names, email or postal addresses, or any other personal data; product or line-item details; your visitors&#8217; IP addresses; and no visitor, session, or user identifier.', 'voris' )
		);
	}

	/**
	 * The consent sentence for a detection result (VOR-313).
	 *
	 * Separated as a pure function so all four states are testable without a live
	 * consent manager installed. `detect()` supplies the argument in `render()`.
	 *
	 * @param array $detect `{ manager: string, consent_api: bool }` from `Voris_Consent::detect()`.
	 * @return string
	 */
	public static function consent_message( array $detect ): string {
		$manager     = isset( $detect['manager'] ) ? (string) $detect['manager'] : '';
		$consent_api = ! empty( $detect['consent_api'] );

		if ( $consent_api && '' !== $manager ) {
			return sprintf(
				/* translators: %s: the detected consent plugin's name. */
				__( '%s is active, and the WP Consent API is available. Voris is registered as a consent-aware plugin, so its tracking tag waits for statistics consent before it loads.', 'voris' ),
				$manager
			);
		}

		if ( $consent_api ) {
			return __( 'A consent plugin that supports the WP Consent API is active. Voris is registered as consent-aware, so its tracking tag waits for statistics consent before it loads.', 'voris' );
		}

		if ( '' !== $manager ) {
			return sprintf(
				/* translators: 1: the detected consent plugin's name, 2: the same name again. */
				__( 'We detected %1$s, but it does not use the WP Consent API, so Voris cannot read its consent state automatically. In Mode A no consent is required; for Mode B or C, configure %2$s to cover analytics.', 'voris' ),
				$manager,
				$manager
			);
		}

		return __( 'No consent-management plugin was detected. In Mode A, Voris is cookieless and needs no consent banner. If your Voris account uses Mode B or C, consent is your responsibility — a plugin that supports the WP Consent API will integrate with Voris automatically.', 'voris' );
	}

	/**
	 * Render the active plan nudges (AC12/13).
	 *
	 * Every string is the plugin's own copy authored from the served codes
	 * (CL10); the link, when present, is host-pinned to app.voris.ai by the
	 * engine. Kept on this capability-gated screen — never a site-wide banner —
	 * so the second-site prompt stays at the point of action (table row 6).
	 *
	 * @return void
	 */
	private function render_nudges(): void {
		$levels = array(
			'critical' => 'notice-error',
			'warning'  => 'notice-warning',
			'info'     => 'notice-info',
		);

		foreach ( Voris_Nudge::active() as $nudge ) {
			$class = isset( $levels[ $nudge['level'] ] ) ? $levels[ $nudge['level'] ] : 'notice-info';

			printf( '<div class="notice %s voris-nudge">', esc_attr( $class ) );
			printf( '<p><strong>%s</strong></p>', esc_html( $nudge['title'] ) );
			printf( '<p>%s</p>', esc_html( $nudge['body'] ) );

			if ( '' !== $nudge['action_url'] ) {
				printf(
					'<p><a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
					esc_url( $nudge['action_url'] ),
					esc_html__( 'Open Voris', 'voris' )
				);
			}

			if ( $nudge['dismissible'] ) {
				printf(
					'<p><button type="button" class="button-link voris-nudge-dismiss" data-key="%s">%s</button></p>',
					esc_attr( $nudge['threshold_key'] ),
					esc_html__( 'Dismiss', 'voris' )
				);
			}

			echo '</div>';
		}
	}

	/**
	 * Show the served plan usage against the cap (AC11).
	 *
	 * The numbers here are Voris's own served figures, rendered verbatim — a plan
	 * change is reflected without a plugin update. Two clocks are distinguished:
	 * `fetched_at` (our last contact — the "couldn't refresh" caveat) and the
	 * served `measuredAt` ("as of"); `not_yet_measured` is a real zero, not stale.
	 *
	 * @return void
	 */
	private function render_plan_summary(): void {
		$read = Voris_Plan::read();
		if ( null === $read ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Plan & usage', 'voris' ) . '</h2>';

		if ( Voris_Plan::is_stale() ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Voris could not be reached to refresh these figures. Showing the last known usage.', 'voris' )
			);
		}

		$state = isset( $read['usage']['state'] ) ? (string) $read['usage']['state'] : '';
		$cap   = isset( $read['limits']['eventsPerMonth'] ) ? (int) $read['limits']['eventsPerMonth'] : 0;
		$used  = isset( $read['usage']['events'] ) ? (int) $read['usage']['events'] : 0;

		if ( 'not_yet_measured' === $state ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Usage has not yet been measured this billing period.', 'voris' )
			);

			return;
		}

		if ( 'measured' !== $state || $cap <= 0 ) {
			return;
		}

		$percent = min( 100, (int) round( ( $used / $cap ) * 100 ) );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: events used, 2: monthly cap, 3: percentage. */
					__( '%1$s of %2$s events used this billing period (%3$d%%).', 'voris' ),
					number_format_i18n( $used ),
					number_format_i18n( $cap ),
					$percent
				)
			)
		);

		printf(
			'<progress max="100" value="%1$d" aria-label="%2$s"></progress>',
			esc_attr( (string) $percent ),
			esc_attr__( 'Share of your monthly event allowance used', 'voris' )
		);

		$measured_at = isset( $read['usage']['measuredAt'] ) ? strtotime( (string) $read['usage']['measuredAt'] ) : false;
		if ( $measured_at ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable date and time Voris last measured usage. */
						__( 'As of %s.', 'voris' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $measured_at )
					)
				)
			);
		}
	}

	/**
	 * List deliveries the plugin gave up on (AC10).
	 *
	 * Read-only, and inside the capability-gated screen, so it is never
	 * visible to anyone who could not already change the settings. The log
	 * holds references only — no payload, no site id, no revenue figure — so
	 * there is nothing here to leak (§11, §14).
	 *
	 * @return void
	 */
	private function render_delivery_log(): void {
		$entries = Voris_Queue::log();

		echo '<h2>' . esc_html__( 'Delivery log', 'voris' ) . '</h2>';

		if ( empty( $entries ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No delivery failures. Everything this site sent has reached Voris.', 'voris' )
			);

			return;
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'Events that could not be delivered. Visitors never see these; page rendering and checkout are unaffected.', 'voris' )
		);

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'When', 'voris' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Event', 'voris' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Order', 'voris' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Event ID', 'voris' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Reason', 'voris' ) );
		echo '</tr></thead><tbody>';

		// Newest first: the log is stored oldest-first.
		foreach ( array_reverse( $entries ) as $entry ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $this->format_log_time( $entry ) ) );
			printf( '<td>%s</td>', esc_html( $this->log_value( $entry, 'event_name' ) ) );
			printf( '<td>%s</td>', esc_html( $this->log_value( $entry, 'order_ref' ) ) );

			// A ULID, safe to print, and the only value that lets an owner
			// correlate a local failure with a Voris-side record.
			printf( '<td><code>%s</code></td>', esc_html( $this->log_value( $entry, 'event_id' ) ) );
			printf( '<td>%s</td>', esc_html( $this->describe_failure( $entry ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Read one field from a log entry, tolerating a corrupted option.
	 *
	 * Casting an array to string would raise a warning on an admin screen,
	 * and the log's own option is only validated at the top level.
	 *
	 * @param array  $entry Log entry.
	 * @param string $key   Field name.
	 * @return string
	 */
	private function log_value( array $entry, string $key ): string {
		return isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
	}

	/**
	 * A log entry's timestamp in the site's own format.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private function format_log_time( array $entry ): string {
		if ( empty( $entry['time'] ) ) {
			return '';
		}

		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) $entry['time']
		);
	}

	/**
	 * Say what went wrong in words the site owner can act on.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private function describe_failure( array $entry ): string {
		$code   = (int) ( $entry['code'] ?? 0 );
		$reason = (string) ( $entry['reason'] ?? '' );

		if ( 'queue_overflow' === $reason ) {
			return __( 'Dropped — too many events were waiting to be sent.', 'voris' );
		}

		if ( 'purchase_not_delivered' === $reason ) {
			return __( 'Not reported — Voris never received the original order, so a correction would show negative revenue against a sale it has no record of.', 'voris' );
		}

		if ( 'refused_locally' === $reason ) {
			return __( 'Not reported — the order carried no revenue Voris could record.', 'voris' );
		}

		if ( 'encode_failed' === $reason ) {
			return __( 'Could not be prepared for sending. Check the order for unusual characters.', 'voris' );
		}

		if ( 404 === $code ) {
			return __( 'Voris did not recognise this site ID. Check the Connection setting above.', 'voris' );
		}

		if ( 400 === $code ) {
			return __( 'Voris rejected the event.', 'voris' );
		}

		if ( 'gave_up' === $reason ) {
			return __( 'Voris could not be reached after three attempts.', 'voris' );
		}

		/*
		 * Deliberately says nothing about the cause. Naming a specific one
		 * here would eventually print something untrue: a corrupted entry, or
		 * a reason added by a later writer, would be reported as a
		 * connectivity failure it never was.
		 */
		return __( 'Not delivered. See the event and order references above.', 'voris' );
	}
}
