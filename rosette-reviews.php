<?php
/**
 * Plugin Name:       Rosette Reviews
 * Plugin URI:        https://nowdigiverse.com/rosette-reviews
 * Description:        Reliable, self-hosted reviews for WooCommerce — multi-criteria ratings, photo reviews, working reminders, and rich schema. No account or external service required.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Nowdigiverse
 * Author URI:        https://nowdigiverse.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rosette-reviews
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * Rosette Reviews is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later version.
 *
 * @package NdvReviews
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Central naming / prefixing constants (single source of truth — build-plan §13).
 * Renaming the brand = change these (plus slug folder + readme).
 * ---------------------------------------------------------------------------
 */
define( 'NDVR_VERSION', '1.0.0' );
define( 'NDVR_DB_VERSION', '8' );
// Public API level for add-ons (RR-00 F3b). Raised by each release that adds
// API an add-on may use; add-ons check it instead of method_exists()/versions.
define( 'NDVR_API', 11 );
define( 'NDVR_SLUG', 'rosette-reviews' );
define( 'NDVR_NAME', 'Rosette Reviews' );
define( 'NDVR_TEXTDOMAIN', 'rosette-reviews' );
define( 'NDVR_TABLE_PREFIX', 'ndvr_' );
define( 'NDVR_OPTION_SETTINGS', 'ndv_reviews_settings' );
define( 'NDVR_OPTION_DB_VERSION', 'ndv_reviews_db_version' );
define( 'NDVR_FILE', __FILE__ );
define( 'NDVR_DIR', plugin_dir_path( __FILE__ ) );
define( 'NDVR_URL', plugin_dir_url( __FILE__ ) );
define( 'NDVR_BASENAME', plugin_basename( __FILE__ ) );

/*
 * ---------------------------------------------------------------------------
 * PSR-4 autoloader.
 * Mirrors the "NdvReviews\\" => "includes/" map in composer.json so the plugin
 * runs without `composer install`. If a Composer autoload is present, it wins.
 * ---------------------------------------------------------------------------
 */
if ( file_exists( NDVR_DIR . 'vendor/autoload.php' ) ) {
	require NDVR_DIR . 'vendor/autoload.php';
} else {
	require NDVR_DIR . 'includes/Support/Autoloader.php';
	NdvReviews\Support\Autoloader::register( 'NdvReviews\\', NDVR_DIR . 'includes/' );
}

/*
 * ---------------------------------------------------------------------------
 * Activation / Deactivation — registered at top level (never inside a hook).
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( 'NdvReviews\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NdvReviews\\Deactivator', 'deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * WooCommerce feature compatibility (HPOS + Cart/Checkout Blocks).
 * Must be declared on before_woocommerce_init, hooked at top level.
 * ---------------------------------------------------------------------------
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NDVR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NDVR_FILE, true );
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Boot the plugin after all plugins are loaded.
 * Degrades gracefully (admin notice, no fatal) if WooCommerce is absent.
 * ---------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( 'NdvReviews\\Plugin', 'render_woocommerce_missing_notice' ) );
			return;
		}

		NdvReviews\Plugin::instance()->boot();
	}
);
