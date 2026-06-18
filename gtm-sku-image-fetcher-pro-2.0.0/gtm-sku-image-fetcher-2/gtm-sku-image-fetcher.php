<?php
/**
 * Plugin Name: GTM SKU Image Fetcher Pro (WooCommerce)
 * Description: Αυτόματα βρίσκει και προσθέτει featured image + gallery (3+ φωτογραφίες) σε WooCommerce προϊόντα χρησιμοποιώντας Google Custom Search API. Τρέχει στο background — φύγε από τη σελίδα και επέστρεψε να δεις την πρόοδο.
 * Version: 2.0.0
 * Author: KOSTSI / GTMobiles.gr
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: gtm-sku-image-fetcher
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GTMIF_VERSION',     '2.0.0' );
define( 'GTMIF_PLUGIN_FILE', __FILE__ );
define( 'GTMIF_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GTMIF_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Activation / uninstall hooks — load only what's needed
function gtmif_activate() {
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-logger.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-settings.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-stats.php';
	\GTMIF\Logger::install();
	\GTMIF\Stats::install();
}
register_activation_hook( __FILE__, 'gtmif_activate' );

function gtmif_uninstall() {
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-logger.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-settings.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-stats.php';
	\GTMIF\Logger::uninstall();
	\GTMIF\Stats::uninstall();
}
register_uninstall_hook( __FILE__, 'gtmif_uninstall' );

// Bootstrap on plugins_loaded so WooCommerce is available
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-logger.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-settings.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-stats.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-providers.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-runner.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-logs-table.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-admin.php';
	require_once GTMIF_PLUGIN_DIR . 'includes/class-gtmif-plugin.php';

	\GTMIF\Plugin::instance();
} );
