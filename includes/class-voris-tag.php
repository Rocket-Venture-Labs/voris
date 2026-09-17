<?php
/**
 * Hosted browser tag.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints Voris's hosted script on the front end.
 *
 * The plugin ships no tracker code. The hosted script handles pageviews, Core
 * Web Vitals, SPA navigation, `sendBeacon` on unload, and DNT/GPC, and in
 * Mode A touches no cookie or storage — so the plugin adds nothing on top and
 * emits no inline JavaScript.
 */
final class Voris_Tag {

	/**
	 * Voris's hosted script. Hard-wired by C1.
	 *
	 * A class constant rather than a `define()`: a global constant keeps
	 * whichever value was set first, so wp-config.php or an mu-plugin could
	 * pre-empt it and serve every visitor a script of their choosing.
	 *
	 * @var string
	 */
	private const SCRIPT_URL = 'https://cdn.voris.ai/v1/vor.js';

	/**
	 * Hook the renderer, front end only.
	 *
	 * The admin check happens here rather than inside `render()` so the
	 * callback does not even exist on an admin request (AC7).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'render' ) );
	}

	/**
	 * Print the tag, if this site should be sending anything at all.
	 *
	 * Reads only the autoloaded settings option, so it costs no query on a
	 * front-end request.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Voris_Settings::is_connected() || ! Voris_Settings::is_tracking_enabled() ) {
			return;
		}

		/*
		 * Defer to a consent manager when one is active (ADR-ACH005). With no WP
		 * Consent API on the site this is always true, so the tag renders exactly
		 * as before — Mode A is cookieless and needs no consent.
		 */
		if ( ! Voris_Consent::has_consent() ) {
			return;
		}

		/*
		 * wp_print_script_tag() escapes the attributes and passes them through
		 * `wp_script_attributes`, so CSP plugins can add a nonce.
		 */
		wp_print_script_tag(
			array(
				'src'          => self::SCRIPT_URL,
				'defer'        => true,
				'data-site-id' => Voris_Settings::site_id(),
			)
		);
	}
}
