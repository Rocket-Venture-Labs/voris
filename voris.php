<?php
/**
 * Plugin Name:       Voris — Privacy-first Analytics
 * Plugin URI:        https://voris.ai
 * Description:       Sends analytics to Voris, a privacy-first customer data platform.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Rocket Venture Labs
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       voris
 * Domain Path:       /languages
 *
 * This file must stay parse-safe on PHP versions below the 8.1 floor (C5), so
 * it uses no syntax newer than the oldest PHP a WordPress site might run.
 * Everything using 8.1 syntax lives under includes/.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

define( 'VORIS_VERSION', '1.0.0' );
define( 'VORIS_PLUGIN_FILE', __FILE__ );

/*
 * Where a site's analytics go is hard-wired (C1) — no setting, no filter, no
 * overridable constant. That is why the endpoints are NOT global constants:
 * define() keeps the first value it sees, so wp-config.php or an mu-plugin,
 * both of which run before plugins load, could pre-empt one and redirect every
 * payload. A class constant cannot be pre-empted, so each endpoint lives on the
 * class that owns it — Voris_Transport::INGEST_URL, and the tag URL on the
 * renderer at T12. This is also how WooCommerce declares its own remote
 * endpoints. Paths are derived from __FILE__ for the same reason.
 */

/**
 * Show why the plugin refused to load on an unsupported PHP version.
 *
 * @return void
 */
function voris_render_php_version_notice() {
	// Core gates its own PHP notice this way; the version is not for subscribers.
	if ( ! current_user_can( 'update_php' ) ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: minimum required PHP version, 2: PHP version in use */
		__( 'Voris requires PHP %1$s or newer. This site runs PHP %2$s, so Voris has been deactivated.', 'voris' ),
		'8.1',
		PHP_VERSION
	);

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
}

/**
 * Deactivate the plugin when the PHP floor is not met (C5, graceful).
 *
 * @return void
 */
function voris_deactivate_on_unsupported_php() {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$voris_plugin = plugin_basename( __FILE__ );

	/*
	 * Without the network flag a network-activated plugin is never actually
	 * deactivated, and the notice repeats on every admin page forever.
	 */
	$voris_network_wide = is_multisite() && is_plugin_active_for_network( $voris_plugin );

	deactivate_plugins( $voris_plugin, true, $voris_network_wide );
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', 'voris_render_php_version_notice' );
	add_action( 'admin_init', 'voris_deactivate_on_unsupported_php' );

	return;
}

/**
 * Load the plugin's classes and register their hooks.
 *
 * @return void
 */
function voris_load_classes() {
	$voris_dir = plugin_dir_path( __FILE__ );

	require_once $voris_dir . 'includes/class-voris-ulid.php';
	require_once $voris_dir . 'includes/class-voris-settings.php';
	require_once $voris_dir . 'includes/class-voris-connection.php';
	require_once $voris_dir . 'includes/class-voris-payload.php';
	require_once $voris_dir . 'includes/class-voris-transport.php';
	require_once $voris_dir . 'includes/class-voris-oauth.php';
	require_once $voris_dir . 'includes/class-voris-partner-api.php';
	require_once $voris_dir . 'includes/class-voris-connect.php';
	require_once $voris_dir . 'includes/class-voris-plan.php';
	require_once $voris_dir . 'includes/class-voris-nudge.php';
	require_once $voris_dir . 'includes/class-voris-queue.php';
	require_once $voris_dir . 'includes/class-voris-dispatcher.php';
	require_once $voris_dir . 'includes/class-voris-consent.php';
	require_once $voris_dir . 'includes/class-voris-tag.php';
	require_once $voris_dir . 'includes/class-voris-lifecycle.php';
	require_once $voris_dir . 'admin/class-voris-admin-page.php';
	require_once $voris_dir . 'integrations/class-voris-woocommerce.php';
	require_once $voris_dir . 'includes/class-voris-plugin.php';
}

/**
 * Bootstrap the plugin. Hooked to `plugins_loaded`.
 *
 * @return void
 */
function voris_bootstrap() {
	voris_load_classes();

	Voris_Plugin::instance()->init();
}

/**
 * Set the plugin up on activation.
 *
 * Loads the classes itself rather than relying on the bootstrap. WordPress
 * includes this file during activation at a point where `plugins_loaded` has
 * already fired — the plugin was not active when it did — so `voris_bootstrap`
 * never runs, and a bare `array( 'Voris_Lifecycle', 'activate' )` callback
 * would be an undefined class and a fatal.
 *
 * @return void
 */
function voris_activate() {
	voris_load_classes();

	Voris_Lifecycle::activate();
}

/**
 * Stop scheduled work on deactivation. Loads its own classes, as above.
 *
 * @return void
 */
function voris_deactivate() {
	voris_load_classes();

	Voris_Lifecycle::deactivate();
}

/**
 * Declare High-Performance Order Storage compatibility.
 *
 * Also loads its own classes: this fires from `WooCommerce::init()` on `init`
 * priority 0, which is after `plugins_loaded` for an active plugin — but
 * depending on that ordering is what made activation fatal.
 *
 * @return void
 */
function voris_declare_woocommerce_compatibility() {
	voris_load_classes();

	Voris_WooCommerce::declare_hpos_compatibility();
}

add_action( 'plugins_loaded', 'voris_bootstrap' );

/*
 * All three are registered at file scope, and every callback is a plain
 * function that loads its own classes. A class callable would be a fatal
 * during activation, when WordPress includes this file after `plugins_loaded`
 * has already fired.
 */
add_action( 'before_woocommerce_init', 'voris_declare_woocommerce_compatibility' );

register_activation_hook( __FILE__, 'voris_activate' );
register_deactivation_hook( __FILE__, 'voris_deactivate' );
