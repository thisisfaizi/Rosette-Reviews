<?php
/**
 * Deactivation handler.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on deactivation. Non-destructive: never drops data here (uninstall does,
 * and only if the user opted in).
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Cancel pending jobs for every Action Scheduler hook the plugin owns
		// (group 'ndv-reviews'). Pass the hook alone: with a group, Action
		// Scheduler matches the (empty) args exactly and cancels nothing. Jobs
		// left queued would fail anyway once their callback is gone; whatever
		// they were for is re-queued after reactivation.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( Uninstall::registry()['scheduler_hooks'] as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}

		// The endpoint's rule goes with this flush; the flag must go too (RR-15).
		delete_option( Account\MyAccountReviews::FLAG );
		flush_rewrite_rules();
	}
}
