<?php
/**
 * Uninstall.
 *
 * WordPress runs this with the plugin itself unloaded, so everything the
 * removal needs is required explicitly. WooCommerce may be inactive too.
 *
 * @package Voris
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$voris_dir = plugin_dir_path( __FILE__ );

require_once $voris_dir . 'includes/class-voris-settings.php';
require_once $voris_dir . 'includes/class-voris-connection.php';
require_once $voris_dir . 'includes/class-voris-oauth.php';
require_once $voris_dir . 'includes/class-voris-plan.php';
require_once $voris_dir . 'includes/class-voris-nudge.php';
require_once $voris_dir . 'includes/class-voris-queue.php';
require_once $voris_dir . 'includes/class-voris-dispatcher.php';
require_once $voris_dir . 'admin/class-voris-admin-page.php';
require_once $voris_dir . 'integrations/class-voris-woocommerce.php';
require_once $voris_dir . 'includes/class-voris-lifecycle.php';

Voris_Lifecycle::uninstall();
