<?php
/**
 * Activation handler.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation: seeds default settings, then creates or upgrades
 * the tables through the same locked routine every request uses (RR-00 F3).
 */
class Activator {

	/**
	 * Activate the plugin.
	 *
	 * The version is never written here: maybe_upgrade() stamps a fresh
	 * install (and seeds the default criteria), runs pending steps for an
	 * older one, and leaves a newer one alone. WordPress runs this hook before
	 * it adds the plugin to `active_plugins`, so no other request is booting
	 * the plugin during a fresh install.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( NDVR_OPTION_SETTINGS, false ) ) {
			add_option( NDVR_OPTION_SETTINGS, Settings::defaults() );
		}

		Installer::maybe_upgrade( true );

		// The hourly recovery job (RR-09): re-queues reminders whose jobs the
		// deactivation removed, and resets crashed sends.
		Requests\Scheduler::ensure_recover_scheduled( true );

		set_transient( 'ndv_reviews_activated', 1, 60 );

		// This flush runs before the plugin boots, without the My Account
		// endpoint (RR-15): dropping the flag makes the first booted request
		// flush again with it.
		delete_option( Account\MyAccountReviews::FLAG );
		flush_rewrite_rules();
	}
}
