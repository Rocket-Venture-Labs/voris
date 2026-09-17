<?php
/**
 * Activation, deactivation, and schema upgrades.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that happens once, rather than on every request.
 *
 * The schema version lives in its own option rather than inside
 * `voris_settings`. `register_setting()` hooks the settings sanitizer to
 * `sanitize_option_voris_settings`, which fires on *every* write to that
 * option — and the sanitizer deliberately refuses to let a form post change
 * schema state. Keeping the version separate means an upgrade can always write
 * it, instead of the upgrade only working because `plugins_loaded` happens to
 * run before `admin_init`.
 */
final class Voris_Lifecycle {

	/**
	 * Where the stored schema version lives.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'voris_db_version';

	/**
	 * Schema version this code expects.
	 *
	 * Bumped to v2 (ACH002) to backfill the OAuth grant's `attested` flag onto
	 * connections stored before that flag existed — see `maybe_upgrade()`.
	 *
	 * @var int
	 */
	const DB_VERSION = 2;

	/**
	 * Floors declared in the plugin header, enforced here too (C5).
	 *
	 * @var string
	 */
	const MIN_WP = '6.6';

	/**
	 * Minimum PHP, matching the header.
	 *
	 * @var string
	 */
	const MIN_PHP = '8.1';

	/**
	 * Whether this site can run the plugin.
	 *
	 * @param string|null $wp_version  WordPress version, or null for this site's.
	 * @param string|null $php_version PHP version, or null for this runtime's.
	 * @return bool
	 */
	public static function requirements_met( ?string $wp_version = null, ?string $php_version = null ): bool {
		$wp_version  = $wp_version ?? get_bloginfo( 'version' );
		$php_version = $php_version ?? PHP_VERSION;

		return version_compare( $wp_version, self::MIN_WP, '>=' )
			&& version_compare( $php_version, self::MIN_PHP, '>=' );
	}

	/**
	 * Why activation was refused.
	 *
	 * @return string
	 */
	public static function requirements_message(): string {
		return sprintf(
			/* translators: 1: minimum WordPress version, 2: minimum PHP version */
			__( 'Voris requires WordPress %1$s or newer and PHP %2$s or newer. Nothing has been changed on your site.', 'voris' ),
			self::MIN_WP,
			self::MIN_PHP
		);
	}

	/**
	 * Set the plugin up. Safe to run more than once.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! self::requirements_met() ) {
			deactivate_plugins( plugin_basename( VORIS_PLUGIN_FILE ) );

			wp_die(
				esc_html( self::requirements_message() ),
				'',
				array( 'back_link' => true )
			);
		}

		Voris_Settings::install();

		add_option( self::DB_VERSION_OPTION, self::DB_VERSION, '', 'yes' );

		/*
		 * Deactivation cleared the drain cron but kept the retry queue (C4). If
		 * events were awaiting redelivery, reschedule the drain now — otherwise
		 * a deactivate→reactivate strands them: nothing is scheduled, and the
		 * overdue-admin fallback only adopts an existing overdue schedule
		 * (VOR-301).
		 */
		Voris_Dispatcher::reconcile_schedule();
	}

	/**
	 * Stop all scheduled work. Deletes nothing (C4).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Voris_Dispatcher::CRON_HOOK );
		wp_clear_scheduled_hook( Voris_Plan::REFRESH_HOOK );
	}

	/**
	 * Remove everything the plugin stored, unless the owner opted out (C4).
	 *
	 * Called from `uninstall.php`, where the plugin itself is not loaded and
	 * WooCommerce may be inactive — so order meta is removed with SQL rather
	 * than CRUD, which would not be available.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		// The stored OAuth credential is ALWAYS removed on uninstall — even when
		// the owner kept settings (C12/AC18). A token must never outlive the
		// plugin, and its short-lived companions go with it.
		delete_option( Voris_Connection::OPTION );
		delete_option( Voris_Oauth::PENDING_OPTION );
		delete_transient( Voris_Oauth::REFRESH_LOCK );
		wp_clear_scheduled_hook( Voris_Plan::REFRESH_HOOK );

		if ( Voris_Settings::keeps_data_on_uninstall() ) {
			return;
		}

		delete_option( Voris_Settings::OPTION );
		delete_option( Voris_Queue::QUEUE_OPTION );
		delete_option( Voris_Queue::LOG_OPTION );
		delete_option( Voris_Plan::CACHE_OPTION );
		delete_option( Voris_Nudge::DISMISS_OPTION );
		delete_option( self::DB_VERSION_OPTION );

		delete_transient( Voris_Dispatcher::LOCK_TRANSIENT );
		delete_transient( Voris_Admin_Page::CACHE_NOTICE_TRANSIENT );

		wp_clear_scheduled_hook( Voris_Dispatcher::CRON_HOOK );

		self::delete_order_meta();
	}

	/**
	 * Drop the `_voris_*` markers from orders, wherever WooCommerce keeps them.
	 *
	 * @return void
	 */
	private static function delete_order_meta(): void {
		global $wpdb;

		/*
		 * The exact keys, not a `_voris_%` prefix match: a prefix would also take
		 * any future Voris-branded plugin's meta, or a shop's own field that
		 * happened to share the namespace. A new `_voris_*` order meta key must be
		 * added here by hand (ADR-MG001, ADR-ACH006).
		 */
		$keys         = array(
			Voris_WooCommerce::PURCHASE_PENDING_META,
			Voris_WooCommerce::PURCHASE_EVENT_META,
			Voris_WooCommerce::PURCHASE_SITE_META,
			Voris_WooCommerce::REFUND_EVENT_META,
		);
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholder string is built from a fixed key list, so PHPCS cannot see the %s markers it contains; every value is still prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", $keys ) );

		/*
		 * High-Performance Order Storage keeps order meta in its own table,
		 * which only exists on stores that enabled it.
		 */
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for an optional WooCommerce table.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) );

		/*
		 * Compared case-insensitively: MySQL lower-cases table names on
		 * platforms where `lower_case_table_names` is on, so a site using a
		 * mixed-case `$table_prefix` would otherwise fail this check and
		 * silently leave its order meta behind.
		 */
		if ( null === $found || 0 !== strcasecmp( (string) $found, $orders_meta ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name comes from $wpdb->prefix and the placeholder string from a fixed key list, neither from input; every value is prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$found}` WHERE meta_key IN ({$placeholders})", $keys ) );
	}

	/**
	 * Run any migrations this version needs. Hooked to `plugins_loaded`.
	 *
	 * Nothing here touches the schema on a normal request — the version check
	 * is a single autoloaded read, so there is no per-request schema work
	 * (constitution §17).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $stored >= self::DB_VERSION ) {
			return;
		}

		/*
		 * Version 1 is the first schema, so there is nothing to migrate from.
		 * Later versions add their steps here, guarded by the stored version.
		 * Installing settings covers an install that predates versioning.
		 */
		Voris_Settings::install();

		/*
		 * v2 (ACH002): a grant connected before the `attested` flag existed has a
		 * bound site but no flag, which the hardened connect screen would misread as
		 * "still connecting" and show a spurious "Resume connection". Backfill it so
		 * an already-connected site stays fully connected across the upgrade.
		 */
		if ( $stored < 2 ) {
			Voris_Connection::migrate_legacy_attestation();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		// A queue can survive an upgrade just as it survives a reactivate; make
		// sure its drain is scheduled (VOR-301). Inside the upgrade branch only,
		// so a current install still does no per-request work (§17).
		Voris_Dispatcher::reconcile_schedule();
	}
}
