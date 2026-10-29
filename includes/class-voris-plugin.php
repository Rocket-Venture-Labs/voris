<?php
/**
 * Plugin container.
 *
 * @package Voris
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's services together.
 *
 * Each service registers its own hooks through a `register()` method; this
 * class constructs them and owns the few hooks that belong to no single
 * service. It is not the only registrar — `Voris_Dispatcher` hooks its own
 * flush, and the activation, deactivation and WooCommerce-compatibility hooks
 * are registered at file scope because they must be.
 */
final class Voris_Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Voris_Plugin|null
	 */
	private static ?Voris_Plugin $instance = null;

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Delivery service, shared so one `shutdown` flush covers the request.
	 *
	 * @var Voris_Dispatcher|null
	 */
	private ?Voris_Dispatcher $dispatcher = null;

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Voris_Plugin
	 */
	public static function instance(): Voris_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use instance().
	 */
	private function __construct() {}

	/**
	 * Register the plugin's hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;
		$this->dispatcher  = new Voris_Dispatcher();

		Voris_Lifecycle::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Before anything can write the option, and regardless of context.
		Voris_Settings::register();

		/*
		 * Last priority so it overrides any site-wide snippet that disables
		 * certificate verification. Scoped to the ingest endpoint inside the
		 * callback.
		 */
		add_filter( 'http_request_args', array( 'Voris_Transport', 'force_ssl_verification' ), PHP_INT_MAX, 2 );

		/*
		 * `https_ssl_verify` is applied after the sslverify translation, so the
		 * filter above cannot protect against it on its own.
		 */
		add_filter( 'https_ssl_verify', array( 'Voris_Transport', 'force_ssl_certificate' ), PHP_INT_MAX, 2 );
		add_filter( 'https_local_ssl_verify', array( 'Voris_Transport', 'force_ssl_certificate' ), PHP_INT_MAX, 2 );

		add_action( Voris_Dispatcher::CRON_HOOK, array( $this->dispatcher, 'drain' ) );
		add_action( 'admin_init', array( $this->dispatcher, 'maybe_run_overdue_drain' ) );

		( new Voris_Consent() )->register();
		( new Voris_Tag() )->register();
		( new Voris_Connect() )->register();
		( new Voris_Plan() )->register();
		( new Voris_Nudge() )->register();

		if ( is_admin() ) {
			( new Voris_Admin_Page() )->register();
		}

		( new Voris_WooCommerce( $this->dispatcher ) )->register();
	}

	/**
	 * Whether the plugin has been bootstrapped.
	 *
	 * @return bool
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}

	/**
	 * Load shipped translations. Hooked to `init`.
	 *
	 * Called on `init` rather than earlier: WordPress warns about loading a
	 * text domain before then.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'voris-privacy-first-analytics', false, dirname( plugin_basename( VORIS_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * The shared delivery service.
	 *
	 * One instance per request, so every event raised joins the same
	 * `shutdown` flush.
	 *
	 * @return Voris_Dispatcher|null Null before init() has run.
	 */
	public function dispatcher(): ?Voris_Dispatcher {
		return $this->dispatcher;
	}
}
