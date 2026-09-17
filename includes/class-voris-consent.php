<?php
/**
 * Consent-management integration.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the plugin to a site's Consent Management Platform through the WP
 * Consent API (ADR-ACH005).
 *
 * The plugin's default posture is Mode A — cookieless, no identifier, no consent
 * required — so a site with no consent manager is unaffected. When a
 * WP-Consent-API-compatible manager is active, the hosted browser tag defers to
 * its statistics-anonymous verdict. This class owns that decision and the
 * detection used only to phrase the settings-screen message; the tag stays a
 * dumb renderer and the admin page stays a view.
 */
final class Voris_Consent {

	/**
	 * The WP Consent API category the tag registers under.
	 *
	 * `statistics-anonymous` matches the Mode A posture — cookieless and
	 * aggregate — and is the least-restrictive statistics category, so a
	 * correctly configured manager keeps Mode A analytics flowing while still
	 * gating a site that is set to require consent (ADR-ACH005).
	 *
	 * @var string
	 */
	const CONSENT_CATEGORY = 'statistics-anonymous';

	/**
	 * Common WordPress consent plugins, by active-plugin basename → display name.
	 *
	 * Best-effort and for the settings message only: the gate depends on the WP
	 * Consent API, never on naming a specific vendor, so a miss here degrades the
	 * copy to "a consent plugin" and never the behaviour (clarify C5).
	 *
	 * @var array<string,string>
	 */
	private const MANAGERS = array(
		'cookie-law-info/cookie-law-info.php' => 'CookieYes',
		// 'gpdr' is Complianz's real, vendor-misspelled main-file name — not a typo. Do not "fix" it.
		'complianz-gdpr/complianz-gpdr.php'   => 'Complianz',
		'complianz-gdpr-premium/complianz-gpdr-premium.php' => 'Complianz',
		'cookiebot/cookiebot.php'             => 'Cookiebot',
		'cookiebot-cmp/cookiebot-cmp.php'     => 'Cookiebot',
		'borlabs-cookie/borlabs-cookie.php'   => 'Borlabs Cookie',
		'real-cookie-banner/index.php'        => 'Real Cookie Banner',
		'wpconsent-cookies-banner-privacy-suite/wpconsent.php' => 'WPConsent',
	);

	/**
	 * Declare the plugin consent-aware to the WP Consent API.
	 *
	 * The filter is cheap and harmless when no consent manager is installed, so
	 * it is registered unconditionally. It stops a manager warning the owner that
	 * Voris is not integrated.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_consent_api_registered_' . plugin_basename( VORIS_PLUGIN_FILE ), '__return_true' );
	}

	/**
	 * Whether the hosted tag may load on this request.
	 *
	 * No WP Consent API on the site ⇒ allowed (Mode A needs no consent, and the
	 * behaviour must not change for the majority of sites — AC3). A manager
	 * present ⇒ its statistics-anonymous verdict (AC4). The result passes through
	 * one filter, both as a genuine escape hatch — a site can integrate a manager
	 * this class does not detect, or override the decision — and as the seam the
	 * tests drive so both outcomes are provable without a live manager.
	 *
	 * A local, synchronous read: no network, nothing that can block render (§16).
	 *
	 * @return bool
	 */
	public static function has_consent(): bool {
		$granted = ! function_exists( 'wp_has_consent' ) || (bool) wp_has_consent( self::CONSENT_CATEGORY );

		/**
		 * Filter whether Voris's hosted tag may load, given the site's consent state.
		 *
		 * @param bool $granted Whether statistics-anonymous consent is present (or not required).
		 */
		return (bool) apply_filters( 'voris_tag_has_consent', $granted );
	}

	/**
	 * What the settings screen needs to describe the consent situation.
	 *
	 * @return array{manager:string,consent_api:bool} `manager` is a display name
	 *         or '' when none is recognised; `consent_api` is whether a WP Consent
	 *         API is active.
	 */
	public static function detect(): array {
		return array(
			'manager'     => self::active_manager(),
			'consent_api' => function_exists( 'wp_has_consent' ),
		);
	}

	/**
	 * The display name of the first recognised consent plugin, or ''.
	 *
	 * Only ever called from the admin screen, where `plugin.php` is available;
	 * loaded defensively in case a caller reaches here earlier.
	 *
	 * @return string
	 */
	private static function active_manager(): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::MANAGERS as $basename => $name ) {
			if ( is_plugin_active( $basename ) ) {
				return $name;
			}
		}

		return '';
	}
}
