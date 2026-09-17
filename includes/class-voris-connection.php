<?php
/**
 * The connected OAuth grant (Phase 2).
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the OAuth 2.1 device-grant credential the plugin holds after connecting
 * to Voris, and enforces its lifecycle rules (ACH001 C12 / AC5).
 *
 * The credential lives in a single **non-autoloaded** option, never on the front
 * end and never surfaced to non-admins. Refresh tokens **rotate on use**
 * (`rotate()`): each refresh returns a new pair that atomically overwrites the
 * old one — a stale refresh token is treated by Voris as theft and revokes the
 * whole grant family, so the plugin must never keep or retry a superseded token
 * (`partner-oauth-api.md` §token rotation).
 *
 * This class is the credential store only. Writing the bound site id into
 * `voris_settings` (the single source the tag + WooCommerce path read) is the
 * connect flow's job (Phase 2 chunk 2), not the store's.
 */
final class Voris_Connection {

	/**
	 * The option name. Non-autoloaded (it is a secret, not read on every request).
	 *
	 * @var string
	 */
	const OPTION = 'voris_connection';

	/**
	 * The one scope the grant carries (least privilege).
	 *
	 * @var string
	 */
	const SCOPE = 'analytics:read';

	/**
	 * The stored grant, or null when not connected.
	 *
	 * @return array|null
	 */
	public static function get(): ?array {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Whether a usable grant is stored.
	 *
	 * "Connected" means both tokens are present: the access token authorizes reads
	 * now, and the refresh token renews it. A grant missing either is not usable
	 * and reads as disconnected.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$grant = self::get();

		return null !== $grant
			&& ! empty( $grant['access_token'] )
			&& ! empty( $grant['refresh_token'] );
	}

	/**
	 * Persist a freshly minted grant, binding it to a site.
	 *
	 * Non-autoloaded by construction: the option is only ever written here (and by
	 * `rotate()`), and both pass `false` for autoload so a secret never joins the
	 * autoloaded bundle read on every request.
	 *
	 * @param string $access_token  The bearer access token.
	 * @param string $refresh_token The rotating refresh token.
	 * @param int    $expires_in    Access-token lifetime in seconds.
	 * @param string $bound_site_id The site the grant is bound to (UUID).
	 * @param string $bound_domain  The bound site's domain, for second-site checks.
	 * @return void
	 */
	public static function save( string $access_token, string $refresh_token, int $expires_in, string $bound_site_id = '', string $bound_domain = '' ): void {
		self::write(
			array(
				'access_token'      => $access_token,
				'refresh_token'     => $refresh_token,
				'access_expires_at' => time() + max( 0, $expires_in ),
				'bound_site_id'     => $bound_site_id,
				'bound_domain'      => $bound_domain,
				'scope'             => self::SCOPE,
				'attested'          => false,
			)
		);
	}

	/**
	 * Bind the grant to a site after learning it from the onboarding read.
	 *
	 * The mint returns tokens but not the bound site; the connect flow reads
	 * onboarding for `site.{id,domain}` and records it here. A no-op without a
	 * stored grant.
	 *
	 * @param string $bound_site_id The bound site (UUID).
	 * @param string $bound_domain  The bound site's domain.
	 * @return void
	 */
	public static function bind_site( string $bound_site_id, string $bound_domain ): void {
		$grant = self::get();

		if ( null === $grant ) {
			return;
		}

		$grant['bound_site_id'] = $bound_site_id;
		$grant['bound_domain']  = $bound_domain;

		self::write( $grant );
	}

	/**
	 * Replace both tokens after a refresh — atomically, preserving the binding.
	 *
	 * The refresh response ALWAYS returns a new refresh token; the old one is now
	 * poison (reusing it revokes the family). Overwrite both in one write so a
	 * crash can never leave the old refresh token paired with a new access token.
	 * The site binding and scope are untouched — a refresh never re-binds.
	 *
	 * @param string $access_token  The new access token.
	 * @param string $refresh_token The new (rotated) refresh token.
	 * @param int    $expires_in    New access-token lifetime in seconds.
	 * @return void
	 */
	public static function rotate( string $access_token, string $refresh_token, int $expires_in ): void {
		$grant = self::get();

		if ( null === $grant ) {
			return; // Nothing to rotate; a rotate without a prior grant is a no-op.
		}

		$grant['access_token']      = $access_token;
		$grant['refresh_token']     = $refresh_token;
		$grant['access_expires_at'] = time() + max( 0, $expires_in );

		self::write( $grant );
	}

	/**
	 * The current access token, or null.
	 *
	 * @return string|null
	 */
	public static function access_token(): ?string {
		$grant = self::get();

		return ( null !== $grant && ! empty( $grant['access_token'] ) ) ? (string) $grant['access_token'] : null;
	}

	/**
	 * The current refresh token, or null.
	 *
	 * @return string|null
	 */
	public static function refresh_token(): ?string {
		$grant = self::get();

		return ( null !== $grant && ! empty( $grant['refresh_token'] ) ) ? (string) $grant['refresh_token'] : null;
	}

	/**
	 * The bound site id (UUID), or null.
	 *
	 * @return string|null
	 */
	public static function bound_site_id(): ?string {
		$grant = self::get();

		return ( null !== $grant && ! empty( $grant['bound_site_id'] ) ) ? (string) $grant['bound_site_id'] : null;
	}

	/**
	 * The bound site's domain, or null.
	 *
	 * @return string|null
	 */
	public static function bound_domain(): ?string {
		$grant = self::get();

		return ( null !== $grant && ! empty( $grant['bound_domain'] ) ) ? (string) $grant['bound_domain'] : null;
	}

	/**
	 * Record that the install has been attested to Voris (VOR-298 / ADR-ACH004).
	 *
	 * The connect flow attests once, idempotently, after binding the site. Persisting
	 * the outcome is what lets a later heartbeat retry a *failed* attestation rather
	 * than skip it forever. A no-op without a stored grant.
	 *
	 * @return void
	 */
	public static function mark_attested(): void {
		$grant = self::get();

		if ( null === $grant ) {
			return;
		}

		$grant['attested'] = true;

		self::write( $grant );
	}

	/**
	 * Whether the install has been attested for the stored grant.
	 *
	 * @return bool
	 */
	public static function is_attested(): bool {
		$grant = self::get();

		return null !== $grant && ! empty( $grant['attested'] );
	}

	/**
	 * Whether the connection is fully established: a usable grant, bound to a site,
	 * with the install attested. Anything short of this is still *connecting* and the
	 * connect screen must keep working toward completion (VOR-298).
	 *
	 * @return bool
	 */
	public static function is_fully_connected(): bool {
		return self::is_connected() && null !== self::bound_site_id() && self::is_attested();
	}

	/**
	 * Backfill the attestation flag for a grant stored before the flag existed.
	 *
	 * A grant connected under an earlier version has a bound site but no `attested`
	 * key, which `is_fully_connected()` would read as still-connecting — showing a
	 * spurious "Resume connection" and auto-resuming the heartbeat on an
	 * already-connected site. A pre-existing *bound* grant was, by definition,
	 * established and attested under the old flow, so mark it attested. An unbound
	 * keyless grant is left alone — the bind path will (re)attest it, which is the
	 * intended recovery. Run once, from the versioned upgrade path only (ACH002).
	 *
	 * @return void
	 */
	public static function migrate_legacy_attestation(): void {
		$grant = self::get();

		// Only a grant that predates the flag (no key) AND is already bound.
		if ( null === $grant || array_key_exists( 'attested', $grant ) || empty( $grant['bound_site_id'] ) ) {
			return;
		}

		$grant['attested'] = true;

		self::write( $grant );
	}

	/**
	 * Whether the access token is expired (or within `$skew` seconds of it).
	 *
	 * Used to refresh proactively before a read rather than eating a 401. A grant
	 * with no expiry recorded is treated as expired (refresh to be safe).
	 *
	 * @param int $skew Seconds of headroom to treat as already-expired.
	 * @return bool
	 */
	public static function is_access_expired( int $skew = 0 ): bool {
		$grant = self::get();

		if ( null === $grant || empty( $grant['access_expires_at'] ) ) {
			return true;
		}

		return time() + max( 0, $skew ) >= (int) $grant['access_expires_at'];
	}

	/**
	 * Remove the credential entirely.
	 *
	 * Called on disconnect and on uninstall — the credential is dropped even when
	 * the owner chose to keep settings (a stored token has no business surviving
	 * an uninstall).
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Write the option non-autoloaded, whether or not it already exists.
	 *
	 * `update_option` keeps the existing autoload flag on an existing row, so a
	 * first write must go through `add_option( …, false )` to guarantee the secret
	 * is never autoloaded.
	 *
	 * @param array $grant The full grant array.
	 * @return void
	 */
	private static function write( array $grant ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $grant, '', false );
			return;
		}

		update_option( self::OPTION, $grant, false );
	}
}
