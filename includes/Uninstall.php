<?php
/**
 * Uninstall registry and runner (RR-00 F2).
 *
 * WordPress runs uninstall.php on its own: the main plugin file, its constants
 * and the autoloader are not loaded. This file therefore stays dependency-free
 * at load time (only the ABSPATH guard), uses no class but Installer, and its
 * registry is plain literals with no filters (no listener is loaded anyway).
 *
 * @package NdvReviews
 */

namespace NdvReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin stores outside its own tables, and the routine that
 * removes it when the merchant opted in to data removal.
 */
final class Uninstall {

	/**
	 * Stored data by kind. Every feature that stores something appends here.
	 *
	 * - options:            option names, exact.
	 * - option_prefixes:    per-id option names, swept with LIKE 'prefix%'.
	 * - transients:         transient names, exact.
	 * - transient_prefixes: per-key transients, swept with LIKE.
	 * - comment_meta:       keys removed from the reviews that survive uninstall
	 *                       (WooCommerce native reviews keep their own `rating`).
	 * - comment_meta_prefixes: per-id comment meta keys, swept with LIKE 'prefix%'.
	 * - post_meta:          keys on reviewable posts (aggregates of non-products).
	 * - order_meta:         keys on orders, HPOS table and legacy postmeta.
	 * - user_meta:          keys on users.
	 * - scheduler_hooks:    Action Scheduler hooks to cancel.
	 *
	 * @return array<string,string[]>
	 */
	public static function registry() {
		return array(
			'options'               => array(
				'ndv_reviews_settings',
				'ndv_reviews_consent_enabled_at',
				'ndv_reviews_ml_strings_hash',
				'ndv_reviews_db_version',
				'ndv_reviews_unsubscribed',
				'ndv_reviews_rewrite_flushed',
				'ndv_reviews_upgrade_lock',
				'ndv_reviews_upgrade_error',
				'ndv_reviews_media_resweep_cursor',
				'ndv_reviews_backfill_purchase_done', // RR-23.
				'ndv_reviews_backfill_purchase_cursor',
			),
			'option_prefixes'       => array(
				'ndv_reviews_invite_lock_', // RR-22.
			),
			'transients'            => array(
				'ndv_reviews_activated',
				'ndvr_upgrade_backoff',
				'ndvr_recover_checked',
				'ndvr_tp_import',
				'ndvr_risk_counts', // RR-21.
			),
			'transient_prefixes'    => array(
				'ndvr_rl_',
				'ndvr_request_stats_',
				'ndvr_order_action_notice_',
				'ndvr_edit_saved_', // RR-22.
				'ndvr_recovery_notice_',
			),
			'comment_meta'          => array(
				'_ndvr_risk',
				'_ndvr_risk_reasons',
				'_ndvr_risk_held',
				'_ndvr_token_id',
				'_ndvr_recovery_status',
				'_ndvr_recovery_note',
				'_ndvr_recovery_resolved_at',
				'_ndvr_recovery_invited_at',
				'_ndvr_purchased_at',
				'_ndvr_overall_rating',
				'_ndvr_source',
				'_ndvr_verified',
				'_ndvr_title',
				'_ndvr_recommend',
				'_ndvr_helpful_up',
				'_ndvr_tag',
				'_ndvr_order_id',
				'_ndvr_consent',
				'_ndvr_import_hash',
				'_ndvr_pooled_from',
				'_ndvr_incentive_offered',
				'_ndvr_answers',
				'_ndvr_report_count',
				'_ndvr_report_held',
				'_ndvr_report_held_n',
				'_ndvr_pending_edit',
				'_ndvr_edited_at',
				'_ndvr_edit_discarded',
				'_ndvr_published_once',
			),
			'comment_meta_prefixes' => array(
				'_ndvr_ans_',
			),
			'post_meta'             => array(
				'_ndvr_average_rating',
				'_ndvr_review_count',
				'_ndvr_rating_count',
			),
			'order_meta'            => array(
				'_ndvr_review_consent',
				'_ndvr_review_consent_at',
				'_ndvr_review_consent_text',
				'_ndvr_review_consent_via',
				'_wc_other/ndv-reviews/review-email-consent',
			),
			'user_meta'             => array(
				'ndvr_setup_dismissed',
				'ndvr_health_notice_dismissed',
				'ndvr_transparency_notice_dismissed',
				'_wc_other/ndv-reviews/review-email-consent',
			),
			'scheduler_hooks'       => array(
				'ndvr_review_field_reindex',
				'ndvr_send_request',
				'ndvr_requests_recover',
				'ndvr_media_cleanup',
				'ndvr_backfill_purchase_dates', // RR-23.
			),
		);
	}

	/**
	 * Remove the plugin's data. Order matters: the review-id query reads
	 * `_ndvr_*` meta and the review_media table, so it runs before anything is
	 * deleted, and the comment-meta sweep runs after the reviews are gone.
	 *
	 * @param bool $dry_run When true, nothing is changed; the log lists what would be.
	 * @return string[] Log lines, one per action.
	 */
	public static function run( $dry_run = false ) {
		global $wpdb;

		$log      = array();
		$registry = self::registry();
		wp_cache_delete( 'review_fields', 'ndvr' );

		// 1. Capture the reviews this plugin created, and their uploads.
		$review_ids     = self::review_ids();
		$attachment_ids = self::attachment_ids( $review_ids );
		$log[]          = sprintf( 'reviews: %d', count( $review_ids ) );
		$log[]          = sprintf( 'attachments: %d', count( $attachment_ids ) );

		// 2. Delete them. wp_delete_comment( force ) also removes their meta.
		// The stale WooCommerce product aggregates are not recalculated: the
		// merchant chose to remove the plugin and its review data entirely.
		if ( ! $dry_run ) {
			foreach ( $review_ids as $review_id ) {
				wp_delete_comment( $review_id, true );
			}
			foreach ( $attachment_ids as $attachment_id ) {
				if ( 'attachment' === get_post_type( $attachment_id ) ) {
					wp_delete_attachment( $attachment_id, true );
				}
			}
		}

		// 3. Drop the tables.
		foreach ( Installer::table_names() as $table ) {
			$log[] = 'table: ' . $table;
			if ( ! $dry_run ) {
				// Table name is $wpdb->prefix + a hardcoded list; not user input.
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}

		// 4. Sweep the registry.
		foreach ( $registry['options'] as $option ) {
			$log[] = 'option: ' . $option;
			if ( ! $dry_run ) {
				delete_option( $option );
			}
		}
		foreach ( $registry['option_prefixes'] as $prefix ) {
			$log[] = 'option prefix: ' . $prefix;
			if ( ! $dry_run ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		foreach ( $registry['transients'] as $transient ) {
			$log[] = 'transient: ' . $transient;
			if ( ! $dry_run ) {
				delete_transient( $transient );
			}
		}
		foreach ( $registry['transient_prefixes'] as $prefix ) {
			$log[] = 'transient prefix: ' . $prefix;
			if ( ! $dry_run ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						$wpdb->esc_like( '_transient_' . $prefix ) . '%',
						$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
					)
				);
			}
		}
		foreach ( $registry['comment_meta'] as $key ) {
			$log[] = 'comment meta: ' . $key;
			if ( ! $dry_run ) {
				delete_metadata( 'comment', 0, $key, '', true );
			}
		}
		foreach ( $registry['comment_meta_prefixes'] as $prefix ) {
			$log[] = 'comment meta prefix: ' . $prefix;
			if ( ! $dry_run ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		foreach ( $registry['post_meta'] as $key ) {
			$log[] = 'post meta: ' . $key;
			if ( ! $dry_run ) {
				delete_post_meta_by_key( $key );
			}
		}
		if ( $registry['order_meta'] ) {
			$log[] = 'order meta: ' . implode( ', ', $registry['order_meta'] );
			if ( ! $dry_run ) {
				self::delete_order_meta( $registry['order_meta'] );
			}
		}
		foreach ( $registry['user_meta'] as $key ) {
			$log[] = 'user meta: ' . $key;
			if ( ! $dry_run ) {
				delete_metadata( 'user', 0, $key, '', true );
			}
		}
		if ( $registry['scheduler_hooks'] ) {
			$log[] = 'scheduler hooks: ' . implode( ', ', $registry['scheduler_hooks'] );
			if ( ! $dry_run ) {
				self::cancel_jobs( $registry['scheduler_hooks'] );
			}
		}

		return $log;
	}

	/**
	 * Reviews this plugin created.
	 *
	 * `_ndvr_overall_rating`/`_ndvr_source` alone are not enough: RatingCache and
	 * the WooCommerce backfill also write them onto the store's native reviews
	 * (source 'import', or 'erased' after a GDPR erasure), and those must
	 * survive. ReviewRepository::create() is the only writer of
	 * `_ndvr_recommend`; reviews created outside it never carry
	 * 'import'/'erased'.
	 *
	 * @return int[]
	 */
	private static function review_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT DISTINCT comment_id FROM {$wpdb->commentmeta}
			WHERE meta_key = '_ndvr_recommend'
			OR ( meta_key = '_ndvr_source' AND meta_value NOT IN ( 'import', 'erased' ) )"
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Photos uploaded with the given reviews (read before the media table is
	 * dropped). Reviews a merchant wrote in the admin reference existing
	 * media-library items (`_ndvr_admin_created`), so those are left alone, as
	 * is any attachment another surviving review still uses.
	 *
	 * @param int[] $review_ids Reviews being deleted.
	 * @return int[]
	 */
	private static function attachment_ids( array $review_ids ) {
		global $wpdb;

		if ( empty( $review_ids ) ) {
			return array();
		}

		$table = $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_media';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$rows = $wpdb->get_results( "SELECT comment_id, attachment_id FROM `{$table}` WHERE attachment_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix + literal.

		$deleting = array_flip( $review_ids );
		$drop     = array();
		$keep     = array();
		foreach ( (array) $rows as $row ) {
			$comment_id    = (int) $row->comment_id;
			$attachment_id = (int) $row->attachment_id;
			if ( ! isset( $deleting[ $comment_id ] ) || get_comment_meta( $comment_id, '_ndvr_admin_created', true ) ) {
				$keep[ $attachment_id ] = true;
			} else {
				$drop[ $attachment_id ] = true;
			}
		}

		return array_keys( array_diff_key( $drop, $keep ) );
	}

	/**
	 * Delete order meta without WooCommerce APIs (it may not be loaded).
	 *
	 * @param string[] $keys Meta keys.
	 * @return void
	 */
	private static function delete_order_meta( array $keys ) {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		$hpos = $wpdb->prefix . 'wc_orders_meta';
		if ( self::table_exists( $hpos ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from prefix + literal; one %s per key.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$hpos}` WHERE meta_key IN ( {$placeholders} )", $keys ) );
		}

		// Legacy (posts) order storage, and the HPOS sync copy.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per key.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( {$placeholders} )", $keys ) );
	}

	/**
	 * Cancel pending Action Scheduler jobs, with or without WooCommerce loaded.
	 *
	 * Hook only, never a group: with a group, Action Scheduler matches the
	 * empty args exactly and cancels nothing.
	 *
	 * @param string[] $hooks Hooks.
	 * @return void
	 */
	private static function cancel_jobs( array $hooks ) {
		global $wpdb;

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook );
			}
			return;
		}

		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $hooks ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from prefix + literal; one %s per hook.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = 'canceled' WHERE status = 'pending' AND hook IN ( {$placeholders} )", $hooks ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
