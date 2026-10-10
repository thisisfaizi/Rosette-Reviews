<?php
/**
 * Reset the QA site after a suite died mid-run (memory, timeout), before
 * trusting the next failures. Removes the state the suites set and normally
 * restore in their teardown.
 *
 *     php boot.php run .agents/qa/reset-qa.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Only for the throwaway QA site: it deletes options and queued jobs.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery -- QA script, never shipped.

defined( 'ABSPATH' ) || exit;

global $wpdb;
$log = array();

// Pro settings and the "coupon wording" flag that rr-01/rr-02 set.
foreach ( array( 'ndv_reviews_pro_settings', 'ndv_reviews_pro_incentive_used' ) as $option ) {
	if ( false !== get_option( $option, false ) ) {
		delete_option( $option );
		$log[] = "deleted option {$option}";
	}
}

// Free settings the suites switch on (back to their defaults).
$settings = get_option( NDVR_OPTION_SETTINGS );
if ( is_array( $settings ) ) {
	$defaults = array(
		'transparency_enabled' => null,
		'reports_threshold'    => 0,
		'min_review_length'    => 0,
		'captcha_provider'     => null,
		'edit_window_days'     => 30,
		'myaccount_enabled'    => true,
	);
	foreach ( $defaults as $key => $value ) {
		if ( array_key_exists( $key, $settings ) && $settings[ $key ] !== $value ) {
			if ( null === $value ) {
				unset( $settings[ $key ] );
			} else {
				$settings[ $key ] = $value;
			}
			$log[] = "reset setting {$key}";
		}
	}
	update_option( NDVR_OPTION_SETTINGS, $settings );
}

// Queued test webhook deliveries (they crowd rr-01 AC7's first page).
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$ids = as_get_scheduled_actions(
		array(
			'hook'     => 'ndvr_webhook_deliver',
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		),
		'ids'
	);
	foreach ( $ids as $id ) {
		\ActionScheduler::store()->cancel_action( $id );
	}
	$log[] = sprintf( 'cancelled %d pending webhook deliveries', count( $ids ) );
}

// Rate-limit buckets and email caps.
$n     = (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ndvr\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ndvr\\_rl\\_%' OR option_name LIKE '\\_transient%ndvr\\_report\\_mails' OR option_name LIKE '\\_transient%ndvr\\_edit\\_mail\\_%'" );
$log[] = "deleted {$n} rate-limit / email-cap transient rows";
wp_cache_flush();

echo implode( "\n", $log ) . "\nQA site reset.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
