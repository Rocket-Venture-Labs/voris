<?php
/**
 * Plugin settings.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single `voris_settings` option: its defaults, its validation, and
 * the questions the rest of the plugin asks of it.
 *
 * The option is autoloaded because the front-end tag reads it on every
 * request. Endpoints are deliberately absent — they are hard-wired constants
 * with no setting, constant or filter to override them (C1).
 */
final class Voris_Settings {

	/**
	 * Option name. One array, not a key per setting.
	 *
	 * @var string
	 */
	const OPTION = 'voris_settings';

	/**
	 * Settings API group, used by `register_setting()`.
	 *
	 * @var string
	 */
	const GROUP = 'voris_settings_group';

	/**
	 * A site id is a UUID v4: version nibble 4, variant nibble 8, 9, a or b.
	 *
	 * @var string
	 */
	const SITE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

	/**
	 * The shipped defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'site_id'                => '',
			'tracking_enabled'       => true,
			'keep_data_on_uninstall' => false,
		);
	}

	/**
	 * Create the option, autoloaded, if it does not exist yet.
	 *
	 * Safe to call repeatedly: an existing option is left untouched.
	 *
	 * @return void
	 */
	public static function install(): void {
		add_option( self::OPTION, self::defaults(), '', 'yes' );
	}

	/**
	 * Attach the sanitizer to the option.
	 *
	 * Called unconditionally at bootstrap, not from `admin_init`: what
	 * `register_setting()` actually installs is the
	 * `sanitize_option_voris_settings` filter, and a write from WP-CLI, cron,
	 * or another plugin would otherwise bypass validation entirely.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Every setting, with defaults filled in for anything missing.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed Null when the name is unknown.
	 */
	private static function get( string $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * The configured Voris site id, which may be empty.
	 *
	 * @return string
	 */
	public static function site_id(): string {
		return (string) self::get( 'site_id' );
	}

	/**
	 * Set the site id — the SINGLE source the front-end tag + WooCommerce path read.
	 *
	 * The OAuth connect flow calls this on mint so a connected site actually tracks
	 * (the grant store alone never lights up the tag). Goes through `update_option`,
	 * so the registered `sanitize_option_voris_settings` sanitizer validates it
	 * (an invalid id is rejected and not stored).
	 *
	 * @param string $site_id A Voris site id (UUID v4).
	 * @return void
	 */
	public static function set_site_id( string $site_id ): void {
		$all            = self::all();
		$all['site_id'] = $site_id;

		update_option( self::OPTION, $all );
	}

	/**
	 * Whether a usable site id is stored.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return self::is_valid_site_id( self::site_id() );
	}

	/**
	 * Whether the owner has tracking switched on.
	 *
	 * @return bool
	 */
	public static function is_tracking_enabled(): bool {
		return (bool) self::get( 'tracking_enabled' );
	}

	/**
	 * Whether the owner opted to keep their data when uninstalling (C4).
	 *
	 * @return bool
	 */
	public static function keeps_data_on_uninstall(): bool {
		return (bool) self::get( 'keep_data_on_uninstall' );
	}

	/**
	 * Whether a string is a well-formed UUID v4.
	 *
	 * @param string $value Candidate site id.
	 * @return bool
	 */
	public static function is_valid_site_id( string $value ): bool {
		return 1 === preg_match( self::SITE_ID_PATTERN, $value );
	}

	/**
	 * Settings API `sanitize_callback`.
	 *
	 * Builds the saved value from the current one, so unknown keys are dropped
	 * rather than stored, and a rejected site id leaves the previous value in
	 * place (AC3).
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$current = self::all();

		/*
		 * `options.php` hands `null` to `update_option()` for a registered
		 * option that is absent from the post, and the sanitize filter runs on
		 * every write. Rebuilding from an empty array would read every absent
		 * checkbox as "unchecked" and silently switch tracking off, so a
		 * non-array input changes nothing at all.
		 */
		if ( ! is_array( $input ) ) {
			return $current;
		}

		// Drop any stray key already in the option, not just unknown input.
		$clean = array_intersect_key( $current, self::defaults() );

		if ( array_key_exists( 'site_id', $input ) ) {
			$clean['site_id'] = self::sanitize_site_id( $input['site_id'], $current['site_id'] );
		}

		$clean['tracking_enabled']       = ! empty( $input['tracking_enabled'] );
		$clean['keep_data_on_uninstall'] = ! empty( $input['keep_data_on_uninstall'] );

		return $clean;
	}

	/**
	 * Validate a submitted site id.
	 *
	 * An empty submission disconnects the site deliberately, so it is accepted
	 * without complaint. Anything else that is not a UUID v4 is refused and the
	 * previous value kept.
	 *
	 * @param mixed  $submitted Raw submitted site id.
	 * @param string $previous  Currently stored site id.
	 * @return string
	 */
	private static function sanitize_site_id( $submitted, string $previous ): string {
		// Cast first and an array raises a conversion warning (§4).
		$raw       = is_string( $submitted ) ? $submitted : '';
		$candidate = strtolower( trim( sanitize_text_field( $raw ) ) );

		if ( '' === $candidate || self::is_valid_site_id( $candidate ) ) {
			return $candidate;
		}

		/*
		 * The sanitize filter runs on every write, including outside wp-admin
		 * where this function is not loaded. Core guards its own call the same
		 * way in `sanitize_option()`.
		 */
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'voris_invalid_site_id',
				__( 'That does not look like a Voris site id. Copy it from your site&#8217;s settings in app.voris.ai — it looks like 3f2504e0-4f89-41d3-9a0c-0305e82c3301.', 'voris' ),
				'error'
			);
		}

		return $previous;
	}
}
